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
    
    // SAML Logout
    Route::get('/logout', [AdfsController::class, 'logout'])->name('saml.logout');
    Route::post('/logout', [AdfsController::class, 'logout']);
    
    // SAML Metadata
    Route::get('/metadata', [AdfsController::class, 'metadata'])->name('saml.metadata');
    
        // Debug route for SAML attributes (remove in production)\n    Route::get('/attributes', [AdfsController::class, 'attributes'])->name('saml.attributes');\n    \n    // Proxy routes (when acting as SAML proxy/staging AP)\n    Route::prefix('proxy')->name('saml.proxy.')->group(function () {\n        Route::get('/sso', [\\WaterlooBae\\UwAdfs\\Http\\Controllers\\ProxyController::class, 'sso'])->name('sso');\n        Route::post('/sso', [\\WaterlooBae\\UwAdfs\\Http\\Controllers\\ProxyController::class, 'sso']);\n        Route::post('/acs', [\\WaterlooBae\\UwAdfs\\Http\\Controllers\\ProxyController::class, 'acs'])->name('acs');\n        Route::get('/sls', [\\WaterlooBae\\UwAdfs\\Http\\Controllers\\ProxyController::class, 'sls'])->name('sls');\n        Route::post('/sls', [\\WaterlooBae\\UwAdfs\\Http\\Controllers\\ProxyController::class, 'sls']);\n        Route::get('/metadata', [\\WaterlooBae\\UwAdfs\\Http\\Controllers\\ProxyController::class, 'metadata'])->name('metadata');\n        Route::get('/status', [\\WaterlooBae\\UwAdfs\\Http\\Controllers\\ProxyController::class, 'status'])->name('status');\n    });\n});">
});

// Access denied route
Route::get('/access-denied', function () {
    return view('uw-adfs::access-denied');
})->name('uw-adfs.access-denied');