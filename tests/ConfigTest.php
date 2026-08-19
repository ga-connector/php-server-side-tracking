<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Tests;

use GaConnector\Tracking\Config;
use GaConnector\Tracking\Exception\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testNormalizesAbsoluteBaseUrl(): void
    {
        $config = Config::fromArray([
            'apiKey' => 'gac_api_acc_secret',
            'baseUrl' => 'https://e.com/gac/',
        ]);

        self::assertSame('https://e.com/gac', $config->baseUrl);
        self::assertSame('/gac', $config->pathPrefix());
    }

    public function testDefaults(): void
    {
        $config = Config::fromArray([
            'apiKey' => 'gac_api_acc_secret',
            'baseUrl' => 'https://e.com/gac',
        ]);

        self::assertSame(Config::DEFAULT_API_BASE_URL, $config->apiBaseUrl);
        self::assertSame('auto', $config->mode);
        self::assertFalse($config->inlineContext);
    }

    public function testInlineContextIsOptIn(): void
    {
        $config = Config::fromArray([
            'apiKey' => 'k',
            'baseUrl' => 'https://e.com/gac',
            'inlineContext' => true,
        ]);

        self::assertTrue($config->inlineContext);
    }

    public function testApiUrlJoinsBaseAndPath(): void
    {
        $config = Config::fromArray(['apiKey' => 'k', 'baseUrl' => 'https://e.com/gac']);

        self::assertSame(
            'https://track.gaconnector.com/api/v1/events/pageview',
            $config->apiUrl('/api/v1/events/pageview')
        );
    }

    public function testProxyUrlJoinsAbsoluteBaseUrlAndPath(): void
    {
        $config = Config::fromArray([
            'apiKey' => 'k',
            'baseUrl' => 'https://gaconnector.com/_ping/',
        ]);

        self::assertSame('https://gaconnector.com/_ping', $config->baseUrl);
        self::assertSame('/_ping', $config->pathPrefix());
        self::assertSame(
            'https://gaconnector.com/_ping/events/pageview',
            $config->proxyUrl('events/pageview')
        );
    }

    public function testPreservesNonDefaultPort(): void
    {
        $config = Config::fromArray([
            'apiKey' => 'k',
            'baseUrl' => 'http://localhost:8080/gac',
        ]);

        self::assertSame('http://localhost:8080/gac', $config->baseUrl);
        self::assertSame('/gac', $config->pathPrefix());
    }

    public function testTrimsTrailingSlashOnApiBaseUrlOverride(): void
    {
        $config = Config::fromArray([
            'apiKey' => 'k',
            'baseUrl' => 'https://e.com/gac',
            'apiBaseUrl' => 'https://track-staging.gaconnector.com/',
        ]);

        self::assertSame('https://track-staging.gaconnector.com', $config->apiBaseUrl);
    }

    public function testRejectsMissingApiKey(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray(['baseUrl' => 'https://e.com/gac']);
    }

    public function testRejectsMissingBaseUrl(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray(['apiKey' => 'k']);
    }

    public function testRejectsLegacyBasePathKey(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('basePath');
        Config::fromArray(['apiKey' => 'k', 'basePath' => '/gac']);
    }

    public function testRejectsPathOnlyBaseUrl(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('absolute');
        Config::fromArray(['apiKey' => 'k', 'baseUrl' => '/gac']);
    }

    public function testRejectsAbsoluteBaseUrlWithoutPath(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray(['apiKey' => 'k', 'baseUrl' => 'https://example.com']);
    }

    public function testRejectsUnknownMode(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray(['apiKey' => 'k', 'baseUrl' => 'https://e.com/gac', 'mode' => 'nope']);
    }
}
