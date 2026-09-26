<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

class RolePermissionController extends Controller
{
    public function permissions(Request $request): JsonResponse
    {
        $permissions = Permission::where('guard_name', 'web')->pluck('name');
        
        return response()->json([
            'data' => $permissions
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $roles = Role::where('company_id', $user->company_id)
            ->orWhere('is_system', true)
            ->with('permissions:id,name')
            ->get()
            ->map(function ($role) use ($user) {
                // Strip the appended company_id from name for display if it's a custom role
                if (!$role->is_system && str_ends_with($role->name, '_' . $user->company_id)) {
                    $role->display_name = substr($role->name, 0, strrpos($role->name, '_'));
                } else {
                    $role->display_name = ucfirst($role->name);
                }
                $role->permissions_list = $role->permissions->pluck('name')->toArray();
                unset($role->permissions);
                $role->permissions = $role->permissions_list;
                unset($role->permissions_list);
                return $role;
            });

        return response()->json([
            'data' => $roles
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name']
        ]);

        $roleName = $validated['name'] . '_' . $companyId;

        if (Role::where('name', $roleName)->orWhere('name', $validated['name'])->exists()) {
            return response()->json(['message' => 'A role with this name already exists.'], 422);
        }

        $role = Role::create([
            'name' => $roleName,
            'guard_name' => 'web',
            'company_id' => $companyId,
            'is_system' => false,
        ]);

        if (!empty($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        return response()->json([
            'message' => 'Role created successfully.',
            'data' => $role
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $role = Role::where('company_id', $user->company_id)->findOrFail($id);

        if ($role->is_system) {
            return response()->json(['message' => 'System roles cannot be modified.'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name']
        ]);

        $roleName = $validated['name'] . '_' . $user->company_id;
        
        if ($role->name !== $roleName && Role::where('name', $roleName)->exists()) {
            return response()->json(['message' => 'A role with this name already exists.'], 422);
        }

        $role->update([
            'name' => $roleName,
        ]);

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        return response()->json([
            'message' => 'Role updated successfully.',
            'data' => $role
        ]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $role = Role::where('company_id', $user->company_id)->findOrFail($id);

        if ($role->is_system) {
            return response()->json(['message' => 'System roles cannot be deleted.'], 403);
        }

        // Check if role is assigned to any users
        if (\DB::table('model_has_roles')->where('role_id', $role->id)->exists()) {
            return response()->json(['message' => 'Cannot delete role because it is assigned to users.'], 422);
        }

        $role->delete();

        return response()->json([
            'message' => 'Role deleted successfully.'
        ]);
    }
}
