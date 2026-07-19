<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Transport;

/**
 * A tiny outbound HTTP client abstraction.
 *
 * Implementations return null on any failure (connection refused, timeout,
 * TLS error, ...) rather than throwing, so callers can treat a failed
 * fire-and-forget send as a silent no-op.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     */
    public function get(string $url, array $headers = []): ?Response;

    /**
     * @param array<string, string> $headers
     */
    public function post(string $url, string $body, array $headers = []): ?Response;
}
