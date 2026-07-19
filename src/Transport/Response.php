<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Transport;

/**
 * Minimal result of an outbound HTTP call made by an {@see HttpClient}.
 */
final class Response
{
    public int $status;
    public string $body;

    public function __construct(int $status, string $body)
    {
        $this->status = $status;
        $this->body = $body;
    }
}
