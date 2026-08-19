# GA Connector — Server-Side Tracking Library (PHP)

A framework-agnostic PHP library that turns any PHP application into a
GA Connector tracker, the same way the WordPress plugin does for WordPress.
It does two things:

1. **Renders the inline bootstrap** (`window.__gacSettings`,
   `window.__gacStatus`, and optionally `window.__gacContext`) plus the
   tracker `<script>` tag into your page.
2. **Proxies the tracking calls** through your own domain — three
   handlers (`js`, `events/pageview`, `events/identify`) that attach your
   API key server-side and forward to the GA Connector tracking API. Plus
   an account-verification client for install/config time.

The visitor's browser only ever talks to your domain; your API key never
reaches the browser.

## This is an integration library

Installing the package does **not** auto-instrument your site. Unlike the
WordPress plugin — which hooks into WordPress for you — this library is an
abstraction you place in **your** app:

- You choose which HTML pages get the bootstrap (usually a shared layout).
- You choose where the proxy routes live (front controller, framework
  router, or rewrite) under your `basePath`.
- Templates, routing, consent banners, and caching stay yours.

The library only renders snippets and proxies the three endpoints with the
API key attached. Everything else is the host application.

## Requirements

- PHP 7.4+
- [Composer](https://getcomposer.org/) — the library is distributed and
  installed exclusively via Composer.
- **Zero third-party runtime dependencies.** `ext-curl` is used when
  available; otherwise the library falls back to raw sockets. If neither is
  available, page-view / identify sends silently no-op (they are
  fire-and-forget) and account verification raises `NoHttpTransportException`.

## Install

Install with Composer:

```bash
composer require gaconnector/server-side-tracking
```

Then load Composer's autoloader as usual:

```php
require __DIR__ . '/vendor/autoload.php';
```

## What you wire up

On each request, decide which of these apply:

| Responsibility | When | Call |
| -------------- | ---- | ---- |
| Bootstrap in HTML | Every HTML page you want tracked | `html()` (or the composed snippets) in your shared layout — `<head>` or before `</body>`. Not on API-only responses. |
| Proxy under `basePath` | Every request under that URL prefix | `serve()` or the individual handlers (`js`, `events/pageview`, `events/identify`), wherever your app already routes. |
| Account check | Install / admin / setup only | `verifyAccount()`. Not on the visitor path. |
| Configure once | App bootstrap | `GaConnector::create(...)` or `GaConnector::configure(...)`. |

```text
Incoming request
  ├─ path under basePath?  →  serve() / proxy handlers
  └─ otherwise
       ├─ HTML page to track?  →  html() in layout
       ├─ admin / setup?       →  verifyAccount() (optional)
       └─ other                →  no library call
```

## Quick start

```php
use GaConnector\Tracking\GaConnector;

$gac = GaConnector::create([
    'apiKey'   => getenv('GAC_API_KEY'),   // gac_api_<accountId>_<secret>
    'basePath' => '/gac',                   // where you mount the proxy routes
    // optional:
    // 'mode'            => 'auto',         // 'auto' (default) or 'consent'
    // 'debug'           => false,
    // 'iframeEnabled'   => true,
    // 'internalDomains' => ['shop.example.com'],
    // 'inlineContext'   => false,          // see "Server-side page context" below
]);
```

### 1. Render the bootstrap in your page

Drop this into your template `<head>` (or just before `</body>`):

```php
echo $gac->html();
```

In `consent` mode the script tag is omitted; fetch it for your GTM /
consent-banner snippet with `$gac->scriptTag()`. See
[Injection mode vs iframe handling](#injection-mode-vs-iframe-handling).

### Server-side page context (optional)

By default the bootstrap contains nothing per-visitor: just
`__gacSettings` and the `__gacStatus` baseline, both identical on every
request. The tracker reads `window.location.href`, `document.referrer`,
and `navigator.userAgent` in the browser.

Turn on `'inlineContext' => true` and the bootstrap also carries a
`window.__gacContext` block captured server-side — the request URL,
referrer, user-agent, and render time (never a visitor ID). The tracker
prefers it when present. The reason to want it is the URL: Safari's ITP
strips query parameters (and with them `gclid`, `utm_*`, and friends)
from `window.location`, while the server sees them intact.

**Only enable it if the page is not served from a full-page cache.**
Cached HTML would hand every later visitor the first visitor's URL and
referrer.

If you do cache, keep `inlineContext` off and render the two snippets
separately — the settings block in the cached template, the context block
in an uncached hole-punched fragment:

```php
echo $gac->settingsScript();   // cacheable: __gacSettings + __gacStatus
echo $gac->contextScript();    // per-request: __gacContext
echo $gac->scriptTag();        // the tracker <script> tag
```

`$gac->html()` is exactly those three concatenated (minus the context
block when `inlineContext` is off, and minus the script tag in `consent`
mode), so composing by hand costs you nothing.

### 2. Mount the proxy routes

Point every request under your `basePath` at the proxy. With a front
controller (e.g. `public/gac.php` mapped to `/gac/*`):

```php
$gac->serve();   // reads superglobals, routes, emits
```

This serves:

| Route                       | Purpose                                  |
| --------------------------- | ---------------------------------------- |
| `GET  /gac/js`              | Proxies the browser tracker script       |
| `POST /gac/events/pageview` | Records a page view (IP enriched)        |
| `POST /gac/events/identify` | Links a hashed email to the visitor      |

If you have a framework router, call the handlers directly with an
explicit request and emit the returned response:

```php
use GaConnector\Tracking\Http\Request;

$response = $gac->proxy()->handlePageview(Request::fromGlobals());
$response->emit();
```

### 3. Verify the API key (install / config time)

Call this when you save settings or from an admin/setup page — not on every
visitor request.

The demo site exposes it as a normal page at `/setup` (open it in a browser;
no CLI). Copy that handler into your own admin page if you want the same
signal in production:

```php
use GaConnector\Tracking\Exception\AccountVerificationException;
use GaConnector\Tracking\Exception\NoHttpTransportException;

try {
    $account = $gac->verifyAccount('example.com');
    // $account is a GaConnector\Tracking\Account value object:
    //   $account->accountId, $account->accountName, $account->email, $account->allowedDomains
    // allows() is true for an exact host or a subdomain of a registered domain
    // (e.g. www-staging.example.com when example.com is listed).
    $connected = $account->allows('example.com');
} catch (AccountVerificationException $e) {
    // $e->getStatus() is 401 (bad key), 403 (subscription lapsed), or 404 (unknown account)
} catch (NoHttpTransportException $e) {
    // neither curl nor sockets available on this host
}
```

Both library exceptions implement `GaConnector\Tracking\Exception\ExceptionInterface`,
so you can `catch (\GaConnector\Tracking\Exception\ExceptionInterface $e)` to handle
any GA Connector error in one clause.

`$gac->html()`, `$gac->contextScript()`, `$gac->settingsScript()`,
`$gac->scriptTag()`, `$gac->serve()`, and `$gac->verifyAccount()` are
shorthands; the underlying `renderer()`, `proxy()`, and `api()` objects are
still available for advanced use. Each renderer method that reads
superglobals has a `...FromRequest($request)` sibling
(`renderFromRequest()`, `contextScriptFromRequest()`) for framework
integrations that already hold a request object.

### Configure once with the `GaConnector` facade

If you would rather not pass config around, configure the static
`GaConnector` facade once in your bootstrap and call it from anywhere:

```php
use GaConnector\Tracking\GaConnector;

// bootstrap (once):
GaConnector::configure([
    'apiKey'   => getenv('GAC_API_KEY'),
    'basePath' => '/gac',
]);

// anywhere after:
echo GaConnector::html();                 // in a template
GaConnector::serve();                     // in the /gac/* controller
$account = GaConnector::verifyAccount('example.com');
```

`GaConnector::configure()` / `GaConnector::create()` return a
`GaConnector\Tracking\Client`; the facade wraps a single shared instance. Use
`GaConnector::reset()` / `GaConnector::use($client)` to swap it (tests,
multi-tenant). Calling a passthrough before `configure()` throws
`NotConfiguredException`.

## Injection mode vs iframe handling

There is no library mode called `iframe`. Library `mode` is only `auto` or
`consent`. Iframe behaviour lives in the browser tracker the proxy serves
(`GET /gac/js`); you do not build a separate iframe integration.

### `mode` — you choose how the script is injected

| Value | What `html()` does | When to use |
| ----- | ------------------ | ----------- |
| `auto` (default) | Includes the tracker `<script>` tag; the script loads immediately | Sites without a consent gate, or where consent is already handled elsewhere |
| `consent` | Emits settings + status only; omits the script tag | GDPR / consent banners or GTM — you inject `$gac->scriptTag()` yourself after consent |

The library does **not** detect consent state, parse DNT/GPC, or talk to any
consent-banner API. Wiring the script into your consent flow is your job.

### Iframe handling — automatic in the tracker

When the tracker runs inside a frame (`window.self !== window.top`):

- It **suppresses the child page view** (the top window already reports the
  visit) and sets `window.__gacStatus = "iframe"`.
- Form identify inside the frame still works.
- Cross-frame `postMessage` of visitor / GA ids is **on by default**
  (`iframeEnabled` defaults to `true`). The library only writes
  `iframeEnabled` into `__gacSettings` when you set it to `false`.
- Page-view suppression still applies even if you disable messaging.

No extra page, route, or example setup is required. Seeing `__gacStatus`
equal to `iframe` means the tracker ran inside a frame and behaved
correctly — it is not a fault and not a second mode you must configure.

### Cross-domain link decoration — opt-in

Leave `internalDomains` empty (default) and outbound links are unchanged.
List other domains you own and the tracker stamps the visitor id onto links
to those hosts so identity survives a cross-domain hop.

### Reading `__gacStatus`

The demo status box (and DevTools) show a single string:

| Value | Meaning |
| ----- | ------- |
| `script_pending` | Baseline in `auto` mode before the tracker runs |
| `awaiting_consent` | Baseline in `consent` mode before the script is injected |
| `ok` | Tracker ran and fired its first page view |
| `internal` | Tracker ran; referrer is in-site (normal navigation) |
| `iframe` | Tracker ran inside a frame and suppressed the child page view |
| `conflict` | A legacy `gaconnector2` tracker is also on the page |

## Examples

[`examples/website/`](examples/website/) is a **minimal reference
implementation** — a hand-rolled multi-page site that shows one way to call
`GaConnector::html()`, `GaConnector::serve()`, and a `/setup` page. It is
**not** a starter kit or a structure to copy into Laravel, Symfony, or any
other framework.

Copy the two integration points (bootstrap in the layout, proxy under
`basePath`); put them where **your** app already owns layout and routing.
See its [README](examples/website/README.md) for how to run it locally
(`GAC_API_KEY=... php -S localhost:8080 examples/website/router.php`).

## Configuration reference

| Option             | Required | Default                          | Notes                                                                 |
| ------------------ | -------- | -------------------------------- | --------------------------------------------------------------------- |
| `apiKey`           | yes      | —                                | `gac_api_<accountId>_<secret>`; sent as a Bearer token                |
| `basePath`         | yes      | —                                | URL prefix your proxy routes are mounted under, e.g. `/gac`           |
| `mode`             | no       | `auto`                           | `auto` includes the script tag; `consent` omits it (you inject via GTM / consent banner). Not related to iframes. |
| `debug`            | no       | `false`                          | Emits `__gacSettings.debug`                                           |
| `iframeEnabled`    | no       | `true`                           | Tracker cross-frame messaging; on by default. Set `false` only to disable messaging. In-frame page-view suppression is always automatic. |
| `internalDomains`  | no       | `[]`                             | Other owned domains for cross-domain link decoration (off until listed) |
| `inlineContext`    | no       | `false`                          | Inline `__gacContext`; only for pages that aren't cached              |

## How it maps to the tracking API

The library targets the tracking API contract (`Authorization: Bearer`,
`page_url` / `referrer` / `user_agent` / `ip` on page views, SHA-256 hex
identifier on identify, `GET /api/v1/account`). The browser tracker itself
is served unchanged from `GET /api/v1/js`; the `js` handler only rewrites
its `{{PAGEVIEW_URL}}` / `{{IDENTIFY_URL}}` placeholders to absolute URLs on
your proxy's own origin (so a cross-origin embed still posts here).

## Testing (contributors)

Tests use [PHPUnit](https://phpunit.de/), declared as a **dev-only**
dependency. It is never installed into a consuming project: Composer does not
pull a package's `require-dev` for downstream installs, and the test suite plus
`phpunit.xml.dist` are `export-ignore`d from the distributed package. So the
library keeps its zero third-party runtime dependencies and can't clash with a
host application's own PHPUnit.

```bash
composer install   # pulls PHPUnit into this repo's vendor/ only
composer test      # runs vendor/bin/phpunit
```

PHPUnit is pinned to `^9.6`, the newest line that still runs on the PHP 7.4
floor as well as current PHP. CI (`.github/workflows/ci.yml`) runs the suite
on PHP 7.4 and 8.3 so the minimum-version compatibility can't silently
regress.

## Releasing (maintainers)

Versioning is automated with
[github-tag-action](https://github.com/mathieudutour/github-tag-action). On every
push to `main`/`master`, once the PHP test matrix passes, the `tag` job inspects
the commits since the last tag, derives the next
[semantic version](https://semver.org/), pushes the git tag (`vX.Y.Z`), creates a
matching GitHub Release with the auto-generated changelog, and pings Packagist to
re-crawl the new tag — no manual version bumping, tagging, or release steps.

Because versions are derived from commit messages, use
[Conventional Commits](https://www.conventionalcommits.org/):

| Commit prefix                       | Release          |
| ----------------------------------- | ---------------- |
| `fix: ...`                          | patch (`x.y.Z`)  |
| `feat: ...`                         | minor (`x.Y.0`)  |
| `feat!: ...` / `BREAKING CHANGE:`   | major (`X.0.0`)  |
| `chore:` / `docs:` / `test:` / etc. | no release       |

`default_bump: false` means a push with no `feat`/`fix`/breaking commit produces
no tag. The tag/release steps authenticate with the built-in `GITHUB_TOKEN` (needs
only `contents: write`); the Packagist ping uses the `PACKAGIST_USERNAME` /
`PACKAGIST_TOKEN` repository secrets.
