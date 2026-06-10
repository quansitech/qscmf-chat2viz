<?php

namespace Qscmf\Chat2Viz\Exception;

/**
 * Thrown when a dashboard cannot be found by its UID.
 *
 * Controller should map this to a 404 response.
 */
class DashboardNotFoundException extends DashboardException
{
    public function __construct(string $uid = '', ?\Throwable $previous = null)
    {
        $message = $uid !== ''
            ? sprintf('Dashboard not found: %s', $uid)
            : 'Dashboard not found';

        parent::__construct($message, 0, $previous);
    }
}
