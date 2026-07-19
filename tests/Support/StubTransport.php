<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Tests\Support;

use GaConnector\Tracking\Transport\HttpClient;
use GaConnector\Tracking\Transport\Response;

/**
 * In-memory HTTP client for tests: records every call and returns queued
 * responses in order (a null in the queue simulates a failed request).
 */
final class StubTransport implements HttpClient
{
    /** @var list<array{method: string, url: string, body: ?string, headers: array<string, string>}> */
    public array $calls = [];

    /** @var list<?Response> */
    private array $queue;

    public function __construct(?Response ...$responses)
    {
        $this->queue = $responses;
    }

    public function get(string $url, array $headers = []): ?Response
    {
        $this->calls[] = ['method' => 'GET', 'url' => $url, 'body' => null, 'headers' => $headers];

        return $this->next();
    }

    public function post(string $url, string $body, array $headers = []): ?Response
    {
        $this->calls[] = ['method' => 'POST', 'url' => $url, 'body' => $body, 'headers' => $headers];

        return $this->next();
    }

    private function next(): ?Response
    {
        return array_shift($this->queue);
    }
}
