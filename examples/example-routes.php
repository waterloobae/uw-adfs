<?php

/*
|--------------------------------------------------------------------------
| Example Routes for UW ADFS Package
|--------------------------------------------------------------------------
|
| Copy these routes to your routes/web.php file and modify as needed
|
*/

use App\Http\Controllers\ExampleAdfsController;
use Illuminate\Support\Facades\Route;

/***** 
// Public routes
Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', function () {
    return view('auth.login');
})->name('login');

// Protected routes with ADFS authentication
Route::middleware(['adfs.auth'])->group(function () {
    
    // Dashboard - any authenticated user
    Route::get('/dashboard', [ExampleAdfsController::class, 'dashboard'])->name('dashboard');
    
    // User profile
    Route::get('/profile', [ExampleAdfsController::class, 'profile'])->name('profile');
    
});

// Group-based protected routes
Route::middleware(['adfs.group:Domain Admins'])->group(function () {
    Route::get('/admin', [ExampleAdfsController::class, 'admin'])->name('admin');
});

Route::middleware(['adfs.group:Faculty'])->group(function () {
    Route::get('/faculty', [ExampleAdfsController::class, 'facultyOnly'])->name('faculty');
});

Route::middleware(['adfs.group:Students'])->group(function () {
    Route::get('/students', [ExampleAdfsController::class, 'studentOnly'])->name('students');
});

// Alternative syntax for single routes
Route::get('/admin-alt', [ExampleAdfsController::class, 'admin'])
    ->middleware('adfs.group:Domain Admins')
    ->name('admin.alt');

// Multiple middleware
Route::get('/secure-admin', function () {
    return view('secure-admin');
})->middleware(['adfs.auth', 'adfs.group:IT Support']);
******/