<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Only accepts https endpoints hosted by known browser push services. */
class PushEndpoint implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = is_string($value) ? parse_url($value, PHP_URL_HOST) : null;

        if (! is_string($host) || ! self::isAllowedHost(strtolower($host))) {
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
