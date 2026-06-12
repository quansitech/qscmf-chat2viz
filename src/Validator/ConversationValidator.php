<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Validator;

use Qscmf\Chat2Viz\Traits\UuidTrait;

class ConversationValidator
{
    private const MAX_QUESTION_LENGTH = 1000;
    private const CONVERSATION_ID_PATTERN = '/^[a-f0-9\-]{1,64}$/i';

    /**
     * Validate question input — merges validateParsedInput and validateSocketInput.
     *
     * @param array|null $input Parsed JSON input
     * @return array|null Error envelope ['status' => 0, 'info' => '...'] or null if valid
     */
    public static function validateQuestion(?array $input): ?array
    {
        if (!is_array($input)) {
            $content_type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
            if (stripos($content_type, 'application/json') === false) {
                return ['status' => 0, 'info' => '请求格式不支持'];
            }
            return ['status' => 0, 'info' => '请求格式错误'];
        }

        $question = trim((string) ($input['question'] ?? ''));
        if ($question === '') {
            return ['status' => 0, 'info' => '请输入问题'];
        }

        if (mb_strlen($question) > self::MAX_QUESTION_LENGTH) {
            return ['status' => 0, 'info' => '问题长度不能超过1000字'];
        }

        $conversation_id = $input['conversation_id'] ?? null;
        if ($conversation_id !== null && !preg_match(self::CONVERSATION_ID_PATTERN, (string) $conversation_id)) {
            return ['status' => 0, 'info' => '无效的会话ID'];
        }

        return null;
    }

    /**
     * Validate conversation creation input.
     *
     * @param array|null $input Parsed JSON input
     * @return array|null Error envelope or null if valid
     */
    public static function validateConversationCreate(?array $input): ?array
    {
        if (!is_array($input)) {
            return ['status' => 0, 'info' => '请求格式错误'];
        }

        $dashboard_uid = trim((string) ($input['dashboard_uid'] ?? ''));
        if ($dashboard_uid === '') {
            return ['status' => 0, 'info' => '缺少仪表盘ID'];
        }

        if (!UuidTrait::validateUuid($dashboard_uid)) {
            return ['status' => 0, 'info' => '仪表盘ID格式无效'];
        }

        return null;
    }
}
