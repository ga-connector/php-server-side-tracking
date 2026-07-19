<?php

/**
 * Document-root front controller for Apache / nginx.
 *
 * Point the server's document root at this `public/` directory. The rewrite
 * rules (see .htaccess for Apache, or the nginx snippet in the README) send
 * every request that isn't a real file to this script, which delegates to the
 * shared application core.
 */

declare(strict_types=1);

require __DIR__ . '/../app.php';

handle_request();
