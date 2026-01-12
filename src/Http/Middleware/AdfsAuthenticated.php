<?php

namespace WaterlooBae\UwAdfs\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class AdfsAuthenticated
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $guard = null)
    {
        // Check if Laravel user is authenticated
        if (!Auth::guard($guard)->check()) {
            return redirect()->route('saml.login', [
                'returnTo' => $request->fullUrl()
            ]);
        }
        
        // Also validate SAML session exists (redundant safety check)
        if (!Session::has('saml_session')) {
            Auth::logout();
            Session::invalidate();
            return redirect()->route('saml.login', [
                'returnTo' => $request->fullUrl()
            ]);
        }

        return $next($request);
    }
}