<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Models\User;

final class UserController
{
    public function index(Request $request, array $params = []): Response
    {
        return Response::success(array_map([$this, 'format'], User::allSafe()));
    }

    public function show(Request $request, array $params = []): Response
    {
        $user = User::find((int) ($params['id'] ?? 0));
        if (!$user) {
            throw new ApiException('NOT_FOUND', 'El usuario no existe.', 404);
        }

        return Response::success($this->format($user));
    }

    public function store(Request $request, array $params = []): Response
    {
        $data = Validator::validate($request->body(), [
            'name' => 'required|string|minlen:2|maxlen:120',
            'email' => 'required|string|email|maxlen:190',
            'role' => 'required|in:admin,editor',
            'password' => 'required|string|minlen:10|maxlen:120',
            'is_active' => 'nullable|boolean',
        ]);

        if (User::emailExists((string) $data['email'])) {
            throw new ValidationException(['email' => 'Ese correo ya está registrado.']);
        }

        $id = User::createUser([
            'name' => trim((string) $data['name']),
            'email' => mb_strtolower(trim((string) $data['email'])),
            'password_hash' => password_hash((string) $data['password'], PASSWORD_DEFAULT),
            'role' => (string) $data['role'],
            'is_active' => (int) (bool) ($data['is_active'] ?? true),
            'must_change_password' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        audit('user.created', 'users', $id, ['role' => $data['role']]);

        return Response::success($this->format(User::find($id)), [], 201);
    }

    public function update(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $user = User::find($id);
        if (!$user) {
            throw new ApiException('NOT_FOUND', 'El usuario no existe.', 404);
        }

        $data = Validator::validate($request->body(), [
            'name' => 'nullable|string|minlen:2|maxlen:120',
            'email' => 'nullable|string|email|maxlen:190',
            'role' => 'nullable|in:admin,editor',
            'password' => 'nullable|string|minlen:10|maxlen:120',
            'is_active' => 'nullable|boolean',
        ]);

        if (isset($data['email']) && User::emailExists((string) $data['email'], $id)) {
            throw new ValidationException(['email' => 'Ese correo ya está registrado.']);
        }

        if ($id === Auth::id() && array_key_exists('is_active', $data) && !$data['is_active']) {
            throw new ApiException('CANNOT_DEACTIVATE_SELF', 'No puedes desactivar tu propio usuario.', 422);
        }

        $willBeAdmin = array_key_exists('role', $data) ? $data['role'] === 'admin' : $user['role'] === 'admin';
        $willBeActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (int) $user['is_active'] === 1;

        if ($user['role'] === 'admin' && (!$willBeAdmin || !$willBeActive) && User::countAdmins() <= 1) {
            throw new ApiException(
                'LAST_ADMIN',
                'Debe existir al menos un administrador activo.',
                422,
            );
        }

        $payload = ['updated_at' => now()];
        if (array_key_exists('name', $data)) {
            $payload['name'] = trim((string) $data['name']);
        }
        if (array_key_exists('email', $data)) {
            $payload['email'] = mb_strtolower(trim((string) $data['email']));
        }
        if (array_key_exists('role', $data)) {
            $payload['role'] = (string) $data['role'];
        }
        if (array_key_exists('is_active', $data)) {
            $payload['is_active'] = (int) (bool) $data['is_active'];
        }
        if (!empty($data['password'])) {
            $payload['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
            $payload['must_change_password'] = $id === Auth::id() ? 0 : 1;
        }

        User::update($id, $payload);
        audit('user.updated', 'users', $id, ['role' => $payload['role'] ?? $user['role']]);

        return Response::success($this->format(User::find($id)));
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $user = User::find($id);
        if (!$user) {
            throw new ApiException('NOT_FOUND', 'El usuario no existe.', 404);
        }

        if ($id === Auth::id()) {
            throw new ApiException('CANNOT_DEACTIVATE_SELF', 'No puedes desactivar tu propio usuario.', 422);
        }

        if ($user['role'] === 'admin' && User::countAdmins() <= 1) {
            throw new ApiException('LAST_ADMIN', 'Debe existir al menos un administrador activo.', 422);
        }

        User::update($id, ['is_active' => 0, 'updated_at' => now()]);
        audit('user.deactivated', 'users', $id);

        return Response::noContent();
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    private function format(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'is_active' => (int) ($user['is_active'] ?? 1) === 1,
            'must_change_password' => (int) ($user['must_change_password'] ?? 0) === 1,
            'last_login_at' => $user['last_login_at'] ?? null,
            'created_at' => $user['created_at'] ?? null,
        ];
    }
}
