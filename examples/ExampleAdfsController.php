<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use WaterlooBae\UwAdfs\Facades\UwAdfs;

class ExampleAdfsController extends Controller
{
    /**
     * Dashboard - requires ADFS authentication
     */
    public function dashboard()
    {
        $user = Auth::user();
        $samlSession = session('saml_session');
        
        return view('dashboard', [
            'user' => $user,
            'saml_attributes' => $samlSession['attributes'] ?? [],
        ]);
    }

    /**
     * Admin area - requires specific AD group
     */
    public function admin()
    {
        $user = Auth::user();
        $samlSession = session('saml_session');
        $groups = UwAdfs::getUserGroups($samlSession['attributes']);
        
        return view('admin', [
            'user' => $user,
            'groups' => $groups,
        ]);
    }

    /**
     * Faculty-only content
     */
    public function facultyOnly()
    {
        return view('faculty.index');
    }

    /**
     * Student-only content
     */
    public function studentOnly()
    {
        return view('student.index');
    }

    /**
     * Show user profile with SAML attributes
     */
    public function profile()
    {
        $user = Auth::user();
        $samlSession = session('saml_session');
        
        return view('profile', [
            'user' => $user,
            'saml_data' => $samlSession,
            'groups' => UwAdfs::getUserGroups($samlSession['attributes'] ?? []),
        ]);
    }
}