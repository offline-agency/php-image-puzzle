<?php

declare(strict_types=1);

namespace ImagePuzzle\Release\Http;

/**
 * Serves GET requests for file:// URLs from disk, for Composer repository
 * fixtures that stand in for Packagist in tests.
 */
final class FileHttp implements Http
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null): Response
    {
        if ($method !== 'GET' || !str_starts_with($url, 'file://')) {
            throw new \InvalidArgumentException('FileHttp only reads file:// URLs');
        }
        $contents = @file_get_contents(substr($url, strlen('file://')));

        return $contents === false ? new Response(404, '') : new Response(200, $contents);
    }
}
