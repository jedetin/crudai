<?php

declare(strict_types=1);

/**
 * Thin wrapper around the current HTTP request.
 *
 * Path examples this needs to handle:
 *   GET  /users
 *   GET  /users/912
 *   GET  /users/912/bookings
 *   GET  /users/912/bookings?status=confirmed
 *   POST /users
 *   PUT  /users/912
 *   DELETE /users/912
 */
class Request
{
    public readonly string $method;
    public readonly array  $segments;   // ['users', '912', 'bookings']
    public readonly array  $query;      // ['status' => 'confirmed']
    public readonly array  $body;       // decoded JSON body

    public function __construct()
    {
        $this->method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->segments = $this->parseSegments();
        $this->query    = $_GET;
        $this->body     = $this->parseBody();
    }

    // Convenience ---------------------------------------------------------------

    public function resource(): string
    {
        return $this->segments[0] ?? '';
    }

    public function id(): int|string|null
    {
        $raw = $this->segments[1] ?? null;
        return $raw !== null ? (ctype_digit($raw) ? (int)$raw : $raw) : null;
    }

    /** Returns the nested resource name, e.g. 'bookings' in /users/912/bookings */
    public function nested(): ?string
    {
        return $this->segments[2] ?? null;
    }

    public function isCollection(): bool
    {
        return $this->id() === null;
    }

    public function queryParam(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function bodyParam(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    // Internals -----------------------------------------------------------------

    private function parseSegments(): array
    {
        $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri  = str_replace('\\', '/', $uri); // normalise Windows back-slashes
        $uri  = '/' . trim($uri, '/');

        // Derive the subdirectory prefix from SCRIPT_NAME
        // e.g. SCRIPT_NAME = /crudai/api/index.php  → prefix = /crudai/api
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $prefix = rtrim(dirname($script), '/');  // /crudai/api

        // Strip the prefix so /crudai/api/users/1 → /users/1
        if ($prefix !== '' && $prefix !== '/' && str_starts_with($uri, $prefix)) {
            $uri = substr($uri, strlen($prefix));
        }

        $uri = trim($uri, '/');
        return $uri !== '' ? explode('/', $uri) : [];
    }

    private function parseBody(): array
    {
        if (!in_array($this->method, ['POST', 'PUT', 'PATCH'], true)) {
            return [];
        }
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}