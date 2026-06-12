<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Validator;

use Qscmf\Chat2Viz\Exception\DashboardException;

class DashboardValidator
{
    private const VALID_DASHBOARD_STATUSES = ['draft', 'published', 'archived'];
    private const MAX_SCHEMA_BYTES = 65535;
    private const MAX_TITLE_LENGTH = 255;

    /**
     * Validate dashboard creation input.
     *
     * @param array $input Raw input from request body
     * @throws DashboardException When validation fails
     */
    public static function validateCreate(array $input): void
    {
        $title = trim((string) ($input['title'] ?? ''));
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new DashboardException('标题长度不能超过' . self::MAX_TITLE_LENGTH . '个字符');
        }

        if (isset($input['current_schema']) && !is_array($input['current_schema'])) {
            throw new DashboardException('current_schema 必须为数组');
        }
    }

    /**
     * Validate dashboard update input.
     *
     * @param array $input Raw input from request body
     * @throws DashboardException When validation fails
     */
    public static function validateUpdate(array $input): void
    {
        if (isset($input['title'])) {
            $title = trim((string) $input['title']);
            if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
                throw new DashboardException('标题长度不能超过' . self::MAX_TITLE_LENGTH . '个字符');
            }
        }

        if (isset($input['current_schema'])) {
            if (!is_array($input['current_schema'])) {
                throw new DashboardException('current_schema 必须为数组');
            }
            static::validateSchemaSize($input['current_schema']);
        }

        if (isset($input['dashboard_status'])) {
            if (!in_array($input['dashboard_status'], self::VALID_DASHBOARD_STATUSES, true)) {
                throw new DashboardException('无效的仪表盘状态');
            }
        }
    }

    /**
     * Validate that a schema does not exceed the maximum allowed size.
     *
     * @param array $schema Schema data to validate
     * @throws DashboardException When schema exceeds size limit
     */
    public static function validateSchemaSize(array $schema): void
    {
        $size = strlen(json_encode($schema, JSON_UNESCAPED_UNICODE));
        if ($size > self::MAX_SCHEMA_BYTES) {
            throw new DashboardException('仪表盘数据过大');
        }
    }
}
