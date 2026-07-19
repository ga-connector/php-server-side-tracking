<?php

declare(strict_types=1);

namespace GaConnector\Tracking;

use GaConnector\Tracking\Exception\NotConfiguredException;
use GaConnector\Tracking\Transport\HttpClient;

/**
 * Main entry point: a factory plus a static, configure-once facade over a
 * shared {@see Client}.
 *
 * Build a client explicitly:
 *
 *     $client = GaConnector::create([
 *         'apiKey'   => getenv('GAC_API_KEY'),
 *         'basePath' => '/gac',
 *     ]);
 *     echo $client->html();
 *
 * ...or configure once in your bootstrap and call from anywhere without
 * repeating the config:
 *
 *     GaConnector::configure([
 *         'apiKey'   => getenv('GAC_API_KEY'),
 *         'basePath' => '/gac',
 *     ]);
 *
 *     echo GaConnector::html();                     // in a template
 *     GaConnector::serve();                         // in the /gac/* controller
 *     $account = GaConnector::verifyAccount('example.com');
 */
final class GaConnector
{
    private static ?Client $instance = null;

    /**
     * Build a client without registering it as the shared instance.
     *
     * @param array<string, mixed> $options
     */
    public static function create(array $options, ?HttpClient $transport = null): Client
    {
        return new Client(Config::fromArray($options), $transport);
    }

    /**
     * Build a client and store it as the shared instance for the static
     * passthroughs below. Returns it so you can keep a reference too.
     *
     * @param array<string, mixed> $options
     */
    public static function configure(array $options, ?HttpClient $transport = null): Client
    {
        return self::$instance = self::create($options, $transport);
    }

    /**
     * Register a prebuilt client as the shared instance (dependency
     * injection, tests, multi-tenant swapping).
     */
    public static function use(Client $client): Client
    {
        return self::$instance = $client;
    }

    public static function isConfigured(): bool
    {
        return self::$instance !== null;
    }

    /**
     * Clear the shared instance. Mainly for tests and per-tenant resets.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public static function instance(): Client
    {
        if (self::$instance === null) {
            throw new NotConfiguredException('Call GaConnector::configure([...]) once before using the GaConnector facade.');
        }

        return self::$instance;
    }

    public static function html(): string
    {
        return self::instance()->html();
    }

    public static function scriptTag(): string
    {
        return self::instance()->scriptTag();
    }

    public static function serve(?string $route = null): void
    {
        self::instance()->serve($route);
    }

    public static function verifyAccount(string $domain): Account
    {
        return self::instance()->verifyAccount($domain);
    }

    public static function config(): Config
    {
        return self::instance()->config();
    }

    public static function renderer(): Renderer
    {
        return self::instance()->renderer();
    }

    public static function proxy(): Proxy
    {
        return self::instance()->proxy();
    }

    public static function api(): TrackingApiClient
    {
        return self::instance()->api();
    }
}
