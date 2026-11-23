<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:100'],
            'role' => [
                'nullable',
                Rule::in(User::AVAILABLE_ROLES),
            ],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()->select([
            'id',
            'name',
            'email',
            'role',
            'is_active',
            'created_at',
            'updated_at',
        ]);

        if (!empty($validated['keyword'])) {
            $keyword = trim($validated['keyword']);

            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%");
            });
        }

        if (!empty($validated['role'])) {
            $query->where('role', $validated['role']);
        }

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', $validated['is_active']);
        }

        $users = $query
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeEmail($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'password' => [
                'required',
                'string',
                'min:12',
                'max:255',
                'confirmed',
            ],
            'role' => [
                'required',
                Rule::in(User::AVAILABLE_ROLES),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $user = User::create([
            'name' => trim($validated['name']),
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['role'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pengguna berhasil dibuat.',
            'data' => $this->userData($user),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->userData($user),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $this->normalizeEmail($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        if (empty($validated)) {
            throw ValidationException::withMessages([
                'profile' => [
                    'Tidak ada perubahan profil yang dikirim.',
                ],
            ]);
        }

        if (isset($validated['name'])) {
            $validated['name'] = trim($validated['name']);
        }

        $user->fill($validated);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Profil pengguna berhasil diperbarui.',
            'data' => $this->userData($user->fresh()),
        ]);
    }

    public function updateRole(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'role' => [
                'required',
                Rule::in(User::AVAILABLE_ROLES),
            ],
        ]);

        $newRole = $validated['role'];

        $user = DB::transaction(function () use ($id, $newRole) {

            $activeAdministrators = $this->lockActiveAdministrators();

            $user = User::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                (int) $user->id === (int) auth()->id()
                && $user->role !== $newRole
            ) {
                abort(
                    409,
                    'Administrator tidak dapat mengubah role akunnya sendiri.'
                );
            }

            if (
                $user->role === User::ROLE_ADMINISTRATOR
                && $newRole !== User::ROLE_ADMINISTRATOR
                && $user->isActive()
                && $activeAdministrators->count() <= 1
            ) {
                abort(
                    409,
                    'Administrator aktif terakhir tidak boleh diturunkan rolenya.'
                );
            }

            $user->role = $newRole;
            $user->save();

            return $user->fresh();
        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'Role pengguna berhasil diperbarui.',
            'data' => $this->userData($user),
        ]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $active = $request->boolean('is_active');

        $user = DB::transaction(function () use ($id, $active) {

            $activeAdministrators = $this->lockActiveAdministrators();

            $user = User::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                (int) $user->id === (int) auth()->id()
                && !$active
            ) {
                abort(
                    409,
                    'Administrator tidak dapat menonaktifkan akunnya sendiri.'
                );
            }

            if (
                !$active
                && $user->role === User::ROLE_ADMINISTRATOR
                && $user->isActive()
                && $activeAdministrators->count() <= 1
            ) {
                abort(
                    409,
                    'Administrator aktif terakhir tidak boleh dinonaktifkan.'
                );
            }

            $user->is_active = $active;
            $user->save();

            if (!$active) {
                $user->tokens()->delete();
            }

            return $user->fresh();
        }, 3);

        return response()->json([
            'success' => true,
            'message' => $active
                ? 'Akun berhasil diaktifkan.'
                : 'Akun berhasil dinonaktifkan dan seluruh token dicabut.',
            'data' => $this->userData($user),
        ]);
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'password' => [
                'required',
                'string',
                'min:12',
                'max:255',
                'confirmed',
            ],
        ]);

        DB::transaction(function () use ($id, $validated) {

            $user = User::query()
                ->lockForUpdate()
                ->findOrFail($id);

            $user->password = $validated['password'];
            $user->save();

            $user->tokens()->delete();

        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'Password berhasil direset. Seluruh token lama telah dicabut.',
        ]);
    }

    private function lockActiveAdministrators()
    {
        return User::query()
            ->where('role', User::ROLE_ADMINISTRATOR)
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    private function normalizeEmail(Request $request): void
    {
        $email = $request->input('email');

        if (is_string($email)) {
            $request->merge([
                'email' => strtolower(trim($email)),
            ]);
        }
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->isActive(),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}