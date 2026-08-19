<?php

declare(strict_types=1);

namespace GaConnector\Tracking;

use GaConnector\Tracking\Http\Request;
use GaConnector\Tracking\Http\Response;

/**
 * The three same-origin proxy handlers the customer mounts under their base
 * path. Each takes a framework-neutral {@see Request} and returns a
 * {@see Response}. All event responses are `200` with an empty body
 * (fire-and-forget), matching the tracking API.
 */
final class Proxy
{
    public const ROUTE_JS = 'js';
    public const ROUTE_PAGEVIEW = 'events/pageview';
    public const ROUTE_IDENTIFY = 'events/identify';

    private const PAGEVIEW_PLACEHOLDER = '{{PAGEVIEW_URL}}';
    private const IDENTIFY_PLACEHOLDER = '{{IDENTIFY_URL}}';

    private Config $config;
    private TrackingApiClient $apiClient;

    public function __construct(Config $config, TrackingApiClient $apiClient)
    {
        $this->config = $config;
        $this->apiClient = $apiClient;
    }

    /**
     * `GET <basePath>/js` — serve the browser tracker with its endpoint
     * placeholders rewritten to this integration's own routes as absolute
     * URLs (scheme + host from the request). Absolute URLs keep the tracker
     * posting to this proxy when the script is loaded cross-origin (e.g.
     * a subdomain embedding the apex proxy, or GTM/consent-banner injection).
     * Falls back to root-relative paths when the request URL has no origin.
     * Degrades to an empty 200 script if the upstream script can't be
     * fetched, so the page never breaks.
     */
    public function handleJs(Request $request): Response
    {
        $script = $this->apiClient->fetchScript();
        if ($script === null) {
            return Response::javascript('');
        }

        $origin = $this->requestOrigin($request);
        $pageviewUrl = $this->config->proxyUrl(self::ROUTE_PAGEVIEW);
        $identifyUrl = $this->config->proxyUrl(self::ROUTE_IDENTIFY);
        if ($origin !== '') {
            $pageviewUrl = $origin . $pageviewUrl;
            $identifyUrl = $origin . $identifyUrl;
        }

        $script = str_replace(
            [self::PAGEVIEW_PLACEHOLDER, self::IDENTIFY_PLACEHOLDER],
            [$pageviewUrl, $identifyUrl],
            $script,
        );

        return Response::javascript($script);
    }

    /**
     * Scheme + host (and non-default port when present) from the request URL.
     * Empty string when the URL has no usable origin (e.g. a bare path).
     */
    private function requestOrigin(Request $request): string
    {
        $parts = parse_url($request->url);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = isset($parts['host']) ? (string) $parts['host'] : '';
        if ($scheme === '' || $host === '') {
            return '';
        }

        $origin = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            $isDefault = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
            if (!$isDefault) {
                $origin .= ':' . $port;
            }
        }

        return $origin;
    }

    /**
     * `POST <basePath>/events/pageview` — enrich the body with the visitor's
     * IP (authoritative server-side), attach the API key, forward.
     */
    public function handlePageview(Request $request): Response
    {
        $body = $request->jsonBody();
        $body['ip'] = $request->clientIp();

        $this->apiClient->sendPageview($body);

        return Response::noContent();
    }

    /**
     * `POST <basePath>/events/identify` — forward the body unchanged (the
     * email is already hashed in the browser), attach the API key.
     */
    public function handleIdentify(Request $request): Response
    {
        $body = $request->jsonBody();

        $this->apiClient->sendIdentify($body);

        return Response::noContent();
    }

    /**
     * Route a request to the right handler. `$route` (one of the ROUTE_*
     * constants) can be passed explicitly by a framework router, or left
     * null to be derived from the request path relative to the base path.
     */
    public function handle(Request $request, ?string $route = null): Response
    {
        $route = $route ?? $this->resolveRoute($request);

        switch ($route) {
            case self::ROUTE_JS:
                return $this->handleJs($request);
            case self::ROUTE_PAGEVIEW:
                return $this->handlePageview($request);
            case self::ROUTE_IDENTIFY:
                return $this->handleIdentify($request);
            default:
                return new Response(404, [], '');
        }
    }

    /**
     * Vanilla-PHP convenience: read the request from superglobals, route it,
     * and emit the response.
     */
    public function serveFromGlobals(?string $route = null): void
    {
        $this->handle(Request::fromGlobals(), $route)->emit();
    }

    /**
     * Derive the route suffix from the request URL path relative to the
     * configured base path. Returns null when nothing matches.
     */
    public function resolveRoute(Request $request): ?string
    {
        $path = (string) (parse_url($request->url, PHP_URL_PATH) ?: '');
        $path = rtrim($path, '/');
        $base = $this->config->basePath;

        if ($path === $base . '/' . self::ROUTE_PAGEVIEW) {
            return self::ROUTE_PAGEVIEW;
        }
        if ($path === $base . '/' . self::ROUTE_IDENTIFY) {
            return self::ROUTE_IDENTIFY;
        }
        if ($path === $base . '/' . self::ROUTE_JS) {
            return self::ROUTE_JS;
        }

        return null;
    }
}
