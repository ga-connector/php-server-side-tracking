# GA Connector — Server-Side Tracking Library (PHP)

A framework-agnostic PHP library that turns any PHP application into a
GA Connector tracker, the same way the WordPress plugin does for WordPress.
It does two things:

1. **Renders the inline bootstrap** (`window.__gacContext`,
   `window.__gacSettings`, `window.__gacStatus`) plus the tracker
   `<script>` tag into your page.
2. **Proxies the tracking calls** through your own domain — three
   handlers (`js`, `events/pageview`, `events/identify`) that attach your
   API key server-side and forward to the GA Connector tracking API. Plus
   an account-verification client for install/config time.

The visitor's browser only ever talks to your domain; your API key never
reaches the browser.

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
]);
```

### 1. Render the bootstrap in your page

Drop this into your template `<head>` (or just before `</body>`):

```php
echo $gac->html();
```

In `consent` mode the script tag is omitted; fetch it for your GTM /
consent-banner snippet with `$gac->scriptTag()`.

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

```php
use GaConnector\Tracking\Exception\AccountVerificationException;
use GaConnector\Tracking\Exception\NoHttpTransportException;

try {
    $account = $gac->verifyAccount('example.com');
    // $account is a GaConnector\Tracking\Account value object:
    //   $account->accountId, $account->accountName, $account->email, $account->allowedDomains
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

`$gac->html()`, `$gac->scriptTag()`, `$gac->serve()`, and
`$gac->verifyAccount()` are shorthands; the underlying `renderer()`, `proxy()`,
and `api()` objects are still available for advanced use.

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

## Examples

- [`examples/website/`](examples/website/) — a complete, runnable demo site with real `/gac` rewrites for the PHP built-in server, Apache, and nginx. It shows rendering the `<head>` snippet (`GaConnector::html()`) and proxying `/gac/*` tracking requests (`GaConnector::serve()`). Start it with `GAC_API_KEY=... php -S localhost:8080 examples/website/router.php` and see its [README](examples/website/README.md).

## Configuration reference

| Option             | Required | Default                          | Notes                                                     |
| ------------------ | -------- | -------------------------------- | --------------------------------------------------------- |
| `apiKey`           | yes      | —                                | `gac_api_<accountId>_<secret>`; sent as a Bearer token    |
| `basePath`         | yes      | —                                | URL prefix your proxy routes are mounted under, e.g. `/gac` |
| `mode`             | no       | `auto`                           | `auto` injects the script tag; `consent` omits it         |
| `debug`            | no       | `false`                          | Emits `__gacSettings.debug`                               |
| `iframeEnabled`    | no       | `true`                           | Cross-frame messaging in the tracker                      |
| `internalDomains`  | no       | `[]`                             | Other owned domains for cross-domain link decoration      |

## How it maps to the tracking API

The library targets the tracking API contract (`Authorization: Bearer`,
`page_url` / `referrer` / `user_agent` / `ip` on page views, SHA-256 hex
identifier on identify, `GET /api/v1/account`). The browser tracker itself
is served unchanged from `GET /api/v1/js`; the `js` handler only rewrites
its `{{PAGEVIEW_URL}}` / `{{IDENTIFY_URL}}` placeholders to your own routes.
See the [language libraries spec](../tracking-api.gaconnector.com/spec/05-language-libraries-proposal.md).

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
