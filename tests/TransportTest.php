<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Tests;

use GaConnector\Tracking\Transport\CurlClient;
use GaConnector\Tracking\Transport\HttpClient;
use GaConnector\Tracking\Transport\HttpClientFactory;
use GaConnector\Tracking\Transport\SocketClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TransportTest extends TestCase
{
    public function testSocketClientParsesStatusAndBody(): void
    {
        $parse = $this->socketMethod('parseResponse');
        $raw = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 13\r\n\r\n{\"ok\":\"yes\"}\n";

        $parsed = $parse->invoke(new SocketClient(), $raw);

        self::assertSame(200, $parsed->status);
        self::assertSame("{\"ok\":\"yes\"}\n", $parsed->body);
    }

    public function testSocketClientDecodesChunkedBody(): void
    {
        $parse = $this->socketMethod('parseResponse');
        $raw = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nHello\r\n6\r\n World\r\n0\r\n\r\n";

        $parsed = $parse->invoke(new SocketClient(), $raw);

        self::assertSame('Hello World', $parsed->body);
    }

    public function testSocketClientReturnsNullOnMalformedResponse(): void
    {
        $parse = $this->socketMethod('parseResponse');

        self::assertNull($parse->invoke(new SocketClient(), 'garbage-no-status-line'));
    }

    public function testAvailabilityReflectsHostCapabilities(): void
    {
        self::assertSame(function_exists('curl_init'), CurlClient::isAvailable());
        self::assertSame(
            function_exists('stream_socket_client') || function_exists('fsockopen'),
            SocketClient::isAvailable()
        );
    }

    public function testFactoryDetectsAnHttpClient(): void
    {
        self::assertInstanceOf(HttpClient::class, HttpClientFactory::detect());
    }

    public function testCurlClientReturnsTheBodyOfACompletedRequest(): void
    {
        $this->requireCurl();

        $file = tempnam(sys_get_temp_dir(), 'gac');
        self::assertIsString($file);
        file_put_contents($file, 'hello');

        try {
            // A file:// URL completes without a network, so the request
            // reaches the end of the success path.
            $response = (new CurlClient())->get('file://' . $file);
        } finally {
            unlink($file);
        }

        self::assertNotNull($response);
        self::assertSame('hello', $response->body);
    }

    public function testCurlClientReturnsNullWhenTheRequestFails(): void
    {
        $this->requireCurl();

        // Nothing listens on port 1, so the connection fails straight away.
        self::assertNull((new CurlClient(1, 1))->get('http://127.0.0.1:1/'));
    }

    private function requireCurl(): void
    {
        if (!CurlClient::isAvailable()) {
            self::markTestSkipped('The cURL extension is not loaded.');
        }
    }

    private function socketMethod(string $name): \ReflectionMethod
    {
        $method = (new ReflectionClass(SocketClient::class))->getMethod($name);
        // Needed before PHP 8.1, and deprecated since PHP 8.5.
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return $method;
    }
}
