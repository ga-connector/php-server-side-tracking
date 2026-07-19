<?php

declare(strict_types=1);

namespace GaConnector\Tracking;

use GaConnector\Tracking\Transport\HttpClient;

/**
 * A configured GA Connector client.
 *
 * Build one with {@see GaConnector::create()} (or configure the static
 * {@see GaConnector} facade once and let it hold a shared instance):
 *
 *     $client = GaConnector::create([
 *         'apiKey'   => getenv('GAC_API_KEY'),
 *         'basePath' => '/gac',
 *     ]);
 *
 *     echo $client->html();                    // in your template
 *     $client->serve();                        // in your /gac/* controller
 *     $account = $client->verifyAccount($host); // at install time
 *
 * The short methods delegate to the underlying collaborators, which are also
 * available directly via {@see Client::renderer()}, {@see Client::proxy()},
 * and {@see Client::api()}.
 */
final class Client
{
    private Config $config;
    private TrackingApiClient $apiClient;
    private Renderer $renderer;
    private Proxy $proxy;

    public function __construct(Config $config, ?HttpClient $transport = null)
    {
        $this->config = $config;
        $this->apiClient = new TrackingApiClient($config, $transport);
        $this->renderer = new Renderer($config);
        $this->proxy = new Proxy($config, $this->apiClient);
    }

    /**
     * Render the inline bootstrap for the current request. Shorthand for
     * `renderer()->renderFromGlobals()`.
     */
    public function html(): string
    {
        return $this->renderer->renderFromGlobals();
    }

    /**
     * The tracker `<script>` tag (for consent-mode / GTM). Shorthand for
     * `renderer()->scriptTag()`.
     */
    public function scriptTag(): string
    {
        return $this->renderer->scriptTag();
    }

    /**
     * Handle the current same-origin proxy request and emit the response.
     * Shorthand for `proxy()->serveFromGlobals()`.
     */
    public function serve(?string $route = null): void
    {
        $this->proxy->serveFromGlobals($route);
    }

    /**
     * Verify the API key and return the account record. Shorthand for
     * `api()->verifyAccount($domain)`.
     */
    public function verifyAccount(string $domain): Account
    {
        return $this->apiClient->verifyAccount($domain);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function renderer(): Renderer
    {
        return $this->renderer;
    }

    public function proxy(): Proxy
    {
        return $this->proxy;
    }

    /**
     * The tracking API client (events, script fetch, account verification).
     */
    public function api(): TrackingApiClient
    {
        return $this->apiClient;
    }
}
