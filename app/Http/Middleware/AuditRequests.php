<?php

namespace App\Http\Middleware;

use App\Models\RequestAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuditRequests
{
    protected array $ignorePatterns = [
        'auth/callback*',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // If we wish to omit specific routes from auditing, we'll need
        // to label them here. Stubbed for now.
        if ($this->shouldIgnore($request)) {
            return $response;
        }

        // Audit layer
        RequestAudit::create([
            'user_id' => Auth::id(),
            'method' => $request->method(),
            'uri' => $request->getRequestUri(),
            'status' => $response->getStatusCode(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload' => json_encode($this->safePayload($request)),
        ]);

        return $response;
    }

    protected function shouldIgnore(Request $request): bool
    {
        foreach ($this->ignorePatterns as $pattern) {
            if (Str::is($pattern, $request->path())) {
                return true;
            }
        }

        return false;
    }

    protected function safePayload(Request $request): array
    {
        return collect($request->except([
            'password',
            'token',
            // Plus anything else we want to omit from audit layer
        ]))->toArray();
    }
}
