<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Validator;

/**
 * Validates user feedback input for the api_feedback endpoint.
 *
 * Rules (adversarial review consensus):
 * - At least one of thumbs/comment must be provided (no star ratings).
 * - message_id is required (for linking to the answered message).
 * - conversation_id may be empty string (anonymous feedback allowed).
 * - thumbs must be exactly "up" or "down".
 * - implicit_signals is optional and validated as array.
 */
class FeedbackValidator
{
    private const MAX_COMMENT_LENGTH = 2000;

    /**
     * Validate feedback input.
     *
     * @param array|null $input Parsed JSON input
     * @return array|null Error envelope ['status' => 0, 'info' => '...'] or null if valid
     */
    public static function validate(?array $input): ?array
    {
        if (!is_array($input)) {
            return ['status' => 0, 'info' => '请求格式错误'];
        }

        $messageId = trim((string) ($input['message_id'] ?? ''));
        if ($messageId === '') {
            return ['status' => 0, 'info' => '缺少 message_id'];
        }

        $thumbs = $input['thumbs'] ?? null;
        $comment = trim((string) ($input['comment'] ?? ''));
        $implicitSignals = $input['implicit_signals'] ?? null;

        // At least one feedback signal required
        if ($thumbs === null && $comment === '' && empty($implicitSignals)) {
            return ['status' => 0, 'info' => '请至少提供 thumbs、comment 或 implicit_signals 之一'];
        }

        // Validate thumbs value
        if ($thumbs !== null && !in_array($thumbs, ['up', 'down'], true)) {
            return ['status' => 0, 'info' => 'thumbs 必须是 up 或 down'];
        }

        // Validate comment length
        if ($comment !== '' && mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
            return ['status' => 0, 'info' => '评论长度不能超过 ' . self::MAX_COMMENT_LENGTH . ' 字'];
        }

        // Validate implicit_signals format
        if ($implicitSignals !== null && !is_array($implicitSignals)) {
            return ['status' => 0, 'info' => 'implicit_signals 必须是对象'];
        }

        return null; // valid
    }
}
