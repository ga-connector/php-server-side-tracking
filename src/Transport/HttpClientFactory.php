<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Transport;

/**
 * Picks the best available HTTP client at runtime:
 *
 *   1. cURL, if the extension is loaded.
 *   2. Raw sockets, if stream_socket_client / fsockopen exist.
 *   3. Otherwise null — the caller decides whether that is a silent no-op
 *      (fire-and-forget events) or an error (account verification).
 */
final class HttpClientFactory
{
    public static function detect(): ?HttpClient
    {
        if (CurlClient::isAvailable()) {
            return new CurlClient();
        }

        if (SocketClient::isAvailable()) {
            return new SocketClient();
        }

        return null;
    }
}
