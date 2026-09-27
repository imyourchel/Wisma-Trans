<?php

namespace App\Rules;

use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Nilai diharapkan sudah dinormalisasi dengan Phone::normalize(). */
class NomorHp implements ValidationRule
{
    public function __construct(private string $message = 'Nomor tidak valid, contoh 0812xxxxxxxx.') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Phone::isValidMobile($value)) {
            $fail($this->message);
        }
    }
}
