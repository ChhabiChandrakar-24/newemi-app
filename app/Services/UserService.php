<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $role = Arr::pull($data, 'role');
            $company = app(TenantContext::class)->company();
            if ($company?->max_users && User::where('company_id', $company->id)->count() >= $company->max_users) {
                throw ValidationException::withMessages(['company' => ['The company user limit has been reached.']]);
            }
            $allowedRole = Role::query()->where('name', $role)->firstOrFail();
            $user = User::query()->create($data + ['company_id' => $company?->id]);
            $user->syncRoles([$allowedRole]);
            $this->audit->record('user.created', $user, null, $this->snapshot($user));

            return $user->load('roles');
        });
    }

    public function update(User $user, User $actor, array $data): User
    {
        return DB::transaction(function () use ($user, $actor, $data): User {
            $old = $this->snapshot($user);
            $role = Arr::pull($data, 'role');

            if ($user->hasRole('super-admin') && $role !== 'super-admin') {
                $this->ensureAnotherSuperAdmin($user);
            }

            if (($data['status'] ?? null) === 'inactive' && $user->status !== 'inactive') {
                if ($user->is($actor)) {
                    throw ValidationException::withMessages(['status' => ['You cannot deactivate your own account.']]);
                }

                if ($user->hasRole('super-admin')) {
                    $this->ensureAnotherSuperAdmin($user);
                }
            }

            if (blank($data['password'] ?? null)) {
                unset($data['password']);
            }

            $user->update($data);
            $allowedRole = Role::query()->where('name', $role)->firstOrFail();
            $user->syncRoles([$allowedRole]);
            $user->load('roles');
            if ($user->status === 'inactive') {
                $user->tokens()->delete();
            }
            $this->audit->record('user.updated', $user, $old, $this->snapshot($user));

            return $user;
        });
    }

    public function changeStatus(User $user, User $actor, string $status): User
    {
        if ($user->is($actor) && $status === 'inactive') {
            throw ValidationException::withMessages(['status' => ['You cannot deactivate your own account.']]);
        }

        if ($status === 'inactive' && $user->hasRole('super-admin')) {
            $this->ensureAnotherSuperAdmin($user);
        }

        return DB::transaction(function () use ($user, $status): User {
            $old = ['status' => $user->status];
            $user->update(['status' => $status]);
            if ($status === 'inactive') {
                $user->tokens()->delete();
            }
            $this->audit->record('user.status_changed', $user, $old, ['status' => $status]);

            return $user;
        });
    }

    public function delete(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw new AuthorizationException('You cannot delete your own account.');
        }

        if ($user->hasRole('super-admin')) {
            $this->ensureAnotherSuperAdmin($user);
        }

        DB::transaction(function () use ($user): void {
            $old = $this->snapshot($user);
            $user->tokens()->delete();
            $user->delete();
            $this->audit->record('user.deleted', $user, $old, null);
        });
    }

    public function restore(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            $user->restore();
            $this->audit->record('user.restored', $user, null, $this->snapshot($user));

            return $user->load('roles');
        });
    }

    private function ensureAnotherSuperAdmin(User $user): void
    {
        $otherExists = User::role('super-admin')
            ->where('company_id', $user->company_id)
            ->where('status', 'active')
            ->whereKeyNot($user->getKey())
            ->exists();

        if (! $otherExists) {
            throw ValidationException::withMessages([
                'user' => ['The final Super Admin cannot be demoted, deactivated, or deleted.'],
            ]);
        }
    }

    private function snapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'mobile_number' => $user->mobile_number,
            'status' => $user->status,
            'role' => $user->roles->first()?->name,
        ];
    }
}
