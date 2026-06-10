<?php

namespace Qscmf\Chat2Viz\Exception;

/**
 * Base domain exception for the Chat2Viz Dashboard module.
 *
 * All dashboard-related errors should be wrapped in this exception hierarchy
 * so that the controller can handle them uniformly.
 */
class DashboardException extends \Exception
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
