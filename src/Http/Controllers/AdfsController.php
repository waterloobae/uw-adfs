<?php

namespace WaterlooBae\UwAdfs\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use WaterlooBae\UwAdfs\Services\AdfsService;
use WaterlooBae\UwAdfs\Services\AccessControlService;

class AdfsController extends Controller
{
    protected AdfsService $adfsService;

    public function __construct(AdfsService $adfsService)
    {
        $this->adfsService = $adfsService;
    }

    /**
     * Initiate SAML login
     */
    public function login(Request $request): void
    {
        $returnTo = $request->get('returnTo', config('app.url'));
        $this->adfsService->login($returnTo);
        
        // The OneLogin library will handle the redirect via headers, but we need to exit
        exit();
    }

    /**
     * Handle SAML response (Assertion Consumer Service)
     */
    public function acs(Request $request): RedirectResponse
    {
        try {
            $samlData = $this->adfsService->acs();
            
            // Check access control
            $accessControl = new AccessControlService(config('uw-adfs.access_control', []));
            $accessResult = $accessControl->isUserAuthorized($samlData['attributes']);
            
            // Log access decision
            $userData = $this->adfsService->mapAttributes($samlData['attributes']);
            $email = $userData['email'] ?? 'unknown';
            $accessControl->logAccessDecision($email, $accessResult);
            
            if (!$accessResult['authorized']) {
                return redirect(config('uw-adfs.access_control.access_denied_url', '/access-denied'))
                    ->with('adfs.error', $accessResult['reason'])
                    ->with('adfs.access_control_details', $accessResult);
            }
            
            // Create or update user from SAML attributes
            $samlData['attributes']['password'] = bcrypt(Str::random(32));
            $user = $this->adfsService->createOrUpdateUser($samlData['attributes']);
            
            if ($user) {
                // Log the user in
                Auth::login($user);
                
                // Store SAML session data
                Session::put('saml_session', [
                    'nameId' => $samlData['nameId'],
                    'nameIdFormat' => $samlData['nameIdFormat'],
                    'sessionIndex' => $samlData['sessionIndex'],
                    'attributes' => $samlData['attributes'],
                    'access_control_result' => $accessResult,
                ]);
                
                // Get return URL from RelayState or default
                $returnTo = $request->get('RelayState');
                if (empty($returnTo) || $returnTo === config('app.url')) {
                    $returnTo = config('app.url') . '/dashboard';
                }
                Log::info('ADFS user logged in: ' . $email);
                // Log::debug('Session data: ' . json_encode(Session::get('saml_session')));

                return redirect($returnTo)->with('adfs.success', 'Successfully logged in via ADFS');
            }
            
            // User creation failed - properly log out from ADFS
            $this->adfsService->sls();
            // Clear session
            Session::flush();
            Log::error('ADFS user creation failed for attributes: ' . json_encode($samlData['attributes']) ?? 'N/A');
            return redirect()->route('saml.sls')->with('adfs.error', 'Unable to create user account');
            
        } catch (\Exception $e) {

            $this->adfsService->sls();            
            // Clear session
            Session::flush();
            Log::error('ADFS authentication failed: ' . $e->getMessage());
            return redirect()->route('saml.sls')->with('adfs.error', 'ADFS authentication failed: ' . $e->getMessage());
        }
    }

    /**
     * Handle SAML logout
     */
    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $email = $user?->email ?? 'unknown';
        
        // Get SAML session data before clearing
        $samlSession = Session::get('saml_session');
        $nameId = $samlSession['nameId'] ?? null;
        $nameIdFormat = $samlSession['nameIdFormat'] ?? null;
        $sessionIndex = $samlSession['sessionIndex'] ?? null;
        
        Log::info("Logout initiated for user: {$email}");
        Log::debug("SAML logout data - NameID: {$nameId}, NameIDFormat: {$nameIdFormat}, SessionIndex: {$sessionIndex}");
        
        // Log SAML configuration for debugging
        $samlConfig = $this->adfsService->buildSamlConfig();
        Log::debug("SLS endpoint: " . json_encode($samlConfig['sp']['singleLogoutService'] ?? 'not set'));
        Log::debug("IdP SLS endpoint: " . json_encode($samlConfig['idp']['singleLogoutService'] ?? 'not set'));
        
        // Clear SAML session data
        Session::forget('saml_session');
        
        // Log out from Laravel
        Auth::logout();
        
        // Invalidate the entire session
        Session::invalidate();
        
        // Regenerate session token to prevent session fixation
        Session::regenerateToken();
        
        // Get ADFS logout URL from configuration
        $environment = config('uw-adfs.environment', 'development');
        $adfsLogoutBaseUrl = config("uw-adfs.idp.{$environment}.singleLogoutService.url");
        
        // Construct ADFS logout URL with wa=wsignout1.0 parameter
        // This tells ADFS to perform a sign-out and clear all sessions
        $adfsLogoutUrl = rtrim($adfsLogoutBaseUrl, '/');
        $adfsLogoutUrl .= '?wa=wsignout1.0&wreply=' . urlencode(config('app.url'));
        
        Log::info("User logged out: {$email}");
        Log::info("Redirecting to ADFS logout: {$adfsLogoutUrl}");
        
        // Redirect to ADFS logout endpoint to clear ADFS session
        return redirect($adfsLogoutUrl);
    }

    /**
     * Handle SAML Single Logout Service
     */
    public function sls(Request $request): RedirectResponse
    {
        // Log::info("SLS endpoint called - processing ADFS logout response");
        // Log::debug("SLS request params: " . json_encode($request->all()));
        // Log::debug("SLS GET params: " . json_encode($request->query->all()));
        // Log::debug("SLS POST params: " . json_encode($request->request->all()));
        // Log::debug("SLS request method: " . $request->method());
        // Log::debug("SLS server vars - SAMLResponse: " . ($request->server('QUERY_STRING') ?? 'N/A'));
        
        try {
            $this->adfsService->sls();
            
            // Log::info("ADFS SLS processed successfully");
            
            // Log out from Laravel if not already done
            if (Auth::check()) {
                Auth::logout();
                Session::invalidate();
            }
            
            // Log::info("User logged out from Laravel");
            return redirect('/')->with('adfs.success', 'Successfully logged out');
            
        } catch (\Exception $e) {
            // Log::error("SLS processing error: " . $e->getMessage());
            // Log::debug("SLS exception trace: " . $e->getTraceAsString());
            return redirect('/')->with('adfs.error', 'Logout failed: ' . $e->getMessage());
        }
    }

    /**
     * Return SAML metadata
     */
    public function metadata(): Response
    {
        return $this->adfsService->getMetadata();
    }

    /**
     * Show user attributes (for debugging)
     */
    public function attributes(Request $request)
    {
        if (!Auth::check()) {
            return redirect('saml.login')->with('adfs.error', 'Please log in first');
        }
        
        $samlSession = Session::get('saml_session');
        
        return response()->json([
            'user' => Auth::user(),
            'saml_attributes' => $samlSession['attributes'] ?? [],
            'saml_session' => $samlSession ?? [],
        ]);
    }
}