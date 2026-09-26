<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreRoleRequest;
use App\Http\Requests\Api\V1\UpdateRoleRequest;
use App\Http\Resources\Api\V1\RoleResource;
use App\Models\Role;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(
            Role::query()->with('permissions')->orderBy('name')->get(),
        );
    }

    public function show(Role $role): RoleResource
    {
        return new RoleResource($role->load('permissions'));
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        return (new RoleResource($this->roles->create($request->validated())))
            ->additional(['message' => 'Role created successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        return (new RoleResource($this->roles->update($role->load('permissions'), $request->validated())))
            ->additional(['message' => 'Role updated successfully.']);
    }

    public function destroy(Role $role): Response
    {
        $this->roles->delete($role->load('permissions'));

        return response()->noContent();
    }
}
