<?php

namespace App\Support;

use RuntimeException;

final class PublicUrl
{
    public static function to(string $path): string
    {
        $base = rtrim((string) config('app.url'), '/');

        if (! filter_var($base, FILTER_VALIDATE_URL) || ! in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException('APP_URL must be an absolute HTTP or HTTPS URL.');
        }

        return $base.'/'.ltrim($path, '/');
    }
}
