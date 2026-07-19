<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Tests;

use GaConnector\Tracking\Config;
use GaConnector\Tracking\Http\Request;
use GaConnector\Tracking\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private function config(array $overrides = []): Config
    {
        return Config::fromArray(array_merge(['apiKey' => 'gac_api_acc_secret', 'basePath' => '/gac'], $overrides));
    }

    public function testEmitsContextSettingsAndAutoModeScriptTag(): void
    {
        $rendered = (new Renderer($this->config()))->render(new Request(
            'GET',
            'https://example.com/l?utm_source=g',
            ['referer' => 'https://ref/', 'user-agent' => 'UA/9']
        ));

        self::assertStringContainsString('window.__gacContext', $rendered);
        self::assertStringContainsString('"url":"https://example.com/l?utm_source=g"', $rendered);
        self::assertStringContainsString('"referrer":"https://ref/"', $rendered);
        self::assertStringContainsString('"user_agent":"UA/9"', $rendered);
        self::assertStringContainsString('window.__gacSettings', $rendered);
        self::assertStringContainsString('"mode":"auto"', $rendered);
        self::assertStringContainsString('window.__gacStatus="script_pending"', $rendered);
        self::assertStringContainsString(
            '<script src="/gac/js" async data-cfasync="false" data-no-optimize="1" data-no-defer="1"></script>',
            $rendered
        );
    }

    public function testConsentModeUsesAwaitingConsentAndOmitsScriptTag(): void
    {
        $consent = $this->config(['mode' => 'consent']);
        $rendered = (new Renderer($consent))->render(new Request('GET', 'https://e.com/'));

        self::assertStringContainsString('window.__gacStatus="awaiting_consent"', $rendered);
        self::assertStringNotContainsString('<script src="/gac/js"', $rendered);
        self::assertStringContainsString('/gac/js', (new Renderer($consent))->scriptTag());
    }

    public function testMinifiesWhenDebugOff(): void
    {
        $rendered = (new Renderer($this->config()))->render(new Request('GET', 'https://e.com/'));

        self::assertStringNotContainsString("\n", $rendered);
        self::assertStringContainsString('<script>window.__gacContext={', $rendered);
        self::assertStringContainsString(';window.__gacSettings={', $rendered);
        self::assertStringContainsString('</script><script src="/gac/js"', $rendered);
    }

    public function testPrettyPrintsEachBlockOnItsOwnLineWhenDebugOn(): void
    {
        $rendered = (new Renderer($this->config(['debug' => true])))->render(new Request('GET', 'https://e.com/'));

        self::assertStringContainsString("<script>\nwindow.__gacContext = {", $rendered);
        self::assertStringContainsString("\nwindow.__gacSettings = {", $rendered);
        self::assertStringContainsString("\nwindow.__gacStatus = \"script_pending\";\n</script>", $rendered);
        // Pretty-printed JSON has a space after the colon and indented keys.
        self::assertStringContainsString("\n    \"url\": \"https://e.com/\"", $rendered);
    }

    public function testEscapesClosingScriptTagInInlinedValues(): void
    {
        $request = new Request('GET', 'https://e.com/</script><b>', ['user-agent' => "a'\"b"]);
        $rendered = (new Renderer($this->config()))->render($request);

        self::assertStringNotContainsString('</script><b>', $rendered);
    }

    public function testEmitsNonDefaultSettings(): void
    {
        $config = $this->config([
            'debug' => true,
            'iframeEnabled' => false,
            'internalDomains' => ['shop.example.com'],
        ]);
        $rendered = (new Renderer($config))->render(new Request('GET', 'https://e.com/'));

        // debug is on here, so settings are pretty-printed (space after colon).
        self::assertStringContainsString('"debug": true', $rendered);
        self::assertStringContainsString('"iframeEnabled": false', $rendered);
        self::assertStringContainsString('"shop.example.com"', $rendered);
        self::assertStringContainsString('"internalDomains": [', $rendered);
    }
}
