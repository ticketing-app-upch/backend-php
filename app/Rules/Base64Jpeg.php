<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Base64Jpeg implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! str_starts_with($value, 'data:image/jpeg;base64,') || strlen($value) > 2796230) {
            $fail('La imagen debe ser un JPEG en Base64 de hasta 2 MiB.');

            return;
        }
        $bytes = base64_decode(substr($value, strlen('data:image/jpeg;base64,')), true);
        $size = $bytes === false ? false : @getimagesizefromstring($bytes);
        if ($bytes === false || strlen($bytes) > 2 * 1024 * 1024 || ! $size
            || ($size[2] ?? null) !== IMAGETYPE_JPEG || $size[0] < 1920 || $size[1] < 480
            || $size[0] !== $size[1] * 4) {
            $fail('La imagen debe ser un JPEG válido, mínimo 1920 × 480 y proporción 4:1.');
        }
    }
}
