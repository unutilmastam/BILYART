<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Users\Models\User;
use App\Domain\Users\Services\UserService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Http\Resources\UserResource;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::query()->with('branches')->orderBy('name')->get());
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('branches'));
    }

    public function store(UserRequest $request): JsonResponse
    {
        $user = $this->users->create($request->user(), [
            'name' => $request->input('name'),
            'login' => $request->input('login'),
            'password' => $request->input('password'),
            'role' => $request->input('role'),
            'branch_ids' => $this->branchIds($request),
        ]);

        return (new UserResource($user->load('branches')))->response()->setStatusCode(201);
    }

    public function update(UserRequest $request, User $user): UserResource
    {
        $data = array_filter([
            'name' => $request->input('name'),
            'password' => $request->input('password'),
            'role' => $request->input('role'),
        ], fn ($v) => $v !== null);
        if ($request->has('isActive')) {
            $data['is_active'] = $request->boolean('isActive');
        }
        if ($request->has('branchIds')) {
            $data['branch_ids'] = $this->branchIds($request);
        }

        return new UserResource($this->users->update($request->user(), $user, $data)->load('branches'));
    }

    public function deactivate(Request $request, User $user): UserResource
    {
        return new UserResource($this->users->update($request->user(), $user, ['is_active' => false])->load('branches'));
    }

    /** @return list<int> */
    private function branchIds(UserRequest $request): array
    {
        $ids = $request->branchIds();
        if ($ids === null) {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['branchIds' => [__('validation.exists', ['attribute' => 'branch'])]]]);
        }

        return $ids;
    }
}
