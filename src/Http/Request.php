<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Http;

/**
 * Framework-neutral snapshot of an incoming HTTP request.
 *
 * The library never depends on PSR-7 (that would require Composer). Instead
 * callers either build this struct explicitly from their framework's request
 * object, or use {@see Request::fromGlobals()} in a vanilla-PHP front
 * controller.
 */
final class Request
{
    public string $method;
    public string $url;
    /** @var array<string, string> */
    public array $headers;
    /** @var array<string, mixed> */
    public array $query;
    public string $rawBody;
    public string $remoteAddr;

    /**
     * @param array<string, string> $headers Header names are lowercased.
     * @param array<string, mixed>  $query
     */
    public function __construct(
        string $method,
        string $url,
        array $headers = [],
        array $query = [],
        string $rawBody = '',
        string $remoteAddr = ''
    ) {
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->query = $query;
        $this->rawBody = $rawBody;
        $this->remoteAddr = $remoteAddr;
    }

    /**
     * Build a request from PHP superglobals for a vanilla-PHP integration.
     */
    public static function fromGlobals(): self
    {
        $server = $_SERVER;

        $https = isset($server['HTTPS']) && $server['HTTPS'] !== '' && strtolower((string) $server['HTTPS']) !== 'off';
        $forwardedProto = isset($server['HTTP_X_FORWARDED_PROTO'])
            ? strtolower(trim(explode(',', (string) $server['HTTP_X_FORWARDED_PROTO'])[0]))
            : '';
        $scheme = $forwardedProto !== '' ? $forwardedProto : ($https ? 'https' : 'http');

        $host = (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost');
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $url = $scheme . '://' . $host . $uri;

        $headers = [];
        foreach ($server as $key => $value) {
            if (strncmp($key, 'HTTP_', 5) === 0) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        }
        if (isset($server['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $server['CONTENT_LENGTH'];
        }

        $rawBody = (string) (file_get_contents('php://input') ?: '');

        return new self(
            strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            $url,
            $headers,
            $_GET,
            $rawBody,
            (string) ($server['REMOTE_ADDR'] ?? '')
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function referrer(): string
    {
        return $this->header('referer') ?? '';
    }

    public function userAgent(): string
    {
        return $this->header('user-agent') ?? '';
    }

    /**
     * Proxy / CDN headers that carry the original client IP, in priority
     * order. Each holds a single IP except `x-forwarded-for` (comma list,
     * first entry wins) and `forwarded` (RFC 7239, first `for=` wins), which
     * are handled specially.
     */
    private const IP_HEADERS = [
        'cf-connecting-ip',
        'true-client-ip',
        'fastly-client-ip',
        'fly-client-ip',
        'x-envoy-external-address',
        'x-real-ip',
        'x-forwarded-for',
        'forwarded',
        'client-ip',
        'x-client-ip',
        'x-cluster-client-ip',
        'x-appengine-user-ip',
    ];

    /**
     * Public IP used when the request address is missing or non-routable
     * (loopback / private / reserved), so the tracking API's GeoIP lookup
     * has something to resolve. The value matches the tracking backend's
     * own default; the range policy here is intentionally stricter (the
     * backend only substitutes for loopback addresses).
     */
    private const DEFAULT_IP = '91.90.13.89';

    /**
     * Best-effort public IP for the visitor, matching the tracking API's
     * `ip` field. Walks the known proxy / CDN headers in priority order and
     * returns the first value that parses to a valid IP. Otherwise it uses
     * the socket peer (request address); when that is missing or not a
     * routable public address (loopback, private LAN ranges such as
     * 192.168.x / 10.x / 172.16-31.x, link-local, or other reserved
     * ranges), returns {@see Request::DEFAULT_IP} so GeoIP still resolves.
     */
    public function clientIp(): string
    {
        $fromHeaders = $this->ipFromHeaders();
        if ($fromHeaders !== null) {
            return $fromHeaders;
        }

        // Normalize first (strip any :port / [..] brackets) so a request
        // address carrying a port isn't misread as non-routable.
        $remote = $this->normalizeIp($this->remoteAddr);
        if ($remote === null || !$this->isPublicIp($remote)) {
            return self::DEFAULT_IP;
        }

        return $remote;
    }

    /**
     * Whether the given value is a valid, routable public IP address, i.e.
     * not empty, not a private LAN range (10.0.0.0/8, 172.16.0.0/12,
     * 192.168.0.0/16, IPv6 fc00::/7) and not a reserved range (loopback,
     * link-local 169.254.0.0/16, etc.).
     */
    private function isPublicIp(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        // Unwrap IPv4-mapped IPv6 (e.g. ::ffff:192.168.0.1) so the embedded
        // IPv4 address is range-checked instead of passing as public IPv6.
        if (stripos($ip, '::ffff:') === 0) {
            $mapped = substr($ip, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $ip = $mapped;
            }
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * First valid IP found in the known proxy / CDN headers, or null when
     * none is present.
     */
    private function ipFromHeaders(): ?string
    {
        foreach (self::IP_HEADERS as $header) {
            $value = $this->header($header);
            if ($value === null || $value === '') {
                continue;
            }

            if ($header === 'x-forwarded-for') {
                $value = explode(',', $value)[0];
            } elseif ($header === 'forwarded') {
                $value = $this->firstForwardedFor($value);
            }

            $ip = $this->normalizeIp($value);
            if ($ip !== null) {
                return $ip;
            }
        }

        return null;
    }

    /**
     * Extract the first `for=` value from an RFC 7239 `Forwarded` header,
     * e.g. `for=192.0.2.60;proto=http, for=198.51.100.17` -> `192.0.2.60`.
     */
    private function firstForwardedFor(string $forwarded): string
    {
        $firstElement = explode(',', $forwarded)[0];

        foreach (explode(';', $firstElement) as $pair) {
            $pair = trim($pair);
            if (stripos($pair, 'for=') === 0) {
                return substr($pair, 4);
            }
        }

        return '';
    }

    /**
     * Trim, unwrap quotes / IPv6 brackets, strip any `:port` on an IPv4
     * value, and validate. Returns the canonical IP or null when the value
     * is not a valid IP address.
     */
    private function normalizeIp(string $value): ?string
    {
        $value = trim($value);
        // Drop surrounding double quotes (RFC 7239 quoted values).
        if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
            $value = substr($value, 1, -1);
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Bracketed IPv6, optionally with a port: [2001:db8::1]:443
        if ($value[0] === '[') {
            $close = strpos($value, ']');
            if ($close !== false) {
                $value = substr($value, 1, $close - 1);
            }
        } elseif (substr_count($value, ':') === 1) {
            // A single colon means IPv4:port (bare IPv6 has several colons).
            $value = explode(':', $value)[0];
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }

    /**
     * Decode a JSON request body into an associative array. Returns an
     * empty array when the body is missing or not a JSON object.
     *
     * @return array<string, mixed>
     */
    public function jsonBody(): array
    {
        if ($this->rawBody === '') {
            return [];
        }

        $decoded = json_decode($this->rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }
}
