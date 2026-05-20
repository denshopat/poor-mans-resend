<?php
declare(strict_types=1);

/**
 * Returns the source_app name if the Authorization header carries a known
 * bearer token, or null otherwise. Uses hash_equals for constant-time
 * comparison so an attacker cannot infer keys from response timing.
 */
function auth_resolve_source_app(string $authHeader, array $apiKeys): ?string
{
    if (!str_starts_with($authHeader, 'Bearer ')) {
        return null;
    }
    $token = substr($authHeader, 7);
    if ($token === '') {
        return null;
    }
    foreach ($apiKeys as $key => $sourceApp) {
        if (hash_equals((string)$key, $token)) {
            return $sourceApp;
        }
    }
    return null;
}
