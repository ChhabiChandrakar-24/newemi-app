<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Requests\Api\V1\UpdateUserStatusRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'role' => ['nullable', 'string', 'exists:roles,name'],
            'company_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'in:name,email,status,created_at,updated_at'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'archived' => ['nullable', 'boolean'],
        ]);

        $isPlatformAdmin = (bool) $request->user()->is_platform_admin;

        $users = User::query()
            ->when($request->boolean('archived'), fn ($query) => $query->onlyTrashed())
            ->when(! $isPlatformAdmin, function ($query) use ($request) {
                $query->where('company_id', $request->user()->company_id);
            }, function ($query) use ($validated) {
                if (! empty($validated['company_id'])) {
                    $query->where('company_id', $validated['company_id']);
                }
            })
            ->with(['roles', 'company'])
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('mobile_number', 'like', "%{$search}%");
                });
            })
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['role'] ?? null, fn ($query, string $role) => $query->role($role))
            ->orderBy($validated['sort'] ?? 'created_at', $validated['direction'] ?? 'desc')
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load(['roles', 'company']));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        return (new UserResource($this->users->create($request->validated())))
            ->additional(['message' => 'User created successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        return (new UserResource($this->users->update(
            $user->load('roles'),
            $request->user(),
            $request->validated(),
        )))
            ->additional(['message' => 'User updated successfully.']);
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): UserResource
    {
        return (new UserResource($this->users->changeStatus(
            $user->load('roles'),
            $request->user(),
            $request->validated('status'),
        )))->additional(['message' => 'User status updated successfully.']);
    }

    public function updatePassword(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user->update(['password' => bcrypt($validated['password'])]);

        return response()->json(['message' => 'User password updated successfully.']);
    }

    public function destroy(Request $request, User $user): Response
    {
        $this->users->delete($user->load('roles'), $request->user());

        return response()->noContent();
    }

    public function restore(Request $request, int $user): UserResource
    {
        $model = User::onlyTrashed()->where('company_id', $request->user()->company_id)->findOrFail($user);

        return (new UserResource($this->users->restore($model)))
            ->additional(['message' => 'User restored successfully.']);
    }
}
