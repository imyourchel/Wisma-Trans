<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Email harus berformat lengkap (nama@domain.tld) dan bukan salah ketik
 * domain populer, misalnya gmial.com atau gmail.co.
 */
class EmailWajar implements ValidationRule
{
    /** Pola yang sama dipakai JavaScript di formulir daftar. */
    public const PATTERN = '^[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}$';

    public const TYPO_DOMAINS = [
        'gmial.com' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gmal.com' => 'gmail.com',
        'gamil.com' => 'gmail.com', 'gmil.com' => 'gmail.com', 'gnail.com' => 'gmail.com',
        'gmaill.com' => 'gmail.com', 'gmail.co' => 'gmail.com', 'gmail.con' => 'gmail.com',
        'gmail.cm' => 'gmail.com', 'gmail.om' => 'gmail.com', 'gmail.id' => 'gmail.com',
        'yaho.com' => 'yahoo.com', 'yahooo.com' => 'yahoo.com', 'yahoo.con' => 'yahoo.com',
        'yahoo.co' => 'yahoo.com', 'yhoo.com' => 'yahoo.com',
        'hotmial.com' => 'hotmail.com', 'hotmail.co' => 'hotmail.com', 'hotmail.con' => 'hotmail.com',
        'outlok.com' => 'outlook.com', 'outlook.co' => 'outlook.com', 'outlook.con' => 'outlook.com',
    ];

    public function __construct(private string $message = 'Format email tidak valid.') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)
            || preg_match('/'.self::PATTERN.'/', $value) !== 1
            || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $fail($this->message);

            return;
        }

        $domain = substr(strrchr($value, '@'), 1);
        if (isset(self::TYPO_DOMAINS[$domain])) {
            $fail('Mungkin maksud Anda @'.self::TYPO_DOMAINS[$domain].'?');
        }
    }
}
