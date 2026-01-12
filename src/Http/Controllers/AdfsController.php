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
                    ->with('error', $accessResult['reason'])
                    ->with('access_control_details', $accessResult);
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

                return redirect($returnTo)->with('success', 'Successfully logged in via ADFS');
            }
            
            // User creation failed - properly log out from ADFS
            $this->adfsService->sls();
            // Clear session
            Session::flush();
            Log::error('ADFS user creation failed for attributes: ' . json_encode($samlData['attributes']) ?? 'N/A');
            return redirect('saml/sls')->with('error', 'Unable to create user account');
            
        } catch (\Exception $e) {

            $this->adfsService->sls();            
            // Clear session
            Session::flush();
            Log::error('ADFS authentication failed: ' . $e->getMessage());
            return redirect('saml/sls')->with('error', 'ADFS authentication failed: ' . $e->getMessage());
        }
    }

    /**
     * Handle SAML logout
     */
    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $email = $user?->email ?? 'unknown';
        
        // Clear SAML session data
        Session::forget('saml_session');
        
        // Log out from Laravel
        Auth::logout();
        
        // Invalidate the entire session
        Session::invalidate();
        
        // Regenerate session token to prevent session fixation
        Session::regenerateToken();
        
        Log::info("User logged out: {$email}");
        
        // Clear ADFS-related cookies to force re-authentication
        $response = redirect('/')->with('success', 'Logged out successfully');
        
        // Clear ADFS authentication cookies
        $response->withoutCookie('MSISAuth');
        $response->withoutCookie('MSISAuth1');
        $response->withoutCookie('MSISAuthenticated');
        
        return $response;
    }

    /**
     * Handle SAML Single Logout Service
     */
    public function sls(Request $request): RedirectResponse
    {
        try {
            $this->adfsService->sls();
            
            // Log out from Laravel if not already done
            if (Auth::check()) {
                Auth::logout();
                Session::flush();
            }
            
            return redirect('/')->with('success', 'Successfully logged out');
            
        } catch (\Exception $e) {
            return redirect('/')->with('error', 'Logout failed: ' . $e->getMessage());
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
            return redirect('/login')->with('error', 'Please log in first');
        }
        
        $samlSession = Session::get('saml_session');
        
        return response()->json([
            'user' => Auth::user(),
            'saml_attributes' => $samlSession['attributes'] ?? [],
            'saml_session' => $samlSession ?? [],
        ]);
    }
}