<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\User\DestroyAdminUserAction;
use App\Actions\Admin\User\ListAdminUsersAction;
use App\Actions\Admin\User\StoreAdminUserAction;
use App\Actions\Admin\User\UpdateAdminUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly ListAdminUsersAction $listUsersAction,
        private readonly StoreAdminUserAction $storeUserAction,
        private readonly UpdateAdminUserAction $updateUserAction,
        private readonly DestroyAdminUserAction $destroyUserAction,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listUsersAction->handle($request));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $result = $this->storeUserAction->handle($request);

        return $result->toResponse();
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $result = $this->updateUserAction->handle($request, $user);

        return $result->toResponse();
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $result = $this->destroyUserAction->handle($request, $user);

        return $result->toResponse();
    }
}
