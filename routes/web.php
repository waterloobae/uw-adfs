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
    
    // Debug route for SAML attributes (remove in production)
    Route::get('/attributes', [AdfsController::class, 'attributes'])->name('saml.attributes');
});