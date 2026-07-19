<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Exception;

use RuntimeException;

/**
 * Thrown by operations that require a response from the tracking API
 * (currently account verification) when neither cURL nor a socket
 * transport is available on the host.
 *
 * Fire-and-forget event sends do not throw this — they silently no-op
 * instead, matching the fire-and-forget contract.
 */
final class NoHttpTransportException extends RuntimeException implements ExceptionInterface
{
}
