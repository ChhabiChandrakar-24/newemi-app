<?php

namespace App\Services;

use App\Models\Role;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data): Role {
            $role = Role::query()->create(['company_id' => app(TenantContext::class)->id(), 'is_system' => false, 'name' => $data['name'], 'guard_name' => 'web']);
            $role->syncPermissions($data['permissions']);
            $this->audit->record('role.created', $role, null, $this->snapshot($role));
            $this->audit->record('role.permissions_changed', $role, null, ['permissions' => $data['permissions']]);

            return $role->load('permissions');
        });
    }

    public function update(Role $role, array $data): Role
    {
        if ($role->is_system) {
            throw ValidationException::withMessages(['name' => ['System roles cannot be modified.']]);
        }

        return DB::transaction(function () use ($role, $data): Role {
            $old = $this->snapshot($role);
            $oldPermissions = $role->permissions->pluck('name')->sort()->values()->all();
            $role->update(['name' => $data['name']]);
            $role->syncPermissions($data['permissions']);
            $role->load('permissions');
            $this->audit->record('role.updated', $role, $old, $this->snapshot($role));

            $newPermissions = $role->permissions->pluck('name')->sort()->values()->all();
            if ($oldPermissions !== $newPermissions) {
                $this->audit->record(
                    'role.permissions_changed',
                    $role,
                    ['permissions' => $oldPermissions],
                    ['permissions' => $newPermissions],
                );
            }

            return $role;
        });
    }

    public function delete(Role $role): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => ['System roles cannot be deleted.']]);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages(['role' => ['A role assigned to users cannot be deleted.']]);
        }

        DB::transaction(function () use ($role): void {
            $old = $this->snapshot($role);
            $this->audit->record('role.deleted', $role, $old, null);
            $role->delete();
        });
    }

    private function snapshot(Role $role): array
    {
        return [
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
        ];
    }
}
