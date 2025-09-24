<?php

namespace WaterlooBae\UwAdfs\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdfsGroup
{
    /**
     * Handle an incoming request - check if user belongs to required group.
     */
    public function handle(Request $request, Closure $next, string $requiredGroup)
    {
        if (!Auth::check()) {
            return redirect()->route('saml.login', [
                'returnTo' => $request->fullUrl()
            ]);
        }

        $samlSession = session('saml_session');
        
        if (!$samlSession || !isset($samlSession['attributes'])) {
            return abort(403, 'SAML session data not found');
        }

        $adfsService = app('uw-adfs');
        
        if (!$adfsService->userHasGroup($samlSession['attributes'], $requiredGroup)) {
            return abort(403, "Access denied. Required group: {$requiredGroup}");
        }

        return $next($request);
    }
}