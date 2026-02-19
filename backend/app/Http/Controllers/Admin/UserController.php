<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(
        private AuditLogService $auditLog
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $users = User::with('company:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'company_id', 'is_active', 'created_at']);

        return response()->json([
            'authenticated' => true,
            'users' => $users,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $actor->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'role' => ['required', Rule::in(['admin', 'company'])],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validated['role'] === 'company' && empty($validated['company_id'])) {
            return response()->json([
                'message' => 'Usuario company precisa de company_id.',
            ], 422);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['role'],
            'company_id' => $validated['role'] === 'company' ? ($validated['company_id'] ?? null) : null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        $this->auditLog->record($request, 'admin.user.created', $user->company_id, [
            'user_id' => $user->id,
            'role' => $user->role,
            'is_active' => $user->is_active,
        ]);

        return response()->json([
            'ok' => true,
            'user' => $user->load('company:id,name'),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $actor->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(['admin', 'company'])],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
        ]);

        if ($validated['role'] === 'company' && empty($validated['company_id'])) {
            return response()->json([
                'message' => 'Usuario company precisa de company_id.',
            ], 422);
        }

        $before = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'company_id' => $user->company_id,
            'is_active' => $user->is_active,
        ];

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role = $validated['role'];
        $user->company_id = $validated['role'] === 'company' ? ($validated['company_id'] ?? null) : null;
        $user->is_active = (bool) $validated['is_active'];
        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }
        $user->save();

        $this->auditLog->record($request, 'admin.user.updated', $user->company_id, [
            'user_id' => $user->id,
            'before' => $before,
            'after' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'company_id' => $user->company_id,
                'is_active' => $user->is_active,
            ],
        ]);

        return response()->json([
            'ok' => true,
            'user' => $user->load('company:id,name'),
        ]);
    }
}

