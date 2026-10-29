<?php

namespace App\Http\Middleware;

use App\Models\IntegrationEndpoint;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Partner systems authenticate with a scoped API key, never a user token. Every failure looks the same. */
class AuthenticateIntegration
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->bearerToken();
        $endpoint = $key ? IntegrationEndpoint::where('api_key_hash', hash('sha256', $key))->first() : null;

        if (! $endpoint || $endpoint->status !== 'active'
            || ($endpoint->key_expires_at && $endpoint->key_expires_at->isPast())
            || $endpoint->facility?->verification_status !== 'verified') {
            throw new HttpException(401, 'Unauthenticated.');
        }

        $request->attributes->set('integration', $endpoint);

        return $next($request);
    }
}
