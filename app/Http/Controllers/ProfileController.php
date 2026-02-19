<?php

namespace App\Http\Controllers;

use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    /**
     * Display the admin profile page.
     */
    public function index()
    {
        $user = auth()->user();
        return view('profile.index', [
            'type_menu' => 'profile',
            'user' => $user,
        ]);
    }

    /**
     * Update the admin profile.
     */
    public function update(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'mobile' => 'nullable|string|max:20',
            'profile' => 'nullable|image|mimes:jpg,jpeg,png,gif,webp,svg|max:2048',
            'current_password' => 'nullable|string',
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'mobile' => $request->mobile,
        ];

        // Handle profile image upload
        if ($request->hasFile('profile')) {
            // Delete old profile image if exists
            if ($user->profile && Storage::exists($user->profile)) {
                Storage::delete($user->profile);
            }

            $imagePath = $request->file('profile')->store('profiles', 'public');
            $data['profile'] = $imagePath;
        }

        // Handle password change
        if ($request->filled('current_password') && $request->filled('password')) {
            if (!Hash::check($request->current_password, $user->password)) {
                return ResponseService::errorResponse('Current password is incorrect');
            }
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        return ResponseService::successResponse('Profile updated successfully');
    }
}
