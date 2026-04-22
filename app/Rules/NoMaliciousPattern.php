<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NoMaliciousPattern implements ValidationRule
{
    public const ERROR_TOKEN = 'UNSAFE_INPUT_DETECTED';

    /**
     * @var array<int, string>
     */
    private array $patterns = [
        '/<\s*script\b/i',
        '/<\s*iframe\b/i',
        '/javascript\s*:/i',
        '/on\w+\s*=/i',
        '/union\s+select/i',
        '/\b(or|and)\b\s+\d+\s*=\s*\d+/i',
        '/;\s*drop\s+table/i',
        '/--\s*$/m',
    ];

    /**
     * @param  mixed  $value
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }

        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                $fail(self::ERROR_TOKEN);
                return;
            }
        }
    }
}
