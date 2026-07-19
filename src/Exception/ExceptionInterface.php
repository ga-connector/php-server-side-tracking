<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Exception;

/**
 * Marker interface implemented by every exception the library throws, so
 * callers can catch all of them in one clause:
 *
 *     try {
 *         $account = GaConnector::verifyAccount($host);
 *     } catch (\GaConnector\Tracking\Exception\ExceptionInterface $e) {
 *         // any GA Connector error
 *     }
 */
interface ExceptionInterface extends \Throwable
{
}
