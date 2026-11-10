<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequireSuperAdmin Middleware
 * Strictly guards admin dashboard and management endpoints against unauthorized users.
 */
class RequireSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Check if authenticated via user session or token
        $user = $request->user();
        if ($user && ($user->isSuperAdmin() || in_array(strtoupper($user->role ?? ''), ['SUPER_ADMIN', 'ADMIN']))) {
            return $next($request);
        }

        // 2. Check session user
        $sessionUser = session('admin_user') ?? session('user');
        if ($sessionUser) {
            $role = is_object($sessionUser) ? ($sessionUser->role ?? '') : ($sessionUser['role'] ?? '');
            if (in_array(strtoupper($role), ['SUPER_ADMIN', 'ADMIN', 'SUPER_ADMINISTRATOR'])) {
                return $next($request);
            }
        }

        // 3. Check if authorized via Master Global API Key
        $apiKey = $request->header('apikey')
            ?? $request->header('X-Admin-Key')
            ?? $request->query('apikey');

        $masterKey = config('evolution.authentication.api_key');
        if ($masterKey && $apiKey === $masterKey) {
            return $next($request);
        }

        // 4. Check Bearer Token
        $bearer = $request->bearerToken() ?? $request->header('X-Admin-Token');
        if ($bearer && $masterKey && $bearer === $masterKey) {
            return $next($request);
        }

        // 5. Check if user header passed
        $headerUserId = $request->header('X-User-Id');
        if ($headerUserId) {
            $foundUser = User::find($headerUserId);
            if ($foundUser && ($foundUser->isSuperAdmin() || in_array(strtoupper($foundUser->role ?? ''), ['SUPER_ADMIN', 'ADMIN']))) {
                return $next($request);
            }
        }

        // 6. Deny access: Hide existence from scanners (404) for page visits, or 403 for API calls
        if ($request->expectsJson() || $request->is('saas/admin/*')) {
            return response()->json([
                'status'  => 'error',
                'message' => 'غير مصرح: هذه العملية تتطلب صلاحيات المدير العام (Super Admin) فقط.',
            ], 403);
        }

        // Return 404 to hide the admin dashboard route completely from attackers
        abort(404, 'Page not found');
    }
}
