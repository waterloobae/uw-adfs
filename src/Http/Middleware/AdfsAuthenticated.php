<?php

namespace WaterlooBae\UwAdfs\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdfsAuthenticated
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $guard = null)
    {
        if (!Auth::guard($guard)->check()) {
            return redirect()->route('saml.login', [
                'returnTo' => $request->fullUrl()
            ]);
        }

        return $next($request);
    }
}