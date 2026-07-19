<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Transport;

/**
 * Fallback HTTP client used when cURL is unavailable. Speaks HTTP/1.1 over a
 * raw socket opened with stream_socket_client (TLS via the `tls://`
 * transport for https URLs).
 *
 * Deliberately minimal: one request per connection (`Connection: close`),
 * with chunked-transfer decoding on the response. Returns null on any
 * failure so the caller can treat it as a silent no-op.
 */
final class SocketClient implements HttpClient
{
    private int $connectTimeout;
    private int $timeout;

    public function __construct(int $connectTimeout = 2, int $timeout = 5)
    {
        $this->connectTimeout = $connectTimeout;
        $this->timeout = $timeout;
    }

    public static function isAvailable(): bool
    {
        return function_exists('stream_socket_client') || function_exists('fsockopen');
    }

    public function get(string $url, array $headers = []): ?Response
    {
        return $this->request('GET', $url, null, $headers);
    }

    public function post(string $url, string $body, array $headers = []): ?Response
    {
        return $this->request('POST', $url, $body, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $url, ?string $body, array $headers): ?Response
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $host = $parts['host'];
        $isTls = $scheme === 'https';
        $port = $parts['port'] ?? ($isTls ? 443 : 80);
        $transport = $isTls ? 'tls' : 'tcp';

        $path = $parts['path'] ?? '/';
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        $remote = sprintf('%s://%s:%d', $transport, $host, $port);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            (float) $this->connectTimeout,
            STREAM_CLIENT_CONNECT,
        );
        if ($socket === false) {
            return null;
        }

        stream_set_timeout($socket, $this->timeout);

        $request = $this->buildRequest($method, $host, $path, $body, $headers);
        if (@fwrite($socket, $request) === false) {
            fclose($socket);

            return null;
        }

        $raw = '';
        while (!feof($socket)) {
            $chunk = @fread($socket, 8192);
            if ($chunk === false) {
                break;
            }
            $raw .= $chunk;

            $info = stream_get_meta_data($socket);
            if (!empty($info['timed_out'])) {
                fclose($socket);

                return null;
            }
        }
        fclose($socket);

        return $this->parseResponse($raw);
    }

    /**
     * @param array<string, string> $headers
     */
    private function buildRequest(string $method, string $host, string $path, ?string $body, array $headers): string
    {
        $lines = [];
        $lines[] = sprintf('%s %s HTTP/1.1', $method, $path);
        $lines[] = 'Host: ' . $host;
        $lines[] = 'Connection: close';
        $lines[] = 'Accept-Encoding: identity';

        $hasContentLength = false;
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
            if (strtolower($name) === 'content-length') {
                $hasContentLength = true;
            }
        }

        if ($body !== null && !$hasContentLength) {
            $lines[] = 'Content-Length: ' . strlen($body);
        }

        return implode("\r\n", $lines) . "\r\n\r\n" . ($body ?? '');
    }

    private function parseResponse(string $raw): ?Response
    {
        if ($raw === '') {
            return null;
        }

        $split = preg_split("/\r\n\r\n/", $raw, 2);
        if ($split === false || count($split) < 1) {
            return null;
        }

        $head = $split[0];
        $body = $split[1] ?? '';

        $headerLines = preg_split("/\r\n/", $head) ?: [];
        $statusLine = array_shift($headerLines) ?? '';
        if (!preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $statusLine, $m)) {
            return null;
        }
        $status = (int) $m[1];

        $isChunked = false;
        foreach ($headerLines as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $value = strtolower(trim(substr($line, $pos + 1)));
            if ($name === 'transfer-encoding' && strpos($value, 'chunked') !== false) {
                $isChunked = true;
            }
        }

        if ($isChunked) {
            $body = self::decodeChunked($body);
        }

        return new Response($status, $body);
    }

    private static function decodeChunked(string $body): string
    {
        $decoded = '';
        $offset = 0;
        $length = strlen($body);

        while ($offset < $length) {
            $lineEnd = strpos($body, "\r\n", $offset);
            if ($lineEnd === false) {
                break;
            }

            $sizeHex = trim(substr($body, $offset, $lineEnd - $offset));
            // A chunk-size may carry extensions after a ';'.
            if (($semi = strpos($sizeHex, ';')) !== false) {
                $sizeHex = substr($sizeHex, 0, $semi);
            }
            if ($sizeHex === '' || !ctype_xdigit($sizeHex)) {
                break;
            }
            $size = (int) hexdec($sizeHex);
            $offset = $lineEnd + 2;

            if ($size <= 0) {
                break;
            }

            $decoded .= substr($body, $offset, $size);
            $offset += $size + 2; // skip trailing CRLF
        }

        return $decoded;
    }
}
