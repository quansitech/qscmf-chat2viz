<?php

namespace Qscmf\Chat2Viz\Traits;

/**
 * Reusable JSON request body parsing.
 * Extracted from Chat2VizController::parseInput() for sharing with DashboardController.
 */
trait JsonInputTrait
{
    protected function parseJsonInput(): ?array
    {
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($contentType, 'application/json') === false) {
            return null;
        }
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
