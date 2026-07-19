<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Tests;

use GaConnector\Tracking\Config;
use GaConnector\Tracking\Exception\AccountVerificationException;
use GaConnector\Tracking\Exception\NoHttpTransportException;
use GaConnector\Tracking\Tests\Support\StubTransport;
use GaConnector\Tracking\TrackingApiClient;
use GaConnector\Tracking\Transport\Response;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TrackingApiClientTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = Config::fromArray(['apiKey' => 'gac_api_acc_secret', 'basePath' => '/gac']);
    }

    public function testVerifyAccountDecodesSuccessfulResponse(): void
    {
        $body = '{"account_id":"acc_1","account_name":"Acme","email":"o@e.com","allowed_domains":["example.com"]}';
        $client = new TrackingApiClient($this->config, new StubTransport(new Response(200, $body)));

        $account = $client->verifyAccount('example.com');

        self::assertSame('acc_1', $account->accountId);
        self::assertSame('Acme', $account->accountName);
        self::assertSame('o@e.com', $account->email);
        self::assertSame(['example.com'], $account->allowedDomains);
        self::assertTrue($account->allows('example.com'));
        self::assertFalse($account->allows('other.com'));
    }

    /**
     * @dataProvider errorStatusProvider
     */
    public function testVerifyAccountMapsErrorStatusesToException(int $status): void
    {
        $client = new TrackingApiClient($this->config, new StubTransport(new Response($status, '')));

        $this->expectException(AccountVerificationException::class);
        $client->verifyAccount('e.com');
    }

    /**
     * @return list<array{int}>
     */
    public function errorStatusProvider(): array
    {
        return [[401], [403], [404]];
    }

    public function testAccountVerificationExceptionCarriesStatus(): void
    {
        $client = new TrackingApiClient($this->config, new StubTransport(new Response(403, '')));

        try {
            $client->verifyAccount('e.com');
            self::fail('Expected AccountVerificationException');
        } catch (AccountVerificationException $e) {
            self::assertSame(403, $e->getStatus());
        }
    }

    public function testSurfacesValidationIssuesInDebugMode(): void
    {
        $debugConfig = Config::fromArray(['apiKey' => 'k', 'basePath' => '/gac', 'debug' => true]);
        $body = '{"error":"validation failed","issues":[{"code":"x"}]}';
        $client = new TrackingApiClient($debugConfig, new StubTransport(new Response(400, $body)));

        try {
            $client->verifyAccount('e.com');
            self::fail('Expected AccountVerificationException');
        } catch (AccountVerificationException $e) {
            self::assertNotNull($e->getIssues());
            self::assertSame('x', $e->getIssues()[0]['code']);
        }
    }

    public function testRejectsMalformedSuccessBody(): void
    {
        $client = new TrackingApiClient($this->config, new StubTransport(new Response(200, 'not json')));

        $this->expectException(AccountVerificationException::class);
        $client->verifyAccount('e.com');
    }

    public function testVerifyAccountRaisesWithoutTransport(): void
    {
        $client = self::withNullTransport(new TrackingApiClient($this->config, new StubTransport()));

        $this->expectException(NoHttpTransportException::class);
        $client->verifyAccount('e.com');
    }

    public function testEventSendsSilentlyNoOpWithoutTransport(): void
    {
        $client = self::withNullTransport(new TrackingApiClient($this->config, new StubTransport()));

        $client->sendPageview(['visitor_id' => 'v']);
        $client->sendIdentify(['visitor_id' => 'v']);

        // No transport, no exception: fire-and-forget contract holds.
        $this->addToAssertionCount(1);
    }

    /**
     * Force the client's resolved transport to null without touching the
     * environment's actual cURL / socket availability.
     */
    private static function withNullTransport(TrackingApiClient $client): TrackingApiClient
    {
        $ref = new ReflectionClass(TrackingApiClient::class);

        $transport = $ref->getProperty('transport');
        $transport->setAccessible(true);
        $transport->setValue($client, null);

        $resolved = $ref->getProperty('transportResolved');
        $resolved->setAccessible(true);
        $resolved->setValue($client, true);

        return $client;
    }
}
