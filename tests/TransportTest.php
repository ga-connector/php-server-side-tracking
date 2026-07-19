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

    private function socketMethod(string $name): \ReflectionMethod
    {
        $method = (new ReflectionClass(SocketClient::class))->getMethod($name);
        $method->setAccessible(true);

        return $method;
    }
}
