<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckApiKeyScope
{
    public function handle(Request $request, Closure $next, string ...$requiredScopes)
    {
        if (! $request->attributes->get('api_key_authed')) {
            return response()->json(['error' => 'Unauthorized: API key required.'], 401);
        }

        $keyScopes = $request->attributes->get('api_key_scopes'); // null = 無限制

        if ($keyScopes !== null) {
            foreach ($requiredScopes as $scope) {
                if (! \in_array($scope, $keyScopes)) {
                    return response()->json(['error' => "Forbidden: scope '{$scope}' required."], 403);
                }
            }
        }

        return $next($request);
    }
}
