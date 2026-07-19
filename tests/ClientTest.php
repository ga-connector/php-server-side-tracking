<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Tests;

use GaConnector\Tracking\GaConnector;
use GaConnector\Tracking\Proxy;
use GaConnector\Tracking\Renderer;
use GaConnector\Tracking\Tests\Support\StubTransport;
use GaConnector\Tracking\TrackingApiClient;
use GaConnector\Tracking\Transport\Response;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testExposesConfiguredCollaborators(): void
    {
        $client = GaConnector::create(['apiKey' => 'gac_api_x', 'basePath' => '/gac']);

        self::assertInstanceOf(Renderer::class, $client->renderer());
        self::assertInstanceOf(Proxy::class, $client->proxy());
        self::assertInstanceOf(TrackingApiClient::class, $client->api());
    }

    public function testHtmlDelegatesToRenderer(): void
    {
        $client = GaConnector::create(['apiKey' => 'gac_api_x', 'basePath' => '/gac']);

        self::assertStringContainsString('window.__gacContext', $client->html());
    }

    public function testScriptTagDelegatesToRenderer(): void
    {
        $client = GaConnector::create(['apiKey' => 'gac_api_x', 'basePath' => '/gac']);

        self::assertStringContainsString('/gac/js', $client->scriptTag());
    }

    public function testVerifyAccountDelegatesToApiClient(): void
    {
        $body = '{"account_id":"acc_1","account_name":"Acme","email":"o@e.com","allowed_domains":["example.com"]}';
        $client = GaConnector::create(
            ['apiKey' => 'gac_api_x', 'basePath' => '/gac'],
            new StubTransport(new Response(200, $body))
        );

        self::assertSame('acc_1', $client->verifyAccount('example.com')->accountId);
    }
}
