<?php

/**
 * Complete demo website built on the GA Connector SST PHP library.
 *
 * This is the shared application core: every entrypoint (router.php for the
 * PHP built-in server, public/index.php for Apache/nginx) requires this file
 * and calls handle_request(). It demonstrates both library capabilities:
 *
 *   1. Rendering the __gacContext / __gacSettings / __gacStatus bootstrap
 *      into each page (Renderer).
 *   2. Serving the same-origin /gac/* proxy routes (Proxy): the tracker
 *      script, page-view events, and identify events.
 *
 * Configure it with one environment variable:
 *   GAC_API_KEY       your gac_api_<accountId>_<secret> key (required for the
 *                     event proxies to be accepted upstream; the page and the
 *                     /gac/js fetch work without it).
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use GaConnector\Tracking\GaConnector;

/**
 * Configure the shared GA Connector client once. Safe to call repeatedly.
 * After this, use GaConnector::html() / GaConnector::serve() anywhere in the
 * request.
 */
function boot_gac(): void
{
    if (GaConnector::isConfigured()) {
        return;
    }

    GaConnector::configure([
        'apiKey' => getenv('GAC_API_KEY') ?: 'gac_api_REPLACE_ME',
        'basePath' => '/gac',
        'debug' => true,
    ]);
}

/**
 * Front controller. Routes /gac/* to the library proxy and everything else
 * to a demo page.
 */
function handle_request(): void
{
    boot_gac();

    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    // Anything under the configured base path is handled by the library: it
    // reads the request from superglobals, matches js / events/pageview /
    // events/identify, forwards with the API key attached, and emits the
    // response. This is the "real rewrite" target every server config points
    // /gac/* at.
    if ($path === '/gac' || strpos($path, '/gac/') === 0) {
        GaConnector::serve();

        return;
    }

    switch ($path) {
        case '/':
            render_page('Home', home_body());

            return;

        case '/about':
            render_page('About', about_body());

            return;

        case '/contact':
            render_page('Contact', contact_body($method === 'POST' ? $_POST : null));

            return;

        default:
            http_response_code(404);
            render_page('Not found', '<h1>404</h1><p>No such page. Try the navigation above.</p>');

            return;
    }
}

/**
 * Shared HTML layout. Injects the GA Connector bootstrap into <head> and
 * shows a small live status panel so the tracking flow is visible while
 * testing.
 */
function render_page(string $title, string $bodyHtml): void
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    // The one line a real integration adds to its template <head>.
    $bootstrap = GaConnector::html();

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }

    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle} — GA Connector demo</title>
    {$bootstrap}
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; line-height: 1.5; }
        header { padding: 1rem 1.5rem; border-bottom: 1px solid #8883; display: flex; gap: 1.5rem; align-items: center; }
        header strong { font-size: 1.1rem; }
        nav a { margin-right: 1rem; text-decoration: none; }
        nav a:hover { text-decoration: underline; }
        main { max-width: 720px; margin: 0 auto; padding: 2rem 1.5rem 5rem; }
        h1 { margin-top: 0; }
        label { display: block; margin: 0.75rem 0 0.25rem; font-weight: 600; }
        input { width: 100%; padding: 0.5rem 0.6rem; font-size: 1rem; border: 1px solid #8886; border-radius: 6px; background: transparent; color: inherit; }
        button { margin-top: 1rem; padding: 0.55rem 1.1rem; font-size: 1rem; border: 0; border-radius: 6px; background: #2d6cdf; color: #fff; cursor: pointer; }
        code { background: #8882; padding: 0.1rem 0.35rem; border-radius: 4px; }
        .notice { padding: 0.75rem 1rem; border-radius: 8px; background: #2e7d3222; border: 1px solid #2e7d3255; margin-bottom: 1rem; }
        #gac-status { position: fixed; right: 1rem; bottom: 1rem; font: 12px/1.4 ui-monospace, monospace;
            background: #000c; color: #fff; padding: 0.6rem 0.8rem; border-radius: 8px; max-width: 320px; }
        #gac-status b { color: #7fd67f; }
    </style>
</head>
<body>
    <header>
        <strong>Acme Demo</strong>
        <nav>
            <a href="/">Home</a>
            <a href="/about">About</a>
            <a href="/contact">Contact</a>
        </nav>
    </header>
    <main>
        {$bodyHtml}
    </main>

    <div id="gac-status">GA Connector: initializing…</div>
    <script>
        // Live view of the tracker's client-side state. Not part of the
        // library — just a testing aid for this demo.
        (function () {
            var el = document.getElementById('gac-status');
            function cookie(name) {
                var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
                return m ? decodeURIComponent(m[1]) : '(none)';
            }
            function render() {
                var status = typeof window.__gacStatus === 'string' ? window.__gacStatus : '(unset)';
                el.innerHTML = '__gacStatus: <b>' + status + '</b><br>__gacvid: ' + cookie('__gacvid');
            }
            render();
            setInterval(render, 1000);
        })();
    </script>
</body>
</html>
HTML;
}

function home_body(): string
{
    return <<<HTML
<h1>Server-side tracking demo</h1>
<p class="notice">This page loads the GA Connector tracker through this site's
own <code>/gac</code> routes. Open your browser's Network tab and watch
<code>GET /gac/js</code> load the tracker, then <code>POST /gac/events/pageview</code>
fire once the script runs.</p>
<p>The bootstrap in this page's <code>&lt;head&gt;</code> was produced by a single
call to <code>GaConnector::html()</code>. The API key never
reaches the browser — it is attached server-side by the proxy.</p>
<p>Navigate to <a href="/about">About</a> (another page view) or
<a href="/contact">Contact</a> to trigger an identify from the form.</p>
<p>The status box in the bottom-right shows the tracker's live
<code>__gacStatus</code> and the <code>__gacvid</code> visitor cookie.</p>
HTML;
}

function about_body(): string
{
    return <<<HTML
<h1>About</h1>
<p>Acme is a fictional company used to demonstrate the GA Connector server-side
tracking PHP library. Every page on this site is a normal PHP page that renders
the tracker bootstrap in its <code>&lt;head&gt;</code>.</p>
<p>Because each navigation is a fresh page load, this counts as another page view
under the same visitor id — check that <code>__gacvid</code> stays stable across
pages.</p>
HTML;
}

/**
 * @param array<string, mixed>|null $post
 */
function contact_body(?array $post): string
{
    if ($post !== null) {
        $name = htmlspecialchars(trim((string) ($post['name'] ?? '')), ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars(trim((string) ($post['email'] ?? '')), ENT_QUOTES, 'UTF-8');
        $namePrefix = $name !== '' ? ', ' . $name : '';

        return <<<HTML
<h1>Thanks{$namePrefix}</h1>
<p class="notice">Form submitted. On submit, the tracker hashed the email
client-side and fired <code>POST /gac/events/identify</code> — check the Network
tab. The plaintext email never leaves the browser.</p>
<p>Submitted email: <code>{$email}</code></p>
<p><a href="/contact">Submit again</a></p>
HTML;
    }

    return <<<HTML
<h1>Contact us</h1>
<p>Submitting this form triggers a GA Connector <em>identify</em>: the tracker
finds the email field, hashes the address (SHA-256) in the browser, and posts it
to <code>/gac/events/identify</code>, which proxies it to the tracking API with
the API key attached.</p>
<form method="post" action="/contact">
    <label for="name">Name</label>
    <input id="name" name="name" type="text" placeholder="Ada Lovelace">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" placeholder="ada@example.com" required>
    <button type="submit">Send</button>
</form>
HTML;
}
