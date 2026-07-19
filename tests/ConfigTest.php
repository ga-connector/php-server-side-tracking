<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Tests;

use GaConnector\Tracking\Config;
use GaConnector\Tracking\Exception\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testNormalizesBasePathToLeadingSlashNoTrailing(): void
    {
        $config = Config::fromArray(['apiKey' => 'gac_api_acc_secret', 'basePath' => 'gac/']);

        self::assertSame('/gac', $config->basePath);
    }

    public function testDefaults(): void
    {
        $config = Config::fromArray(['apiKey' => 'gac_api_acc_secret', 'basePath' => 'gac/']);

        self::assertSame(Config::DEFAULT_API_BASE_URL, $config->apiBaseUrl);
        self::assertSame('auto', $config->mode);
    }

    public function testApiUrlJoinsBaseAndPath(): void
    {
        $config = Config::fromArray(['apiKey' => 'k', 'basePath' => '/gac']);

        self::assertSame(
            'https://track.gaconnector.com/api/v1/events/pageview',
            $config->apiUrl('/api/v1/events/pageview')
        );
    }

    public function testProxyUrlJoinsBasePathAndPath(): void
    {
        $config = Config::fromArray(['apiKey' => 'k', 'basePath' => '/gac']);

        self::assertSame('/gac/events/pageview', $config->proxyUrl('events/pageview'));
    }

    public function testTrimsTrailingSlashOnApiBaseUrlOverride(): void
    {
        $config = Config::fromArray([
            'apiKey' => 'k',
            'basePath' => '/gac',
            'apiBaseUrl' => 'https://track-staging.gaconnector.com/',
        ]);

        self::assertSame('https://track-staging.gaconnector.com', $config->apiBaseUrl);
    }

    public function testRejectsMissingApiKey(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray(['basePath' => '/gac']);
    }

    public function testRejectsMissingBasePath(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray(['apiKey' => 'k']);
    }

    public function testRejectsUnknownMode(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray(['apiKey' => 'k', 'basePath' => '/gac', 'mode' => 'nope']);
    }
}
