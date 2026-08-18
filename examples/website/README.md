# Demo website

A **minimal reference implementation** — a hand-rolled multi-page site that
shows the GA Connector PHP library's two integration points working
end-to-end. It is **not** a starter kit, and `app.php`'s routing and layout
are **not** the integration pattern to copy into Laravel, Symfony, or any
other framework.

Copy the calls; put them where **your** app already owns layout and routing:

- **`GaConnector::html()`** in `render_page()` — the one line a real
  integration adds to its template `<head>`.
- **`GaConnector::serve()`** for every request under `/gac` — the same
  proxy mount you would register on your own router or rewrite.
- **`/setup`** — optional install-time `verifyAccount()` page. Visitor
  pages and `/gac/*` never call `/account`.

What this demo chooses (defaults unless noted):

| Option | Demo value | Notes |
| ------ | ---------- | ----- |
| `mode` | `auto` (default) | Script tag included in `html()` |
| `iframeEnabled` | `true` (default, unset) | Iframe handling lives in the tracker JS, not in `app.php`. No framed demo page; `__gacStatus` shows `iframe` only if you load a page inside a frame. |
| `inlineContext` | `true` | Safe here because nothing is cached |
| `debug` | `true` | Readable bootstrap + tracker console output |

The API key stays on the server; the browser only ever talks to this site.

## Layout

```
examples/website/
├── app.php            # shared front controller (routing + layout + pages)
├── router.php         # entrypoint for the PHP built-in server
└── public/            # document root for Apache / nginx
    ├── index.php      # entrypoint
    └── .htaccess      # Apache rewrites -> index.php
```

Both entrypoints do the same thing: send every request to `handle_request()`
in `app.php`, which routes `/gac/*` to the library proxy and everything else
to a demo page (`/`, `/about`, `/contact`, `/setup`).

## Prerequisite

The entrypoints load Composer's autoloader (`vendor/autoload.php`), so run
`composer install` once in the repository root before starting the demo:

```bash
composer install
```

## Run it (built-in server — no config needed)

From the repository root:

```bash
GAC_API_KEY=gac_api_<accountId>_<secret> php -S localhost:8080 examples/website/router.php
```

Then open <http://localhost:8080/>.

Optional environment variables:

- `GAC_API_KEY` — your key. The pages and the `GET /gac/js` fetch work without
  it, but page-view / identify events are only accepted upstream with a valid
  key. They are fire-and-forget, so a missing/invalid key never breaks a page.

## What to check

1. **Setup.** Open <http://localhost:8080/setup> (or `/setup` on your shared-host URL).
   That page alone calls `GET /api/v1/account` and shows whether the API key
   works and whether this host is on the account's domains list. Home / About /
   Contact / `/gac/*` never hit that endpoint. Override the host with
   `?domain=other.example.com` if needed. Works the same under Apache/nginx —
   no CLI required.
2. **Page view.** Load `/`. In DevTools → Network you should see
   `GET /gac/js` return the tracker, then `POST /gac/events/pageview` fire once
   the script runs. The bottom-right status box shows `__gacStatus: ok` and a
   `__gacvid` cookie value.
3. **Stable visitor.** Click through to `/about` and back. `__gacvid` stays the
   same across page loads.
4. **Identify.** On `/contact`, submit the form with an email. The tracker
   hashes the email in the browser and fires `POST /gac/events/identify`
   (visible in the Network tab). The plaintext email never leaves the browser.
5. **Proxy rewrite sanity.** `curl -s http://localhost:8080/gac/js | head` shows
   real JavaScript with the endpoint placeholders already rewritten to
   `/gac/events/pageview` and `/gac/events/identify`.

## Run it under Apache

Point a virtual host's `DocumentRoot` at `examples/website/public/` and allow
the bundled `.htaccess` to take effect:

```apache
<VirtualHost *:80>
    ServerName gac-demo.localhost
    DocumentRoot /path/to/gaconnector-sst-php-library/examples/website/public

    <Directory /path/to/gaconnector-sst-php-library/examples/website/public>
        AllowOverride All
        Require all granted
    </Directory>

    SetEnv GAC_API_KEY gac_api_<accountId>_<secret>
</VirtualHost>
```

The `.htaccess` routes every non-file request to `index.php`, so `/gac/*` and
the page paths all reach the front controller.

## Run it under nginx

nginx needs PHP-FPM. Point `root` at `public/` and funnel non-file requests to
`index.php`:

```nginx
server {
    listen 80;
    server_name gac-demo.localhost;
    root /path/to/gaconnector-sst-php-library/examples/website/public;
    index index.php;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param GAC_API_KEY "gac_api_<accountId>_<secret>";
        fastcgi_pass 127.0.0.1:9000;   # or your PHP-FPM socket
    }
}
```

`try_files ... /index.php` preserves the original `/gac/...` URI so the library
matches the proxy routes.
