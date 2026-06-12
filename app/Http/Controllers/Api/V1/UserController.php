<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $authUser = $request->user();

        $query = User::query()
            ->when(!$authUser->isSuperAdmin() && !$authUser->isDeveloper(), fn($q) => $q->where('merchant_id', $authUser->merchant_id))
            ->when($request->merchant_id && ($authUser->isSuperAdmin() || $authUser->isDeveloper()), fn($q, $m) => $q->where('merchant_id', $request->merchant_id))
            ->when($request->role, fn($q, $r) => $q->where('role', $r))
            ->when($request->search, fn($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%");
            }))
            ->orderBy('name');

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function store(Request $request)
    {
        $authUser = $request->user();

        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'email'       => 'required|email|max:255|unique:users,email',
            'phone'       => 'nullable|string|max:20',
            'password'    => 'required|string|min:8',
            'role'        => ['required', Rule::in($this->assignableRoles($authUser))],
            'merchant_id' => 'nullable|integer|exists:merchants,id',
            'is_active'   => 'nullable|boolean',
        ]);

        $merchantId = $authUser->isSuperAdmin() || $authUser->isDeveloper()
            ? ($data['merchant_id'] ?? null)
            : $authUser->merchant_id;

        if (in_array($data['role'], ['dispatcher', 'driver', 'merchant_owner']) && empty($merchantId)) {
            return response()->json(['message' => 'merchant_id is required for this role.'], 422);
        }

        $user = User::create([
            'ulid'        => Str::ulid(),
            'merchant_id' => $merchantId,
            'name'        => $data['name'],
            'email'       => $data['email'],
            'phone'       => $data['phone'] ?? null,
            'password'    => Hash::make($data['password']),
            'role'        => $data['role'],
            'is_active'   => $data['is_active'] ?? true,
        ]);

        if ($data['role'] === 'driver') {
            $this->ensureDriverRecord($user);
        }

        return response()->json(['data' => $this->formatUser($user)], 201);
    }

    public function show(Request $request, User $user)
    {
        $this->authorizeManage($request, $user);
        return response()->json(['data' => $this->formatUser($user)]);
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeManage($request, $user);
        $authUser = $request->user();

        $data = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'email'     => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone'     => 'nullable|string|max:20',
            'role'      => ['sometimes', Rule::in($this->assignableRoles($authUser))],
            'is_active' => 'nullable|boolean',
        ]);

        $user->update($data);

        if (($data['role'] ?? null) === 'driver') {
            $this->ensureDriverRecord($user);
        }

        return response()->json(['data' => $this->formatUser($user->fresh())]);
    }

    public function destroy(Request $request, User $user)
    {
        $this->authorizeManage($request, $user);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $user->delete();
        return response()->json(null, 204);
    }

    public function resetPassword(Request $request, User $user)
    {
        $this->authorizeManage($request, $user);

        $data = $request->validate([
            'password' => 'nullable|string|min:8',
        ]);

        $newPassword = $data['password'] ?? Str::random(10);
        $user->update(['password' => Hash::make($newPassword)]);

        return response()->json([
            'message' => 'Password reset successfully.',
            'data'    => ['password' => $newPassword],
        ]);
    }

    private function assignableRoles(User $authUser): array
    {
        if ($authUser->isSuperAdmin() || $authUser->isDeveloper()) {
            return ['super_admin', 'developer', 'merchant_owner', 'dispatcher', 'driver'];
        }

        // merchant_owner can manage staff within their own merchant
        return ['dispatcher', 'driver'];
    }

    private function authorizeManage(Request $request, User $target): void
    {
        $authUser = $request->user();

        if ($authUser->isSuperAdmin() || $authUser->isDeveloper()) {
            return;
        }

        if ($target->merchant_id !== $authUser->merchant_id || in_array($target->role, ['super_admin', 'developer'])) {
            abort(403, 'Access denied.');
        }
    }

    private function ensureDriverRecord(User $user): void
    {
        if (Driver::where('user_id', $user->id)->exists() || !$user->merchant_id) {
            return;
        }

        Driver::create([
            'ulid'          => Str::ulid(),
            'merchant_id'   => $user->merchant_id,
            'user_id'       => $user->id,
            'driver_name'   => $user->name,
            'phone'         => $user->phone ?? '',
            'vehicle_type'  => 'motorcycle',
            'vehicle_plate' => '',
            'status'        => 'offline',
        ]);
    }

    private function formatUser(User $user): array
    {
        return [
            'id'         => $user->id,
            'ulid'       => $user->ulid,
            'merchant_id'=> $user->merchant_id,
            'name'       => $user->name,
            'email'      => $user->email,
            'phone'      => $user->phone,
            'role'       => $user->role,
            'is_active'  => $user->is_active,
            'last_login_at' => $user->last_login_at,
        ];
    }
}
