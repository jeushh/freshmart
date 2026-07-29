<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = $request->user();
        abort_unless($user, 401);
        $allowed = array_values(array_filter(explode('|', $permission)));
        abort_unless($user->hasAnyPermission(...$allowed), 403, 'Permission denied.');

        return $next($request);
    }
}
