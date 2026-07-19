# Demo website

A complete, runnable multi-page site that uses the GA Connector server-side
tracking PHP library end-to-end. It shows both library capabilities:

- **Rendering** the `__gacContext` / `__gacSettings` / `__gacStatus` bootstrap
  into every page's `<head>` (one `GaConnector::html()` call).
- **Proxying** the tracker's browser calls through this site's own `/gac`
  routes: `GET /gac/js`, `POST /gac/events/pageview`, `POST /gac/events/identify`
  (one `GaConnector::serve()` call).

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
to a demo page (`/`, `/about`, `/contact`).

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

1. **Page view.** Load `/`. In DevTools → Network you should see
   `GET /gac/js` return the tracker, then `POST /gac/events/pageview` fire once
   the script runs. The bottom-right status box shows `__gacStatus: ok` and a
   `__gacvid` cookie value.
2. **Stable visitor.** Click through to `/about` and back. `__gacvid` stays the
   same across page loads.
3. **Identify.** On `/contact`, submit the form with an email. The tracker
   hashes the email in the browser and fires `POST /gac/events/identify`
   (visible in the Network tab). The plaintext email never leaves the browser.
4. **Proxy rewrite sanity.** `curl -s http://localhost:8080/gac/js | head` shows
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
