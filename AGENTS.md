# GA Connector — Server-Side Tracking Library (PHP)

A framework-agnostic PHP library that turns any PHP application into a GA Connector
tracker, the same way the WordPress plugin does for WordPress. It (1) renders the
inline tracker bootstrap (`__gacContext` / `__gacSettings` / `__gacStatus`) plus the
tracker `<script>` tag into a page, and (2) proxies the tracking calls through the
customer's own domain so the API key never reaches the browser. It also exposes an
account-verification client for install/config time.

Distributed **only** via Composer as `gaconnector/server-side-tracking`. **Zero
third-party runtime dependencies** — HTTP goes through `ext-curl` when available and
falls back to raw sockets otherwise.

## Build and run

```bash
composer install   # pulls PHPUnit (dev-only) into this repo's vendor/
composer test      # runs vendor/bin/phpunit
```

There is no build step — it's a plain PSR-4 library loaded via Composer's autoloader.

## Project layout

```
src/
├── GaConnector.php        # Entry point: factory + static configure-once facade
├── Client.php             # A configured client; thin convenience methods over collaborators
├── Config.php             # Immutable config, built from an options array via Config::fromArray()
├── Renderer.php           # Builds the inline bootstrap + <script> tag
├── Proxy.php              # The three same-origin handlers (js, events/pageview, events/identify)
├── TrackingApiClient.php  # Talks to the tracking API (events + account verification + script fetch)
├── Account.php            # Value object returned by verifyAccount() (+ allows($domain))
├── Http/
│   ├── Request.php   # Framework-neutral request (+ Request::fromGlobals(), clientIp())
│   └── Response.php  # Framework-neutral response (+ ->emit())
├── Transport/
│   ├── HttpClient.php          # get()/post() contract (interface)
│   ├── HttpClientFactory.php   # detect(): cURL first, sockets second, null if neither
│   ├── CurlClient.php
│   ├── SocketClient.php
│   └── Response.php            # outbound HTTP call result
└── Exception/
    ├── ExceptionInterface.php            # marker implemented by every library exception
    ├── ConfigException.php               # bad/missing options
    ├── NoHttpTransportException.php       # no cURL and no sockets (account verify only)
    ├── AccountVerificationException.php   # 400/401/403/404 or malformed account reply
    └── NotConfiguredException.php         # facade used before GaConnector::configure()

tests/            # PHPUnit suite (mirrors src/); tests/Support/StubTransport.php is the fake transport
examples/website/ # The single, runnable demo site (PHP built-in server, Apache, nginx rewrites)
```

## Public API surface

Two ways to use it, both landing on `Client`:

```php
use GaConnector\Tracking\GaConnector;

// (a) Explicit client
$client = GaConnector::create(['apiKey' => '...', 'basePath' => '/gac']);
echo $client->html();

// (b) Configure once (bootstrap), call statically anywhere
GaConnector::configure(['apiKey' => '...', 'basePath' => '/gac']);
echo GaConnector::html();                     // in a template
GaConnector::serve();                         // in the /gac/* controller
$account = GaConnector::verifyAccount($host); // install-time verification
```

- `GaConnector` — factory (`create`) + static facade (`configure`, `use`, `isConfigured`,
  `reset`, `instance`) whose passthroughs (`html`, `scriptTag`, `serve`, `verifyAccount`,
  `config`, `renderer`, `proxy`, `api`) throw `NotConfiguredException` if called
  before `configure()`.
- `Client` — the convenience methods (`html`, `scriptTag`, `serve`, `verifyAccount`) delegate
  to the underlying `Renderer` / `Proxy` / `TrackingApiClient`, which stay accessible via
  `renderer()` / `proxy()` / `api()`.
- `verifyAccount()` returns an `Account` value object (`accountId`, `accountName`, `email`,
  `allowedDomains`, plus `allows($domain)`). All library exceptions implement
  `Exception\ExceptionInterface` for a single catch-all.

## Conventions

