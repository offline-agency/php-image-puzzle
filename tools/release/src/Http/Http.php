<?php

declare(strict_types=1);

namespace ImagePuzzle\Release\Http;

interface Http
{
    /**
     * @param list<string> $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): Response;
}
