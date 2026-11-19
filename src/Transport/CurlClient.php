<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Transport;

/**
 * Preferred HTTP client: uses the cURL extension when it is loaded.
 */
final class CurlClient implements HttpClient
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
        return function_exists('curl_init');
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
        $handle = curl_init();
        if ($handle === false) {
            return null;
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt($handle, CURLOPT_URL, $url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($handle, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headerLines);

        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body ?? '');
        } elseif ($method !== 'GET') {
            curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== null) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
            }
        }

        $result = curl_exec($handle);
        if ($result === false) {
            self::close($handle);

            return null;
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        self::close($handle);

        return new Response($status, is_string($result) ? $result : '');
    }

    /**
     * Close the handle on PHP 7.4, the only supported version where
     * curl_close() still does anything. Since PHP 8.0 the handle is an object
     * that is freed when it goes out of scope, and PHP 8.5 deprecates the call.
     *
     * @param resource|\CurlHandle $handle
     */
    private static function close($handle): void
    {
        if (PHP_VERSION_ID < 80000) {
            curl_close($handle);
        }
    }
}
