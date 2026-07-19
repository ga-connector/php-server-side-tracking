<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Http;

/**
 * Framework-neutral HTTP response value object.
 *
 * Proxy handlers return one of these; the caller either emits it with
 * {@see Response::emit()} (vanilla PHP) or maps it onto their framework's
 * own response type.
 */
final class Response
{
    public int $statusCode;
    /** @var array<string, string> */
    public array $headers;
    public string $body;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $statusCode = 200, array $headers = [], string $body = '')
    {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->body = $body;
    }

    /**
     * A `200 OK` with an empty body — the fire-and-forget event response.
     */
    public static function noContent(): self
    {
        return new self(200, [], '');
    }

    public static function javascript(string $body): self
    {
        return new self(200, ['Content-Type' => 'text/javascript; charset=utf-8', 'Cache-Control' => 'no-store'], $body);
    }

    public static function json(int $statusCode, string $json): self
    {
        return new self($statusCode, ['Content-Type' => 'application/json; charset=utf-8'], $json);
    }

    /**
     * Send status, headers, and body to the client. No-ops the header/status
     * calls if output has already started (still echoes the body).
     */
    public function emit(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $this->body;
    }
}
