<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Exception;

use LogicException;

/**
 * Thrown when the static {@see \GaConnector\Tracking\GaConnector} facade is
 * used before `GaConnector::configure([...])` has been called. This is a
 * programming error (the facade has no instance to delegate to), hence a
 * LogicException.
 */
final class NotConfiguredException extends LogicException implements ExceptionInterface
{
}
