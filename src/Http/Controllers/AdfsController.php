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
                
                Log::info("SAML session stored - NameID: " . ($samlData['nameId'] ?? 'NULL') . ", SessionIndex: " . ($samlData['sessionIndex'] ?? 'NULL') . ", Format: " . ($samlData['nameIdFormat'] ?? 'NULL'));
                
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
    public function logout(Request $request): void
    {
        
        // Get SAML session data FIRST before clearing it
        $samlSession = Session::get('saml_session');
        $nameId = $samlSession['nameId'] ?? null;
        $nameIdFormat = $samlSession['nameIdFormat'] ?? null;
        $sessionIndex = $samlSession['sessionIndex'] ?? null;
        $returnTo = $request->get('returnTo', config('app.url'));
        
        // Get email from authenticated user
        $email = Auth::user()?->email ?? null;
        
        Log::info("Logout endpoint called - User check: " . (Auth::check() ? 'authenticated' : 'not-authenticated'));
        Log::info("User email: " . ($email ? $email : 'NULL'));
        Log::info("Retrieved session data: NameID=" . ($nameId ? $nameId : 'NULL') . ", SessionIndex=" . ($sessionIndex ? substr($sessionIndex, 0, 20) . '...' : 'NULL') . ", Format=" . ($nameIdFormat ? $nameIdFormat : 'NULL') . ", Email=" . ($email ? $email : 'NULL'));
        
        // Get logout redirect URL from ADFS service BEFORE clearing session
        $logoutUrl = $this->adfsService->logout($returnTo, $nameId, $sessionIndex, $nameIdFormat, $email);
        
        // Log::debug("ADFS logout URL obtained: " . ($logoutUrl ? substr($logoutUrl, 0, 100) . "..." : "none"));
        
        // NOW clear the local session
        Auth::logout();
        Session::forget('saml_session');
        Session::invalidate();        
        Session::regenerateToken();
        Session::flush();
            
        Log::info("Local logout completed, redirecting to: " . $returnTo);
        
        // Redirect to ADFS logout if we have a URL
        if ($logoutUrl) {
            Log::info("Redirecting to ADFS logout URL");
            header('Location: ' . $logoutUrl);
        } else {
            Log::warning("No ADFS logout URL available, redirecting to home");
            header('Location: ' . config('app.url'));
        }
        
        exit();        
   }

    /**
     * Handle SAML Single Logout Service
     */
    public function sls(Request $request): RedirectResponse
    {
        Log::info("=== SLS ENDPOINT CALLED ===");
        Log::info("SLS request method: " . $request->method());
        Log::info("SLS request path: " . $request->path());
        
        // Log all parameters
        Log::debug("SLS query parameters: " . json_encode($request->query->all()));
        
        // Log SAMLResponse if present
        if ($request->has('SAMLResponse')) {
            Log::debug("SAMLResponse present: " . substr($request->get('SAMLResponse'), 0, 100) . "...");
        }
        if ($request->has('SAMLRequest')) {
            Log::debug("SAMLRequest present: " . substr($request->get('SAMLRequest'), 0, 100) . "...");
        }
        if ($request->has('RelayState')) {
            Log::debug("RelayState: " . $request->get('RelayState'));
        }
        
        try {
            Log::info("Calling AdfsService::sls() to process ADFS LogoutRequest");
            $logoutResponseUrl = $this->adfsService->sls();
            
            Log::info("AdfsService::sls() completed successfully");
            
            // Log out from Laravel if not already done
            if (Auth::check()) {
                Log::info("User is logged in, logging out from Laravel");
                Auth::logout();
                Session::invalidate();
            }
            
            Log::info("User logged out successfully");
            
            // If we have a LogoutResponse URL, redirect to ADFS to acknowledge the logout
            if ($logoutResponseUrl) {
                Log::info("Redirecting to ADFS LogoutResponseUrl: " . substr($logoutResponseUrl, 0, 150) . "...");
                return redirect($logoutResponseUrl)
                    ->with('adfs.success', 'Successfully logged out')
                    ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                    ->header('Pragma', 'no-cache')
                    ->header('Expires', '0');
            } else {
                Log::warning("No LogoutResponse URL generated, redirecting to home");
                return redirect('/')
                    ->with('adfs.success', 'Successfully logged out')
                    ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                    ->header('Pragma', 'no-cache')
                    ->header('Expires', '0');
            }
            
        } catch (\Exception $e) {
            Log::error("SLS processing error: " . $e->getMessage());
            Log::debug("SLS exception trace: " . $e->getTraceAsString());
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