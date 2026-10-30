<?php

declare(strict_types=1);

namespace ImagePuzzle\Release\Http;

/** HTTPS client on ext-curl; the caller supplies authorization headers and never logs them. */
final class CurlHttp implements Http
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null): Response
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException('only https URLs are requested');
        }
        $curl = curl_init($url);
        $received = [];
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method === '' ? 'GET' : $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [...$headers, 'User-Agent: image-puzzle-release'],
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $curl, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($curl);
        if ($result === false) {
            throw new \RuntimeException("request to {$url} failed: " . curl_error($curl));
        }

        return new Response((int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), is_string($result) ? $result : '', $received);
    }
}
