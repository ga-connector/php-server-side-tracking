<?php

declare(strict_types=1);

namespace GaConnector\Tracking;

use GaConnector\Tracking\Exception\ConfigException;

/**
 * Immutable configuration for the library.
 *
 * Built from a plain options array so callers never need Composer or any
 * particular framework container:
 *
 *     $config = Config::fromArray([
 *         'apiKey'  => 'gac_api_...',
 *         'baseUrl' => 'https://example.com/gac',
 *     ]);
 */
final class Config
{
    public const DEFAULT_API_BASE_URL = 'https://track.gaconnector.com';

    public const MODE_AUTO = 'auto';
    public const MODE_CONSENT = 'consent';

    public string $apiKey;
    /**
     * Absolute public proxy base URL (`https://example.com/gac`). Trailing
     * slash stripped. Always `http://` or `https://` with a non-empty path.
     */
    public string $baseUrl;
    public string $apiBaseUrl;
    public string $mode;
    public bool $debug;
    public bool $iframeEnabled;
    /** @var list<string> */
    public array $internalDomains;
    public bool $inlineContext;

    /**
     * Path component of {@see Config::$baseUrl} (e.g. `/gac`), used for
     * route matching. Always starts with `/`, never ends with `/`.
     */
    private string $pathPrefix;

    /**
     * @param list<string> $internalDomains
     */
    public function __construct(
        string $apiKey,
        string $baseUrl,
        string $pathPrefix,
        string $apiBaseUrl = self::DEFAULT_API_BASE_URL,
        string $mode = self::MODE_AUTO,
        bool $debug = false,
        bool $iframeEnabled = true,
        array $internalDomains = [],
        bool $inlineContext = false
    ) {
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
        $this->pathPrefix = $pathPrefix;
        $this->apiBaseUrl = $apiBaseUrl;
        $this->mode = $mode;
        $this->debug = $debug;
        $this->iframeEnabled = $iframeEnabled;
        $this->internalDomains = $internalDomains;
        $this->inlineContext = $inlineContext;
    }

    /**
     * Path prefix used for proxy route matching (e.g. `/gac`).
     */
    public function pathPrefix(): string
    {
        return $this->pathPrefix;
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        if (array_key_exists('basePath', $options)) {
            throw new ConfigException(
                '"basePath" was removed in v2; use "baseUrl" instead '
                . '(e.g. "https://example.com/gac").'
            );
        }

        $apiKey = isset($options['apiKey']) ? trim((string) $options['apiKey']) : '';
        if ($apiKey === '') {
            throw new ConfigException('A non-empty "apiKey" is required.');
        }

        $rawBaseUrl = isset($options['baseUrl']) ? trim((string) $options['baseUrl']) : '';
        if ($rawBaseUrl === '') {
            throw new ConfigException(
                'A non-empty absolute "baseUrl" is required (e.g. "https://example.com/gac").'
            );
        }

        [$baseUrl, $pathPrefix] = self::normalizeBaseUrl($rawBaseUrl);

        $apiBaseUrl = isset($options['apiBaseUrl']) && trim((string) $options['apiBaseUrl']) !== ''
            ? rtrim(trim((string) $options['apiBaseUrl']), '/')
            : self::DEFAULT_API_BASE_URL;

        $mode = isset($options['mode']) ? (string) $options['mode'] : self::MODE_AUTO;
        if ($mode !== self::MODE_AUTO && $mode !== self::MODE_CONSENT) {
            throw new ConfigException(sprintf('Unknown "mode" %s; expected "auto" or "consent".', var_export($options['mode'] ?? null, true)));
        }

        $internalDomains = [];
        if (isset($options['internalDomains']) && is_array($options['internalDomains'])) {
            foreach ($options['internalDomains'] as $domain) {
                $domain = trim((string) $domain);
                if ($domain !== '') {
                    $internalDomains[] = $domain;
                }
            }
        }

        return new self(
            $apiKey,
            $baseUrl,
            $pathPrefix,
            $apiBaseUrl,
            $mode,
            (bool) ($options['debug'] ?? false),
            (bool) ($options['iframeEnabled'] ?? true),
            $internalDomains,
            (bool) ($options['inlineContext'] ?? false)
        );
    }

    /**
     * Absolute URL for a tracking API path, e.g. `/api/v1/events/pageview`.
     */
    public function apiUrl(string $path): string
    {
        return $this->apiBaseUrl . '/' . ltrim($path, '/');
    }

    /**
     * Absolute proxy URL under the configured base, e.g.
     * `https://example.com/gac/events/pageview`.
     */
    public function proxyUrl(string $path): string
    {
        $path = ltrim($path, '/');

        return $path === '' ? $this->baseUrl : $this->baseUrl . '/' . $path;
    }

    /**
     * @return array{0: string, 1: string} [normalized absolute baseUrl, path component]
     */
    private static function normalizeBaseUrl(string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) {
            throw new ConfigException(
                '"baseUrl" must be an absolute http(s) URL with a path '
                . '(e.g. "https://example.com/gac").'
            );
        }

        $parts = parse_url($baseUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new ConfigException(
                '"baseUrl" must be a valid http(s) URL (e.g. "https://example.com/gac").'
            );
        }

        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $path = self::normalizePathOnly($path);
        if ($path === '') {
            throw new ConfigException(
                '"baseUrl" must include a non-empty path (e.g. "https://example.com/gac").'
            );
        }

        $origin = strtolower((string) $parts['scheme']) . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }

        return [$origin . $path, $path];
    }

    /**
     * Leading slash, no trailing slash. Empty string when nothing usable.
     */
    private static function normalizePathOnly(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $path = '/' . trim($path, '/');

        return $path === '/' ? '' : $path;
    }
}
