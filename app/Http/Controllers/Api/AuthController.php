<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // REGISTER
    public function register(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'nullable|string|max:255',
            'last_name'  => 'nullable|string|max:255',
            'email'      => 'required|email|unique:users',
            'phone'      => 'required|string|unique:users',
            'password'   => 'required|string|min:6',
        ]);

        $emailPrefix = explode('@', $validated['email'])[0] ?? 'User';
        $firstName = trim($validated['first_name'] ?? '');
        $lastName = trim($validated['last_name'] ?? '');
        if ($firstName === '') {
            $firstName = 'User';
        }
        if ($lastName === '') {
            $lastName = $emailPrefix;
        }

        $user = User::create([
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'email'      => $validated['email'],
            'phone'      => $validated['phone'],
            'password'   => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('mobile_token')->plainTextToken;

        $defaultRole = Role::firstOrCreate(['name' => 'customer']);
        $user->roles()->syncWithoutDetaching([$defaultRole->id]);

        return response()->json([
            'message' => 'User registered successfully',
            'token'   => $token,
            'user'    => [
                ...$user->toArray(),
                'roles' => $user->roles()->pluck('name')->values(),
            ],
        ]);
    }

    // LOGIN
public function login(Request $request)
{
    $request->validate([
        'email' => 'required|email',
        'password' => 'required|string',
    ]);

    $user = User::where('email', $request->email)->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        return response()->json([
            'message' => 'Invalid credentials'
        ], 401);
    }

    // Backfill customer role for legacy users that were created before roles assignment.
    if (! $user->roles()->exists()) {
        $defaultRole = Role::firstOrCreate(['name' => 'customer']);
        $user->roles()->syncWithoutDetaching([$defaultRole->id]);
    }

    $token = $user->createToken('auth_token')->plainTextToken;

    return response()->json([
        'message' => 'Login successful',
        'token' => $token,
        'user' => [
            ...$user->toArray(),
            'roles' => $user->roles()->pluck('name')->values(),
        ],
    ]);
}

    // LOGOUT
    public function logout(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = $user->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Logout successful',
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json([
            'user' => [
                ...$user->toArray(),
                'roles' => $user->roles()->pluck('name')->values(),
            ],
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'required|string|max:50|unique:users,phone,' . $user->id,
        ]);

        $user->first_name = $validated['first_name'];
        $user->last_name = $validated['last_name'];
        $user->phone = $validated['phone'];
        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => [
                ...$user->toArray(),
                'roles' => $user->roles()->pluck('name')->values(),
            ],
        ]);
    }

}
