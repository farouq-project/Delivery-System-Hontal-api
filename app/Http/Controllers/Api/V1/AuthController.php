<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::with('merchant.settings')
            ->where('email', $request->email)
            ->where('is_active', true)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user'  => $this->formatUser($user),
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('merchant.settings');
        return response()->json(['data' => $this->formatUser($user)]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'name'  => 'sometimes|string|max:255',
            'phone' => 'sometimes|nullable|string|max:20',
        ]);

        $request->user()->update($request->only(['name', 'phone']));
        return response()->json(['data' => $this->formatUser($request->user()->fresh())]);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password'      => 'required',
            'new_password'          => 'required|min:8|confirmed',
        ]);

        if (!Hash::check($request->current_password, $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => ['Current password is incorrect.']]);
        }

        $request->user()->update(['password' => Hash::make($request->new_password)]);
        return response()->json(['message' => 'Password changed successfully']);
    }

    private function formatUser(User $user): array
    {
        return [
            'id'       => $user->id,
            'ulid'     => $user->ulid,
            'name'     => $user->name,
            'email'    => $user->email,
            'phone'    => $user->phone,
            'role'     => $user->role,
            'merchant' => $user->merchant ? [
                'id'           => $user->merchant->id,
                'company_name' => $user->merchant->company_name,
                'slug'         => $user->merchant->slug,
                'timezone'     => $user->merchant->timezone,
            ] : null,
        ];
    }
}
