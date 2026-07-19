<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Tests;

use GaConnector\Tracking\Client;
use GaConnector\Tracking\Exception\NotConfiguredException;
use GaConnector\Tracking\GaConnector;
use GaConnector\Tracking\Tests\Support\StubTransport;
use GaConnector\Tracking\Transport\Response;
use PHPUnit\Framework\TestCase;

final class GaConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        GaConnector::reset();
    }

    protected function tearDown(): void
    {
        GaConnector::reset();
    }

    public function testCreateReturnsClientWithoutStoringIt(): void
    {
        $client = GaConnector::create(['apiKey' => 'gac_api_x', 'basePath' => '/gac']);

        self::assertInstanceOf(Client::class, $client);
        self::assertFalse(GaConnector::isConfigured());
    }

    public function testIsNotConfiguredInitially(): void
    {
        self::assertFalse(GaConnector::isConfigured());
    }

    public function testInstanceThrowsBeforeConfigure(): void
    {
        $this->expectException(NotConfiguredException::class);
        GaConnector::instance();
    }

    public function testPassthroughThrowsBeforeConfigure(): void
    {
        $this->expectException(NotConfiguredException::class);
        GaConnector::html();
    }

    public function testConfigureStoresSharedInstance(): void
    {
        $returned = GaConnector::configure(['apiKey' => 'gac_api_x', 'basePath' => '/gac']);

        self::assertTrue(GaConnector::isConfigured());
        self::assertInstanceOf(Client::class, $returned);
        self::assertSame($returned, GaConnector::instance());
    }

    public function testUseRegistersPrebuiltInstance(): void
    {
        $client = GaConnector::create(['apiKey' => 'gac_api_x', 'basePath' => '/gac']);

        GaConnector::use($client);

        self::assertSame($client, GaConnector::instance());
    }

    public function testHtmlDelegatesToSharedInstance(): void
    {
        GaConnector::configure(['apiKey' => 'gac_api_x', 'basePath' => '/gac']);

        self::assertStringContainsString('window.__gacContext', GaConnector::html());
    }

    public function testVerifyAccountDelegatesToSharedInstance(): void
    {
        $body = '{"account_id":"acc_1","account_name":"Acme","email":"o@e.com","allowed_domains":["example.com"]}';
        GaConnector::configure(
            ['apiKey' => 'gac_api_x', 'basePath' => '/gac'],
            new StubTransport(new Response(200, $body))
        );

        self::assertSame('acc_1', GaConnector::verifyAccount('example.com')->accountId);
    }

    public function testResetClearsSharedInstance(): void
    {
        GaConnector::configure(['apiKey' => 'gac_api_x', 'basePath' => '/gac']);
        self::assertTrue(GaConnector::isConfigured());

        GaConnector::reset();

        self::assertFalse(GaConnector::isConfigured());
    }
}
