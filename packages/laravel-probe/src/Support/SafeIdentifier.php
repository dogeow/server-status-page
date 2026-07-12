<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Support;

final class SafeIdentifier
{
    public static function make(string $value, string $fallbackPrefix = 'item'): string
    {
        $value = trim($value);

        if ($value !== '' && strlen($value) <= 128 && preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
            return $value;
        }

        return $fallbackPrefix.'_'.substr(hash('sha256', $value), 0, 16);
    }
}
