<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Server-side gate: the signed-in account must be active and of an allowed type (e.g. account:admin,practitioner). */
class EnsureAccount
{
    public function handle(Request $request, Closure $next, string ...$types)
    {
        $user = $request->user();

        // Disabled users lose access immediately, even with an unexpired token.
        if (! $user || ! $user->isActive()) {
            $user?->tokens()->delete();
            throw new HttpException(401, 'Unauthenticated.');
        }
        if ($types && ! in_array($user->account_type, $types, true)) {
            throw new HttpException(403, 'Forbidden.');
        }
        if ($user->isStaff() && ! $user->mfa_enrolled_at) {
            throw new HttpException(403, 'Multi-factor authentication is required.');
        }

        return $next($request);
    }
}
