<?php

namespace WaterlooBae\UwAdfs\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use WaterlooBae\UwAdfs\Services\AdfsService;

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
            
            // Create or update user from SAML attributes
            $user = $this->adfsService->createOrUpdateUser($samlData['attributes']);
            
            if ($user) {
                // Log the user in
                Auth::login($user);
                
                // Store SAML session data
                Session::put('saml_session', [
                    'nameId' => $samlData['nameId'],
                    'sessionIndex' => $samlData['sessionIndex'],
                    'attributes' => $samlData['attributes'],
                ]);
                
                // Get return URL from RelayState or default
                $returnTo = $request->get('RelayState', config('app.url') . '/dashboard');
                
                return redirect($returnTo)->with('success', 'Successfully logged in via ADFS');
            }
            
            return redirect('/login')->with('error', 'Unable to create user account');
            
        } catch (\Exception $e) {
            return redirect('/login')->with('error', 'ADFS authentication failed: ' . $e->getMessage());
        }
    }

    /**
     * Handle SAML logout
     */
    public function logout(Request $request): void
    {
        $samlSession = Session::get('saml_session');
        $nameId = $samlSession['nameId'] ?? null;
        $sessionIndex = $samlSession['sessionIndex'] ?? null;
        
        // Log out from Laravel
        Auth::logout();
        Session::flush();
        
        $returnTo = $request->get('returnTo', config('app.url'));
        
        $this->adfsService->logout($returnTo, $nameId, $sessionIndex);
        
        // The OneLogin library will handle the redirect via headers
        exit();
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