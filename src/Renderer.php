<?php

declare(strict_types=1);

namespace GaConnector\Tracking;

use GaConnector\Tracking\Http\Request;

/**
 * Renders the inline bootstrap the browser tracker reads:
 *
 *   - `window.__gacContext`  — per-request data captured server-side
 *     (URL, referrer, user-agent, render time). Never a visitor id.
 *   - `window.__gacSettings` — read-only tracker config from {@see Config}.
 *   - `window.__gacStatus`   — always-on baseline (`script_pending` in auto
 *     mode, `awaiting_consent` in consent mode), upgraded later by the
 *     tracker.
 *
 * In auto mode the tracker `<script>` tag is appended; in consent mode it is
 * omitted (the customer injects it via GTM / a consent banner using
 * {@see Renderer::scriptTag()}).
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
     * Build the bootstrap HTML for the given request.
     *
     * Debug off (production): everything is minified onto a single line with
     * no whitespace between statements. Debug on: each `window.__gac*`
     * assignment goes on its own line with pretty-printed JSON, for readable
     * output while developing.
     */
    public function render(Request $request): string
    {
        $context = [
            'url' => $request->url,
            'referrer' => $request->referrer(),
            'user_agent' => $request->userAgent(),
            'rendered_at' => time(),
        ];

        $status = $this->config->mode === Config::MODE_CONSENT ? 'awaiting_consent' : 'script_pending';

        $assignments = [
            ['window.__gacContext', $context],
            ['window.__gacSettings', $this->settings()],
            ['window.__gacStatus', $status],
        ];

        $withScriptTag = $this->config->mode !== Config::MODE_CONSENT;

        if ($this->config->debug) {
            $lines = [];
            foreach ($assignments as $assignment) {
                $lines[] = $assignment[0] . ' = ' . $this->encode($assignment[1], true) . ';';
            }

            $script = "<script>\n" . implode("\n", $lines) . "\n</script>";

            if ($withScriptTag) {
                $script .= "\n" . $this->scriptTag();
            }

            return $script;
        }

        $body = '';
        foreach ($assignments as $assignment) {
            $body .= $assignment[0] . '=' . $this->encode($assignment[1]) . ';';
        }

        $script = '<script>' . $body . '</script>';

        if ($withScriptTag) {
            $script .= $this->scriptTag();
        }

        return $script;
    }

    /**
     * Convenience wrapper that reads the current request from superglobals.
     */
    public function renderFromGlobals(): string
    {
        return $this->render(Request::fromGlobals());
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
