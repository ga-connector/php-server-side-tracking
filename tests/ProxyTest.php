<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Tests;

use GaConnector\Tracking\Config;
use GaConnector\Tracking\Http\Request;
use GaConnector\Tracking\Proxy;
use GaConnector\Tracking\Tests\Support\StubTransport;
use GaConnector\Tracking\TrackingApiClient;
use GaConnector\Tracking\Transport\Response;
use PHPUnit\Framework\TestCase;

final class ProxyTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = Config::fromArray(['apiKey' => 'gac_api_acc_secret', 'basePath' => '/gac']);
    }

    public function testHandleJsSubstitutesPlaceholdersAndSetsContentType(): void
    {
        $script = "var P='{{PAGEVIEW_URL}}';var I='{{IDENTIFY_URL}}';";
        $proxy = new Proxy($this->config, new TrackingApiClient($this->config, new StubTransport(new Response(200, $script))));

        $response = $proxy->handleJs(new Request('GET', 'https://e.com/gac/js'));

        self::assertStringContainsString("var P='/gac/events/pageview';", $response->body);
        self::assertStringContainsString("var I='/gac/events/identify';", $response->body);
        self::assertStringNotContainsString('{{PAGEVIEW_URL}}', $response->body);
        self::assertSame('text/javascript; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testHandleJsDegradesToEmptyBodyWhenScriptUnavailable(): void
    {
        $proxy = new Proxy($this->config, new TrackingApiClient($this->config, new StubTransport(null)));

        self::assertSame('', $proxy->handleJs(new Request('GET', 'https://e.com/gac/js'))->body);
    }

    public function testHandlePageviewForwardsEnrichedBodyWithBearerKey(): void
    {
        $stub = new StubTransport(new Response(200, ''));
        $proxy = new Proxy($this->config, new TrackingApiClient($this->config, $stub));
        $request = new Request(
            'POST',
            'https://e.com/gac/events/pageview',
            ['x-forwarded-for' => '203.0.113.9'],
            [],
            '{"visitor_id":"v1","page_url":"https://e.com/p","ip":"1.1.1.1"}',
            '10.0.0.1'
        );

        $response = $proxy->handlePageview($request);

        self::assertSame(200, $response->statusCode);
        self::assertSame('', $response->body);

        $sent = json_decode($stub->calls[0]['body'], true);
        self::assertSame('203.0.113.9', $sent['ip']);
        self::assertSame('v1', $sent['visitor_id']);
        self::assertSame('https://e.com/p', $sent['page_url']);
        self::assertSame('Bearer gac_api_acc_secret', $stub->calls[0]['headers']['Authorization']);
        self::assertSame('https://track.gaconnector.com/api/v1/events/pageview', $stub->calls[0]['url']);
    }

    public function testHandleIdentifyForwardsBodyUnchanged(): void
    {
        $stub = new StubTransport(new Response(200, ''));
        $proxy = new Proxy($this->config, new TrackingApiClient($this->config, $stub));
        $body = '{"visitor_id":"v1","identifier":{"name":"email","value":"9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08"}}';

        $proxy->handleIdentify(new Request('POST', 'https://e.com/gac/events/identify', [], [], $body));

        self::assertSame($body, $stub->calls[0]['body']);
        self::assertSame('https://track.gaconnector.com/api/v1/events/identify', $stub->calls[0]['url']);
    }

    public function testResolvesKnownRoutes(): void
    {
        $proxy = new Proxy($this->config, new TrackingApiClient($this->config, new StubTransport()));

        self::assertSame(Proxy::ROUTE_PAGEVIEW, $proxy->resolveRoute(new Request('POST', 'https://e.com/gac/events/pageview')));
        self::assertSame(Proxy::ROUTE_IDENTIFY, $proxy->resolveRoute(new Request('POST', 'https://e.com/gac/events/identify')));
        self::assertSame(Proxy::ROUTE_JS, $proxy->resolveRoute(new Request('GET', 'https://e.com/gac/js')));
        self::assertNull($proxy->resolveRoute(new Request('GET', 'https://e.com/other')));
    }

    public function testHandleReturns404ForUnknownRoute(): void
    {
        $proxy = new Proxy($this->config, new TrackingApiClient($this->config, new StubTransport()));

        self::assertSame(404, $proxy->handle(new Request('GET', 'https://e.com/other'))->statusCode);
    }
}
