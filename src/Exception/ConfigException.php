<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Exception;

use InvalidArgumentException;

/**
 * Thrown when the library is constructed with invalid configuration
 * (missing API key, missing base path, unknown mode, ...).
 */
final class ConfigException extends InvalidArgumentException implements ExceptionInterface
{
}
