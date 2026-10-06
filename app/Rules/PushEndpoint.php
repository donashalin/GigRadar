<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Only accepts https endpoints (no userinfo, port 443 only) hosted by known browser push services. */
class PushEndpoint implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) ? parse_url($value) : false;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        $valid = is_string($host)
            && ! str_ends_with($host, '.')
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ($parts['port'] ?? 443) === 443
            && self::isAllowedHost(strtolower($host));

        if (! $valid) {
            $fail('The :attribute must be a web push endpoint from a supported browser.');
        }
    }

    public static function isAllowedHost(string $host): bool
    {
        foreach ((array) config('webpush.allowed_hosts', []) as $pattern) {
            if (str_starts_with($pattern, '*.')) {
                if (str_ends_with($host, substr($pattern, 1)) && strlen($host) > strlen($pattern) - 1) {
                    return true;
                }
            } elseif ($host === $pattern) {
                return true;
            }
        }

        return false;
    }
}