### Style / language level

- **Target PHP 7.4** — the floor is enforced in `composer.json` (`"php": ">=7.4"`) and in
  CI. Do **not** introduce 8.0+ syntax: no constructor property promotion, no `readonly`,
  no `match`, no `str_contains`/`str_starts_with`, no `mixed` type, no named arguments in
  calls the library makes to itself, no non-capturing `catch`. Use the 7.4 equivalents
  (explicit property declarations + assignment, `switch`, `strpos(...) !== false`, untyped
  params with `@param mixed` docblocks, positional args, `catch (\Throwable $e)`).
- `declare(strict_types=1);` at the top of every file. Namespace `GaConnector\Tracking\...`.
- Classes are `final` unless there's a reason not to be.
- Keep PHPDoc precise — array shapes (`array{account_id: string, ...}`) and `list<string>`
  are used and relied on.

### Design rules

- **Config is immutable** and only built through `Config::fromArray()`, which validates
  (`apiKey`, `basePath` required; `mode` ∈ `auto`/`consent`) and normalizes.
- **Event sends are fire-and-forget**: `sendPageview` / `sendIdentify` swallow every
  failure (including no transport). Never let a tracking send throw to the caller.
- **Account verification is not fire-and-forget**: it throws `NoHttpTransportException`
  when no transport exists and maps HTTP error statuses to `AccountVerificationException`.
- **The `js` handler never breaks the page**: if the upstream script can't be fetched it
  returns an empty `200` script. It only rewrites the `{{PAGEVIEW_URL}}` / `{{IDENTIFY_URL}}`
  placeholders to the customer's own proxy routes — the tracker itself is served unchanged.
- **The API key stays server-side.** It is attached as `Authorization: Bearer` inside the
  proxy/`TrackingApiClient` and must never be rendered into the page or exposed to the browser.
- Follow the tracking API OpenAPI contract in
  `../tracking-api.gaconnector.com/` (bearer auth, `page_url`/`referrer`/`user_agent`/`ip`
  on page views, hashed identifier on identify, `GET /api/v1/account`, `GET /api/v1/js`).

## Testing

- PHPUnit `^9.6` is a **dev-only** dependency (`require-dev`) — the newest line that runs on
  the PHP 7.4 floor. It's `export-ignore`d (see `.gitattributes`) so it never ships in the
  Composer dist and can't clash with a host app's own PHPUnit.
- Tests use `tests/Support/StubTransport.php` (an in-memory transport) to simulate API
  responses and capture outbound requests — no network.
- Add tests alongside the class you change (`tests/<Class>Test.php`) and run `composer test`.

## CI / releases

- `.github/workflows/tests.yml` (`CI`) runs the suite on PHP **7.4** and **8.3** so the
  minimum-version compatibility can't silently regress.
- After the matrix passes on a push to `main`/`master`, the `tag` job runs
  `mathieudutour/github-tag-action` to compute the next semver from
  [Conventional Commits](https://www.conventionalcommits.org/) and push a `vX.Y.Z` tag
  (`default_bump: false`, so chore/docs commits produce no tag). It only pushes a **tag** —
  no GitHub Release object — and Packagist picks the tag up via its webhook.

## Gotchas

- `apiBaseUrl` (and the `GAC_API_BASE_URL` env var in the example) is an **internal,
  dev-only override** for pointing the library at a non-production tracking host during
  development. It defaults to `Config::DEFAULT_API_BASE_URL`
  (`https://track.gaconnector.com`) and is intentionally left out of the public README /
  example docs — keep it that way.
- Distribution is Composer-only; there is no hand-written autoloader. Rely on PSR-4.
- Keep `examples/` to the single `website/` demo — it's the one runnable example.
- Dev-only and repo-meta files (`tests/`, `phpunit.xml.dist`, `.github/`, `.gitignore`,
  `.gitattributes`) are `export-ignore`d to keep the dist tarball lean; add new dev-only
  paths there too.
