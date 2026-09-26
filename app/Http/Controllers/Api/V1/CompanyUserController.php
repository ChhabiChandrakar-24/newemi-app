<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CompanyUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = app(\App\Support\TenantContext::class)->id() ?? $user->company_id;
        
        $users = User::where('company_id', $companyId)
            ->with('roles:id,name,is_system')
            ->get()
            ->map(function ($u) use ($user) {
                // Formatting role name for display
                foreach ($u->roles as $role) {
                    if (!$role->is_system && str_ends_with($role->name, '_' . $companyId)) {
                        $role->display_name = substr($role->name, 0, strrpos($role->name, '_'));
                    } else {
                        $role->display_name = ucfirst($role->name);
                    }
                }
                return $u;
            });

        return response()->json([
            'data' => $users
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'mobile_number' => ['nullable', 'string', 'max:20'],
            'role_id' => ['required', 'integer']
        ]);

        $companyId = app(\App\Support\TenantContext::class)->id() ?? $user->company_id;

        // Verify the role belongs to the company or is a system role
        $role = Role::where(function($q) use ($companyId) {
            $q->where('company_id', $companyId)->orWhere('is_system', true);
        })->findOrFail($validated['role_id']);

        // Auto-generate a secure random password
        // A mix of upper, lower, numbers, and symbols to ensure security
        $password = Str::password(10, true, true, true, false);

        $newUser = User::create([
            'company_id' => $companyId,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'mobile_number' => $validated['mobile_number'] ?? null,
            'password' => Hash::make($password),
            'status' => 'active',
            'is_platform_admin' => is_null($companyId),
        ]);

        $newUser->assignRole($role);

        // Fetch user with roles
        $newUser->load('roles');

        return response()->json([
            'message' => 'User created successfully.',
            'data' => [
                'user' => $newUser,
                'generated_password' => $password // To display in UI once
            ]
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $companyId = app(\App\Support\TenantContext::class)->id() ?? $user->company_id;
        $targetUser = User::where('company_id', $companyId)->findOrFail($id);

        if ($targetUser->id === $user->id) {
            // Can update own details, but usually done via profile endpoint
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $targetUser->id],
            'mobile_number' => ['nullable', 'string', 'max:20'],
            'role_id' => ['required', 'integer'],
            'status' => ['nullable', 'string', 'in:active,inactive']
        ]);

        $role = Role::where(function($q) use ($companyId) {
            $q->where('company_id', $companyId)->orWhere('is_system', true);
        })->findOrFail($validated['role_id']);

        $targetUser->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'mobile_number' => $validated['mobile_number'] ?? null,
            'status' => $validated['status'] ?? $targetUser->status,
        ]);

        $targetUser->syncRoles([$role]);
        $targetUser->load('roles');

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => $targetUser
        ]);
    }

    public function changePassword(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $companyId = app(\App\Support\TenantContext::class)->id() ?? $user->company_id;
        $targetUser = User::where('company_id', $companyId)->findOrFail($id);

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $targetUser->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'message' => 'User password updated successfully.'
        ]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $companyId = app(\App\Support\TenantContext::class)->id() ?? $user->company_id;
        
        if ($user->id == $id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 403);
        }

        $targetUser = User::where('company_id', $companyId)->findOrFail($id);
        
        // Cannot delete the primary owner, check if they are the first user for the company maybe
        $isOwner = User::where('company_id', $companyId)->orderBy('id')->first()->id === $targetUser->id;
        
        if ($isOwner) {
            return response()->json(['message' => 'The primary company owner cannot be deleted.'], 403);
        }

        $targetUser->delete();

        return response()->json([
            'message' => 'User deleted successfully.'
        ]);
    }
}
