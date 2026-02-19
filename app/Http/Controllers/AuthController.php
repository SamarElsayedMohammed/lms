<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\HelperService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct()
    {
        // Remove middleware approach that's causing issues
    }

    public function showLoginForm()
    {
        // Check if user is already authenticated
        if (Auth::check()) {
            return redirect()->intended(route('dashboard'));
        }

        // Fetch the settings you need
        $settings = HelperService::systemSettings(['vertical_logo', 'login_banner_image']);
        return view('auth.login', compact('settings'));
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended(route('dashboard'));
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login-page');
    }
}
