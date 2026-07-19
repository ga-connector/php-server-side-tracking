<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown by the account-verification client when the tracking API rejects
 * the request. Carries the HTTP status so callers can distinguish:
 *
 *   - 401 — API key missing/malformed, or the secret does not match.
 *   - 403 — the account's subscription has lapsed beyond the grace window.
 *   - 404 — the key is well-formed but references an unknown account.
 *   - 400 — validation failed (issues available only when debug is on).
 *
 * `getIssues()` returns the `ValidationError.issues` array when the API
 * returned one (400 responses with `gac-debug: true`), otherwise null.
 */
final class AccountVerificationException extends RuntimeException implements ExceptionInterface
{
    private int $status;

    /** @var array<int, mixed>|null */
    private ?array $issues;

    /**
     * @param array<int, mixed>|null $issues
     */
    public function __construct(
        int $status,
        string $message,
        ?array $issues = null,
        ?Throwable $previous = null
    ) {
        $this->status = $status;
        $this->issues = $issues;
        parent::__construct($message, $status, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array<int, mixed>|null
     */
    public function getIssues(): ?array
    {
        return $this->issues;
    }
}
