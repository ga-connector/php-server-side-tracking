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
 *         'apiKey'   => 'gac_api_...',
 *         'basePath' => '/gac',
 *     ]);
 */
final class Config
{
    public const DEFAULT_API_BASE_URL = 'https://track.gaconnector.com';

    public const MODE_AUTO = 'auto';
    public const MODE_CONSENT = 'consent';

    public string $apiKey;
    public string $basePath;
    public string $apiBaseUrl;
    public string $mode;
    public bool $debug;
    public bool $iframeEnabled;
    /** @var list<string> */
    public array $internalDomains;

    /**
     * @param list<string> $internalDomains
     */
    public function __construct(
        string $apiKey,
        string $basePath,
        string $apiBaseUrl = self::DEFAULT_API_BASE_URL,
        string $mode = self::MODE_AUTO,
        bool $debug = false,
        bool $iframeEnabled = true,
        array $internalDomains = []
    ) {
        $this->apiKey = $apiKey;
        $this->basePath = $basePath;
        $this->apiBaseUrl = $apiBaseUrl;
        $this->mode = $mode;
        $this->debug = $debug;
        $this->iframeEnabled = $iframeEnabled;
        $this->internalDomains = $internalDomains;
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        $apiKey = isset($options['apiKey']) ? trim((string) $options['apiKey']) : '';
        if ($apiKey === '') {
            throw new ConfigException('A non-empty "apiKey" is required.');
        }

        $basePath = isset($options['basePath']) ? (string) $options['basePath'] : '';
        $basePath = self::normalizeBasePath($basePath);
        if ($basePath === '') {
            throw new ConfigException('A non-empty "basePath" is required (e.g. "/gac").');
        }

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
            $basePath,
            $apiBaseUrl,
            $mode,
            (bool) ($options['debug'] ?? false),
            (bool) ($options['iframeEnabled'] ?? true),
            $internalDomains
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
     * Same-origin proxy URL under the configured base path, e.g. the
     * customer route `/gac/events/pageview`.
     */
    public function proxyUrl(string $path): string
    {
        $path = ltrim($path, '/');

        return $path === '' ? $this->basePath : $this->basePath . '/' . $path;
    }

    /**
     * Leading slash, no trailing slash. Empty string when nothing usable
     * was supplied.
     */
    private static function normalizeBasePath(string $basePath): string
    {
        $basePath = trim($basePath);
        if ($basePath === '') {
            return '';
        }

        $basePath = '/' . trim($basePath, '/');

        return $basePath === '/' ? '' : $basePath;
    }
}
