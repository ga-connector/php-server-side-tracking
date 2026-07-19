<?php

declare(strict_types=1);

namespace GaConnector\Tracking;

use GaConnector\Tracking\Exception\AccountVerificationException;
use GaConnector\Tracking\Exception\NoHttpTransportException;
use GaConnector\Tracking\Transport\HttpClient;
use GaConnector\Tracking\Transport\HttpClientFactory;
use GaConnector\Tracking\Transport\Response;

/**
 * Talks to the GA Connector tracking API on behalf of the integration.
 *
 * Two postures:
 *   - Event sends ({@see TrackingApiClient::sendPageview()}, {@see TrackingApiClient::sendIdentify()})
 *     are fire-and-forget: any failure — including no transport being
 *     available — is swallowed silently.
 *   - Account verification ({@see TrackingApiClient::verifyAccount()}) needs a
 *     real response, so it raises {@see NoHttpTransportException} when no
 *     transport exists and maps error statuses to
 *     {@see AccountVerificationException}.
 */
final class TrackingApiClient
{
    private Config $config;
    private bool $transportResolved = false;
    private ?HttpClient $transport = null;

    public function __construct(Config $config, ?HttpClient $transport = null)
    {
        $this->config = $config;
        if ($transport !== null) {
            $this->transport = $transport;
            $this->transportResolved = true;
        }
    }

    /**
     * Record a page view. Fire-and-forget.
     *
     * @param array<string, mixed> $body
     */
    public function sendPageview(array $body): void
    {
        $this->sendEvent('/api/v1/events/pageview', $body);
    }

    /**
     * Attach a hashed identifier to a visitor. Fire-and-forget.
     *
     * @param array<string, mixed> $body
     */
    public function sendIdentify(array $body): void
    {
        $this->sendEvent('/api/v1/events/identify', $body);
    }

    /**
     * Fetch the canonical browser tracker script (public, no auth). Returns
     * null when it can't be retrieved, so the proxy can degrade gracefully.
     */
    public function fetchScript(): ?string
    {
        $transport = $this->transport();
        if ($transport === null) {
            return null;
        }

        $response = $transport->get($this->config->apiUrl('/api/v1/js'));
        if ($response === null || $response->status !== 200) {
            return null;
        }

        return $response->body;
    }

    /**
     * Verify the API key and return the account record.
     *
     * @throws NoHttpTransportException   when neither cURL nor sockets exist.
     * @throws AccountVerificationException on 400/401/403/404 or a malformed reply.
     */
    public function verifyAccount(string $domain): Account
    {
        $transport = $this->transport();
        if ($transport === null) {
            throw new NoHttpTransportException(
                'Cannot verify the account: no HTTP transport (cURL or sockets) is available on this host.',
            );
        }

        $url = $this->config->apiUrl('/api/v1/account') . '?domain=' . rawurlencode($domain);
        $headers = $this->authHeaders();
        if ($this->config->debug) {
            $headers['gac-debug'] = 'true';
        }

        $response = $transport->get($url, $headers);
        if ($response === null) {
            throw new AccountVerificationException(0, 'The account verification request failed to reach the tracking API.');
        }

        if ($response->status === 200) {
            return $this->decodeAccount($response);
        }

        throw $this->mapAccountError($response);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function sendEvent(string $path, array $body): void
    {
        $transport = $this->transport();
        if ($transport === null) {
            return;
        }

        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        try {
            $headers = $this->authHeaders();
            $headers['Content-Type'] = 'application/json';
            $transport->post($this->config->apiUrl($path), $json, $headers);
        } catch (\Throwable $e) {
            // Fire-and-forget: never surface a send failure to the caller.
        }
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->config->apiKey];
    }

    private function decodeAccount(Response $response): Account
    {
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            throw new AccountVerificationException(200, 'The tracking API returned a malformed account response.');
        }

        return Account::fromArray($decoded);
    }

    private function mapAccountError(Response $response): AccountVerificationException
    {
        switch ($response->status) {
            case 400:
                $message = 'Validation failed for the account verification request.';
                break;
            case 401:
                $message = 'The API key is missing, malformed, or its secret does not match.';
                break;
            case 403:
                $message = 'The account subscription has lapsed beyond the grace window.';
                break;
            case 404:
                $message = 'The API key references an account that does not exist.';
                break;
            default:
                $message = sprintf('The tracking API returned an unexpected status %d.', $response->status);
        }

        $issues = null;
        if ($response->status === 400 && $response->body !== '') {
            $decoded = json_decode($response->body, true);
            if (is_array($decoded) && isset($decoded['issues']) && is_array($decoded['issues'])) {
                $issues = $decoded['issues'];
            }
        }

        return new AccountVerificationException($response->status, $message, $issues);
    }

    private function transport(): ?HttpClient
    {
        if (!$this->transportResolved) {
            $this->transport = HttpClientFactory::detect();
            $this->transportResolved = true;
        }

        return $this->transport;
    }
}
