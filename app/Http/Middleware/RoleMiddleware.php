<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(
        Request $request,
        Closure $next,
        string ...$roles
    ): Response {

        $user = $request->user();

        // Authentication check.
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Active account check.
        if (!$user->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Akun Anda tidak aktif.',
            ], 403);
        }

        // Reject invalid or missing role configuration.
        if (empty($roles)) {
            return response()->json([
                'success' => false,
                'message' => 'Konfigurasi hak akses tidak valid.',
            ], 500);
        }

        // Verify user's assigned role.
        if (!in_array($user->role, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki izin untuk mengakses fitur ini.',
            ], 403);
        }

        return $next($request);
    }
}
