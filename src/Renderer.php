<?php

declare(strict_types=1);

namespace GaConnector\Tracking;

use GaConnector\Tracking\Http\Request;

/**
 * Renders the inline bootstrap the browser tracker reads, as two snippets
 * that can be emitted together or placed independently:
 *
 *   - {@see Renderer::settingsScript()} — `window.__gacSettings` (read-only
 *     tracker config from {@see Config}) and the `window.__gacStatus`
 *     baseline (`script_pending` in auto mode, `awaiting_consent` in consent
 *     mode, upgraded later by the tracker). Identical for every visitor, so
 *     it is safe to serve from a full-page cache.
 *   - {@see Renderer::contextScript()} — `window.__gacContext`, the
 *     per-request data captured server-side (URL, referrer, user-agent,
 *     render time; never a visitor id). Different for every visitor, so a
 *     cached page would serve stale values.
 *
 * {@see Renderer::render()} emits the settings snippet, the context snippet
 * when `inlineContext` is enabled, and — in auto mode — the tracker
 * `<script>` tag. In consent mode the tag is omitted (the customer injects
 * it via GTM / a consent banner using {@see Renderer::scriptTag()}).
 */
final class Renderer
{
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Build the bootstrap HTML for the current request, read from
     * superglobals.
     */
    public function render(): string
    {
        return $this->renderFromRequest(Request::fromGlobals());
    }

    /**
     * Build the bootstrap HTML for an explicit request, for integrations
     * that already have their framework's request object.
     *
     * The context snippet is only included when `inlineContext` is enabled;
     * without it the tracker reads `window.location.href`,
     * `document.referrer`, and `navigator.userAgent` instead.
     */
    public function renderFromRequest(Request $request): string
    {
        $parts = [];

        if ($this->config->inlineContext) {
            $parts[] = $this->contextScriptFromRequest($request);
        }

        $parts[] = $this->settingsScript();

        if ($this->config->mode !== Config::MODE_CONSENT) {
            $parts[] = $this->scriptTag();
        }

        return implode($this->config->debug ? "\n" : '', $parts);
    }

    /**
     * The `__gacContext` snippet for the current request, read from
     * superglobals.
     *
     * Rendered whether or not `inlineContext` is enabled: enabling the
     * option is how you ask {@see Renderer::render()} to include it, while
     * calling this directly is how you place it yourself — e.g. inside an
     * uncached fragment of an otherwise cached page.
     */
    public function contextScript(): string
    {
        return $this->contextScriptFromRequest(Request::fromGlobals());
    }

    /**
     * The `__gacContext` snippet for an explicit request.
     */
    public function contextScriptFromRequest(Request $request): string
    {
        return $this->scriptBlock([
            ['window.__gacContext', [
                'url' => $request->url,
                'referrer' => $request->referrer(),
                'user_agent' => $request->userAgent(),
                'rendered_at' => time(),
            ]],
        ]);
    }

    /**
     * The `__gacSettings` + `__gacStatus` snippet. Takes no request: nothing
     * in it varies per visitor.
     */
    public function settingsScript(): string
    {
        return $this->scriptBlock([
            ['window.__gacSettings', $this->settings()],
            ['window.__gacStatus', $this->config->mode === Config::MODE_CONSENT ? 'awaiting_consent' : 'script_pending'],
        ]);
    }

    /**
     * The tracker `<script>` tag, with the cache-bypass attributes the
     * plugin uses. Exposed so consent-mode integrations can paste it into
     * GTM or a consent banner.
     */
    public function scriptTag(): string
    {
        $src = htmlspecialchars($this->config->proxyUrl('js'), ENT_QUOTES, 'UTF-8');

        return '<script src="' . $src . '" async data-cfasync="false" data-no-optimize="1" data-no-defer="1"></script>';
    }

    /**
     * The `__gacSettings` payload. `debug` and `mode` are always present;
     * the iframe/link controls are only emitted when they differ from the
     * tracker's own defaults, matching the WordPress plugin.
     *
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        $settings = [
            'debug' => $this->config->debug,
            'mode' => $this->config->mode,
        ];

        if ($this->config->iframeEnabled === false) {
            $settings['iframeEnabled'] = false;
        }

        if ($this->config->internalDomains !== []) {
            $settings['internalDomains'] = $this->config->internalDomains;
        }

        return $settings;
    }

    /**
     * Wrap `window.__gac*` assignments in a `<script>` block.
     *
     * Debug off (production): everything is minified onto a single line with
     * no whitespace between statements. Debug on: each assignment goes on
     * its own line with pretty-printed JSON, for readable output while
     * developing.
     *
     * @param list<array{0: string, 1: mixed}> $assignments
     */
    private function scriptBlock(array $assignments): string
    {
        if ($this->config->debug) {
            $lines = [];
            foreach ($assignments as $assignment) {
                $lines[] = $assignment[0] . ' = ' . $this->encode($assignment[1], true) . ';';
            }

            return "<script>\n" . implode("\n", $lines) . "\n</script>";
        }

        $body = '';
        foreach ($assignments as $assignment) {
            $body .= $assignment[0] . '=' . $this->encode($assignment[1]) . ';';
        }

        return '<script>' . $body . '</script>';
    }

    /**
     * @param mixed $value
     */
    private function encode($value, bool $pretty = false): string
    {
        $flags = self::JSON_FLAGS;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($value, $flags);

        return $json === false ? 'null' : $json;
    }
}
