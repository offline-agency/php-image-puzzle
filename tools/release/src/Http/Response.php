<?php

declare(strict_types=1);

namespace ImagePuzzle\Release\Http;

final readonly class Response
{
    /**
     * @param array<string, string> $headers lower-cased names
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function json(): array
    {
        $data = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }
}
