<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Tests;

use GaConnector\Tracking\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testClientIpPrefersFirstForwardedForHop(): void
    {
        $request = new Request(
            'POST',
            'https://example.com/x',
            ['x-forwarded-for' => '203.0.113.9, 10.0.0.1', 'referer' => 'https://g.com/', 'user-agent' => 'UA/1'],
            [],
            '{"visitor_id":"v1","page_url":"https://example.com/p"}',
            '10.1.1.1'
        );

        self::assertSame('203.0.113.9', $request->clientIp());
    }

    public function testReadsReferrerAndUserAgent(): void
    {
        $request = new Request(
            'POST',
            'https://example.com/x',
            ['referer' => 'https://g.com/', 'user-agent' => 'UA/1'],
            [],
            '',
            ''
        );

        self::assertSame('https://g.com/', $request->referrer());
        self::assertSame('UA/1', $request->userAgent());
    }

    public function testJsonBodyDecodesJsonObject(): void
    {
        $request = new Request(
            'POST',
            'https://example.com/x',
            [],
            [],
            '{"visitor_id":"v1","page_url":"https://example.com/p"}',
            ''
        );

        self::assertSame('v1', $request->jsonBody()['visitor_id']);
    }

    public function testClientIpFallsBackToRealIpHeader(): void
    {
        $request = new Request('GET', 'https://e.com/', ['x-real-ip' => ' 198.51.100.5 '], [], '', '10.1.1.1');

        self::assertSame('198.51.100.5', $request->clientIp());
    }

    public function testClientIpFallsBackToPublicRemoteAddr(): void
    {
        $request = new Request('GET', 'https://e.com/', [], [], '', '203.0.113.77');

        self::assertSame('203.0.113.77', $request->clientIp());
    }

    public function testClientIpDefaultsToPublicIpWhenUnknown(): void
    {
        $request = new Request('GET', 'https://e.com/', [], [], '', '');

        self::assertSame('91.90.13.89', $request->clientIp());
    }

    /**
     * @dataProvider nonRoutableProvider
     */
    public function testClientIpReplacesNonRoutableRequestAddressWithDefault(string $address): void
    {
        $request = new Request('GET', 'https://e.com/', [], [], '', $address);

        self::assertSame('91.90.13.89', $request->clientIp());
    }

    /**
     * Loopback, private LAN, link-local and other reserved ranges that must
     * be treated as "no real client IP" and replaced with the default.
     *
     * @return array<string, array{string}>
     */
    public function nonRoutableProvider(): array
    {
        return [
            'loopback ipv4' => ['127.0.0.1'],
            'loopback ipv6' => ['::1'],
            'loopback ipv4-mapped' => ['::ffff:127.0.0.1'],
            'private 10/8' => ['10.1.1.1'],
            'private 172.16/12' => ['172.16.5.9'],
            'private 192.168/16' => ['192.168.1.100'],
            'private ipv4-mapped' => ['::ffff:192.168.0.1'],
            'link-local 169.254/16' => ['169.254.10.10'],
            'ipv6 ula fc00::/7' => ['fd00::1'],
            'not an ip' => ['not-an-ip'],
        ];
    }

    public function testClientIpDoesNotSubstituteNonRoutableFromHeader(): void
    {
        // The default-IP substitution keys off the request address only, so a
        // header value is returned as-is even if it is a private/loopback IP.
        $request = new Request('GET', 'https://e.com/', ['x-real-ip' => '192.168.1.10'], [], '', '203.0.113.5');

        self::assertSame('192.168.1.10', $request->clientIp());
    }

    public function testClientIpPrefersCdnHeaderOverForwardedFor(): void
    {
        $request = new Request(
            'GET',
            'https://e.com/',
            ['cf-connecting-ip' => '203.0.113.7', 'x-forwarded-for' => '198.51.100.9'],
            [],
            '',
            '10.1.1.1'
        );

        self::assertSame('203.0.113.7', $request->clientIp());
    }

    /**
     * @dataProvider ipHeaderProvider
     */
    public function testClientIpReadsEachSupportedHeader(string $header, string $value, string $expected): void
    {
        $request = new Request('GET', 'https://e.com/', [$header => $value], [], '', '');

        self::assertSame($expected, $request->clientIp());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public function ipHeaderProvider(): array
    {
        return [
            'true-client-ip' => ['true-client-ip', '203.0.113.1', '203.0.113.1'],
            'fastly-client-ip' => ['fastly-client-ip', '203.0.113.2', '203.0.113.2'],
            'fly-client-ip' => ['fly-client-ip', '203.0.113.3', '203.0.113.3'],
            'x-envoy-external-address' => ['x-envoy-external-address', '203.0.113.4', '203.0.113.4'],
            'client-ip' => ['client-ip', '203.0.113.5', '203.0.113.5'],
            'x-client-ip' => ['x-client-ip', '203.0.113.6', '203.0.113.6'],
            'x-cluster-client-ip' => ['x-cluster-client-ip', '203.0.113.8', '203.0.113.8'],
            'x-appengine-user-ip' => ['x-appengine-user-ip', '203.0.113.10', '203.0.113.10'],
        ];
    }

    public function testClientIpParsesForwardedIpv4(): void
    {
        $request = new Request(
            'GET',
            'https://e.com/',
            ['forwarded' => 'for=192.0.2.60;proto=http;by=203.0.113.43, for=198.51.100.17'],
            [],
            '',
            ''
        );

        self::assertSame('192.0.2.60', $request->clientIp());
    }

    public function testClientIpParsesForwardedIpv4WithPort(): void
    {
        $request = new Request('GET', 'https://e.com/', ['forwarded' => 'for=192.0.2.60:47011'], [], '', '');

        self::assertSame('192.0.2.60', $request->clientIp());
    }

    public function testClientIpParsesForwardedBracketedIpv6(): void
    {
        $request = new Request('GET', 'https://e.com/', ['forwarded' => 'for="[2001:db8::1]:443"'], [], '', '');

        self::assertSame('2001:db8::1', $request->clientIp());
    }

    public function testClientIpSkipsInvalidHeaderValueAndFallsThrough(): void
    {
        $request = new Request(
            'GET',
            'https://e.com/',
            ['cf-connecting-ip' => 'not-an-ip', 'x-real-ip' => '198.51.100.23'],
            [],
            '',
            '10.1.1.1'
        );

        self::assertSame('198.51.100.23', $request->clientIp());
    }

    public function testClientIpAcceptsBareIpv6FromHeader(): void
    {
        $request = new Request('GET', 'https://e.com/', ['x-real-ip' => '2001:db8::42'], [], '', '');

        self::assertSame('2001:db8::42', $request->clientIp());
    }
}
