<?php

/**
 * Entrypoint for the PHP built-in web server:
 *
 *     GAC_API_KEY=gac_api_... php -S localhost:8080 examples/website/router.php
 *
 * The built-in server hands every request to this router script. We route
 * all of them (pages and /gac/* proxy routes) through the shared front
 * controller and never return false, so nothing falls through to static
 * file serving.
 */

declare(strict_types=1);

require __DIR__ . '/app.php';

handle_request();
