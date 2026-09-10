<?php

namespace App\Services\Support;

class PhoneNumberNormalizer
{
    public function normalize(string $value): string
    {
        $value = preg_replace('/^whatsapp:/i', '', trim($value)) ?? '';
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            return '+256'.substr($digits, 1);
        }

        if (str_starts_with($digits, '256')) {
            return '+'.$digits;
        }

        return $digits === '' ? '' : '+'.$digits;
    }

    /** @return array<int, string> */
    public function databaseCandidates(string $value): array
    {
        $normalized = $this->normalize($value);
        $digits = ltrim($normalized, '+');
        $candidates = [$normalized, $digits];

        if (str_starts_with($digits, '256') && strlen($digits) === 12) {
            $candidates[] = '0'.substr($digits, 3);
        }

        return array_values(array_unique(array_filter($candidates)));
    }
}
