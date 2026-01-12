<?php

use Illuminate\Support\Facades\Route;
use WaterlooBae\UwAdfs\Http\Controllers\AdfsController;

/*
|--------------------------------------------------------------------------
| UW ADFS Routes
|--------------------------------------------------------------------------
|
| Routes for SAML authentication with University of Waterloo ADFS
|
*/

Route::group([
    'middleware' => config('uw-adfs.middleware', ['web']),
    'prefix' => 'saml',
], function () {
    
    // SAML Login
    Route::get('/login', [AdfsController::class, 'login'])->name('saml.login');
    
    // SAML Assertion Consumer Service (ACS)
    Route::post('/acs', [AdfsController::class, 'acs'])->name('saml.acs');
    
    // SAML Single Logout Service (SLS)
    Route::get('/sls', [AdfsController::class, 'sls'])->name('saml.sls');
    Route::post('/sls', [AdfsController::class, 'sls']);
    
    // Catch any SLS attempts (for debugging)
    Route::any('/sls-debug', function () {
        \Illuminate\Support\Facades\Log::info("SLS Debug route hit", [
            'method' => \Illuminate\Support\Facades\Request::method(),
            'all' => \Illuminate\Support\Facades\Request::all(),
            'query' => \Illuminate\Support\Facades\Request::query->all(),
            'headers' => \Illuminate\Support\Facades\Request::headers->all(),
        ]);
        return response('SLS Debug logged', 200);
    });
    
    // SAML Logout
    Route::get('/logout', [AdfsController::class, 'logout'])->name('saml.logout');
    Route::post('/logout', [AdfsController::class, 'logout']);
    
    // SAML Metadata
    Route::get('/metadata', [AdfsController::class, 'metadata'])->name('saml.metadata');
    
   // Debug route for SAML attributes (remove in production)
    Route::get('/attributes', [AdfsController::class, 'attributes'])->name('saml.attributes');
    
    // Proxy routes (when acting as SAML proxy/staging AP)
    Route::prefix('proxy')->name('saml.proxy.')->group(function () {
        Route::get('/sso', [\WaterlooBae\UwAdfs\Http\Controllers\ProxyController::class, 'sso'])->name('sso');
        Route::post('/sso', [\WaterlooBae\UwAdfs\Http\Controllers\ProxyController::class, 'sso']);
        Route::post('/acs', [\WaterlooBae\UwAdfs\Http\Controllers\ProxyController::class, 'acs'])->name('acs');
        Route::get('/sls', [\WaterlooBae\UwAdfs\Http\Controllers\ProxyController::class, 'sls'])->name('sls');
        Route::post('/sls', [\WaterlooBae\UwAdfs\Http\Controllers\ProxyController::class, 'sls']);
        Route::get('/metadata', [\WaterlooBae\UwAdfs\Http\Controllers\ProxyController::class, 'metadata'])->name('metadata');
        Route::get('/status', [\WaterlooBae\UwAdfs\Http\Controllers\ProxyController::class, 'status'])->name('status');
    });
    
});

// Access denied route
Route::get('/access-denied', function () {
    return view('uw-adfs::access-denied');
})->name('uw-adfs.access-denied');