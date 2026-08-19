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
        return Config::fromArray(array_merge(['apiKey' => 'gac_api_acc_secret', 'baseUrl' => 'https://e.com/gac'], $overrides));
    }

    public function testEmitsSettingsAndAutoModeScriptTag(): void
    {
        $rendered = (new Renderer($this->config()))->renderFromRequest(new Request('GET', 'https://e.com/'));

        self::assertStringContainsString('window.__gacSettings', $rendered);
        self::assertStringContainsString('"mode":"auto"', $rendered);
        self::assertStringContainsString('window.__gacStatus="script_pending"', $rendered);
        self::assertStringContainsString(
            '<script src="https://e.com/gac/js" async data-cfasync="false" data-no-optimize="1" data-no-defer="1"></script>',
            $rendered
        );
    }

    public function testOmitsContextByDefault(): void
    {
        $rendered = (new Renderer($this->config()))->renderFromRequest(new Request(
            'GET',
            'https://example.com/l?utm_source=g',
            ['referer' => 'https://ref/', 'user-agent' => 'UA/9']
        ));

        self::assertStringNotContainsString('window.__gacContext', $rendered);
    }

    public function testEmitsContextWhenInlineContextEnabled(): void
    {
        $renderer = new Renderer($this->config(['inlineContext' => true]));
        $rendered = $renderer->renderFromRequest(new Request(
            'GET',
            'https://example.com/l?utm_source=g',
            ['referer' => 'https://ref/', 'user-agent' => 'UA/9']
        ));

        self::assertStringContainsString('window.__gacContext', $rendered);
        self::assertStringContainsString('"url":"https://example.com/l?utm_source=g"', $rendered);
        self::assertStringContainsString('"referrer":"https://ref/"', $rendered);
        self::assertStringContainsString('"user_agent":"UA/9"', $rendered);
    }

    public function testRenderIsTheSnippetsConcatenated(): void
    {
        $renderer = new Renderer($this->config(['inlineContext' => true]));
        $request = new Request('GET', 'https://e.com/');

        self::assertSame(
            $renderer->contextScriptFromRequest($request) . $renderer->settingsScript() . $renderer->scriptTag(),
            $renderer->renderFromRequest($request)
        );
    }

    public function testGlobalsVariantsReadTheCurrentRequest(): void
    {
        $server = $_SERVER;
        $_SERVER['HTTP_HOST'] = 'globals.example';
        $_SERVER['REQUEST_URI'] = '/from-globals?utm_source=g';
        $_SERVER['HTTP_REFERER'] = 'https://ref.example/';

        try {
            $renderer = new Renderer($this->config(['inlineContext' => true]));

            self::assertStringContainsString('"url":"http://globals.example/from-globals?utm_source=g"', $renderer->contextScript());
            self::assertStringContainsString('"referrer":"https://ref.example/"', $renderer->render());
        } finally {
            $_SERVER = $server;
        }
    }

    public function testContextScriptRendersEvenWhenInlineContextDisabled(): void
    {
        $renderer = new Renderer($this->config());
        $snippet = $renderer->contextScriptFromRequest(new Request('GET', 'https://e.com/'));

        self::assertStringContainsString('window.__gacContext={', $snippet);
        self::assertStringNotContainsString('window.__gacSettings', $snippet);
    }

    public function testSettingsScriptCarriesSettingsAndStatusOnly(): void
    {
        $snippet = (new Renderer($this->config()))->settingsScript();

        self::assertStringContainsString('window.__gacSettings={', $snippet);
        self::assertStringContainsString('window.__gacStatus="script_pending"', $snippet);
        self::assertStringNotContainsString('window.__gacContext', $snippet);
        self::assertStringNotContainsString('<script src=', $snippet);
    }

    public function testConsentModeUsesAwaitingConsentAndOmitsScriptTag(): void
    {
        $consent = $this->config(['mode' => 'consent']);
        $rendered = (new Renderer($consent))->renderFromRequest(new Request('GET', 'https://e.com/'));

        self::assertStringContainsString('window.__gacStatus="awaiting_consent"', $rendered);
        self::assertStringNotContainsString('<script src="https://e.com/gac/js"', $rendered);
        self::assertStringContainsString('/gac/js', (new Renderer($consent))->scriptTag());
    }

    public function testMinifiesWhenDebugOff(): void
    {
        $rendered = (new Renderer($this->config(['inlineContext' => true])))
            ->renderFromRequest(new Request('GET', 'https://e.com/'));

        self::assertStringNotContainsString("\n", $rendered);
        self::assertStringContainsString('<script>window.__gacContext={', $rendered);
        self::assertStringContainsString('</script><script>window.__gacSettings={', $rendered);
        self::assertStringContainsString('</script><script src="https://e.com/gac/js"', $rendered);
    }

    public function testPrettyPrintsEachBlockOnItsOwnLineWhenDebugOn(): void
    {
        $rendered = (new Renderer($this->config(['debug' => true, 'inlineContext' => true])))
            ->renderFromRequest(new Request('GET', 'https://e.com/'));

        self::assertStringContainsString("<script>\nwindow.__gacContext = {", $rendered);
        self::assertStringContainsString("<script>\nwindow.__gacSettings = {", $rendered);
        self::assertStringContainsString("\nwindow.__gacStatus = \"script_pending\";\n</script>", $rendered);
        // Pretty-printed JSON has a space after the colon and indented keys.
        self::assertStringContainsString("\n    \"url\": \"https://e.com/\"", $rendered);
        // Snippets sit on their own lines rather than butting up against each other.
        self::assertStringContainsString("</script>\n<script", $rendered);
    }

    public function testEscapesClosingScriptTagInInlinedValues(): void
    {
        $request = new Request('GET', 'https://e.com/</script><b>', ['user-agent' => "a'\"b"]);
        $snippet = (new Renderer($this->config()))->contextScriptFromRequest($request);

        self::assertStringNotContainsString('</script><b>', $snippet);
    }

    public function testEmitsNonDefaultSettings(): void
    {
        $config = $this->config([
            'debug' => true,
            'iframeEnabled' => false,
            'internalDomains' => ['shop.example.com'],
        ]);
        $snippet = (new Renderer($config))->settingsScript();

        // debug is on here, so settings are pretty-printed (space after colon).
        self::assertStringContainsString('"debug": true', $snippet);
        self::assertStringContainsString('"iframeEnabled": false', $snippet);
        self::assertStringContainsString('"shop.example.com"', $snippet);
        self::assertStringContainsString('"internalDomains": [', $snippet);
    }
}
