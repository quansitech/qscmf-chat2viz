<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Sse;

/**
 * Accumulates SSE stream data into a Redis Hash for atomic flush to DB.
 *
 * Redis key format: chat2viz:stream:{conversation_id}
 * Hash fields: content, reasoning_content, sql, g2_spec, chart_type, widget_id,
 *              tool_calls (JSON array), action_calls (JSON array)
 * TTL: 3600 seconds
 *
 * On stream completion: flush() returns all accumulated data for DB persistence.
 * On stream interruption: flush() returns partial data for interrupted status save.
 */
class StreamAccumulator
{
    private const KEY_PREFIX = 'chat2viz:stream:';
    private const TTL = 3600;

    private bool $redisAvailable;

    public function __construct()
    {
        $this->redisAvailable = class_exists(\Redis::class);
    }

    // -------------------------------------------------------
    // Public accumulation API
    // -------------------------------------------------------

    /**
     * Append answer text to the 'content' hash field.
     *
     * Uses Redis HGET/HSET read-modify-write cycle since HAPPEND does not exist.
     * Falls back to no-op if Redis is unavailable.
     */
    public function accumulateAnswer(string $conversationId, string $text): void
    {
        if (!$this->ensureRedis($conversationId)) {
            return;
        }

        $redis = $this->redis();
        $key = $this->key($conversationId);

        $existing = (string) $redis->hGet($key, 'content');
        $redis->hSet($key, 'content', $existing . $text);
    }

    /**
     * Store the generated SQL into the 'sql' hash field (replaces previous).
     */
    public function accumulateSql(string $conversationId, string $sql): void
    {
        $this->hSet($conversationId, 'sql', $sql);
    }

    /**
     * Store the G2 chart spec into the 'g2_spec' hash field (JSON string, replaces previous).
     */
    public function accumulateG2Spec(string $conversationId, array $g2Spec): void
    {
        $this->hSet($conversationId, 'g2_spec', json_encode($g2Spec, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Store chart_type and widget_id metadata fields.
     */
    public function accumulateChartMeta(string $conversationId, string $chartType, string $widgetId): void
    {
        if (!$this->ensureRedis($conversationId)) {
            return;
        }

        $redis = $this->redis();
        $key = $this->key($conversationId);
        $redis->hSet($key, 'chart_type', $chartType);
        $redis->hSet($key, 'widget_id', $widgetId);
    }

    /**
     * Append a tool call record to the 'tool_calls' JSON array hash field.
     *
     * Each call is appended to the existing array. Thread-safe via read-modify-write
     * under the assumption that SSE events are processed sequentially.
     */
    public function accumulateToolCall(string $conversationId, array $toolCall): void
    {
        if (!$this->ensureRedis($conversationId)) {
            return;
        }

        $redis = $this->redis();
        $key = $this->key($conversationId);

        $existing = $redis->hGet($key, 'tool_calls');
        $calls = ($existing !== false) ? (array) json_decode($existing, true) : [];
        $calls[] = $toolCall;
        $redis->hSet($key, 'tool_calls', json_encode($calls, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Append reasoning text to the 'reasoning_content' hash field.
     */
    public function accumulateReasoning(string $conversationId, string $text): void
    {
        if (!$this->ensureRedis($conversationId)) {
            return;
        }

        $redis = $this->redis();
        $key = $this->key($conversationId);

        $existing = (string) $redis->hGet($key, 'reasoning_content');
        $redis->hSet($key, 'reasoning_content', $existing . $text);
    }

    /**
     * Append an action call record to the 'action_calls' JSON array hash field.
     *
     * Action calls are tool invocations from the NL2SQL agent (e.g. execute_sql, create_chart).
     */
    public function accumulateActionCall(string $conversationId, array $actionCall): void
    {
        if (!$this->ensureRedis($conversationId)) {
            return;
        }

        $redis = $this->redis();
        $key = $this->key($conversationId);

        $existing = $redis->hGet($key, 'action_calls');
        $calls = ($existing !== false) ? (array) json_decode($existing, true) : [];
        $calls[] = $actionCall;
        $redis->hSet($key, 'action_calls', json_encode($calls, JSON_UNESCAPED_UNICODE));
    }

    // -------------------------------------------------------
    // Flush and cleanup
    // -------------------------------------------------------

    /**
     * Atomically read all accumulated data from the Redis Hash.
     *
     * Returns a structured array suitable for updateMessageWithMetadata().
     * Does NOT delete the key — call cleanup() separately after DB write succeeds.
     *
     * @return array{
     *     content: string,
     *     metadata: array,
     *     reasoning_content: string,
     *     tool_calls: array
     * }
     */
    public function flush(string $conversationId): array
    {
        $empty = [
            'content'           => '',
            'metadata'          => [],
            'reasoning_content' => '',
            'tool_calls'        => [],
        ];

        if (!$this->redisAvailable) {
            return $empty;
        }

        try {
            $redis = $this->redis();
            $key = $this->key($conversationId);
            $raw = $redis->hGetAll($key);

            if (!is_array($raw) || empty($raw)) {
                return $empty;
            }

            // Build metadata from individual hash fields
            $metadata = [];
            if (isset($raw['sql']) && $raw['sql'] !== '') {
                $metadata['sql'] = $raw['sql'];
            }
            if (isset($raw['g2_spec']) && $raw['g2_spec'] !== '') {
                $decoded = json_decode($raw['g2_spec'], true);
                $metadata['g2_spec'] = is_array($decoded) ? $decoded : $raw['g2_spec'];
            }
            if (isset($raw['chart_type']) && $raw['chart_type'] !== '') {
                $metadata['chart_type'] = $raw['chart_type'];
            }
            if (isset($raw['widget_id']) && $raw['widget_id'] !== '') {
                $metadata['widget_id'] = $raw['widget_id'];
            }
            if (isset($raw['action_calls']) && $raw['action_calls'] !== '') {
                $decoded = json_decode($raw['action_calls'], true);
                $metadata['action_calls'] = is_array($decoded) ? $decoded : [];
            }

            return [
                'content'           => $raw['content'] ?? '',
                'metadata'          => $metadata,
                'reasoning_content' => $raw['reasoning_content'] ?? '',
                'tool_calls'        => isset($raw['tool_calls'])
                    ? (array) json_decode($raw['tool_calls'], true)
                    : [],
            ];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /**
     * Delete the Redis Hash key after successful DB flush.
     */
    public function cleanup(string $conversationId): void
    {
        if (!$this->redisAvailable) {
            return;
        }

        try {
            $this->redis()->del($this->key($conversationId));
        } catch (\Throwable $e) {
            // Non-critical: key will expire via TTL
        }
    }

    /**
     * Check whether Redis is available for accumulation.
     */
    public function isRedisAvailable(): bool
    {
        return $this->redisAvailable;
    }

    // -------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------

    /**
     * Build the Redis key for a conversation.
     */
    private function key(string $conversationId): string
    {
        return self::KEY_PREFIX . $conversationId;
    }

    /**
     * Set a single hash field with TTL refresh.
     */
    private function hSet(string $conversationId, string $field, string $value): void
    {
        if (!$this->ensureRedis($conversationId)) {
            return;
        }

        $redis = $this->redis();
        $key = $this->key($conversationId);
        $redis->hSet($key, $field, $value);
    }

    /**
     * Ensure Redis connection is alive and TTL is set on the key.
     *
     * Returns false if Redis is unavailable (caller should degrade gracefully).
     */
    private function ensureRedis(string $conversationId): bool
    {
        if (!$this->redisAvailable) {
            return false;
        }

        try {
            $redis = $this->redis();
            $key = $this->key($conversationId);

            // Refresh TTL on every write to prevent premature expiry
            $redis->expire($key, self::TTL);

            return true;
        } catch (\Throwable $e) {
            $this->redisAvailable = false;
            return false;
        }
    }

    /**
     * Create and return a Redis connection instance.
     *
     * Uses phpredis extension with sensible defaults.
     */
    private function redis(): \Redis
    {
        static $instance = null;

        if ($instance === null) {
            $instance = new \Redis();
            $host = (string) env('REDIS_HOST', '127.0.0.1');
            $port = (int) env('REDIS_PORT', 6379);
            $timeout = 2.0;
            $instance->connect($host, $port, $timeout);

            $password = (string) env('REDIS_PASSWORD', '');
            if ($password !== '') {
                $instance->auth($password);
            }

            $database = (int) env('REDIS_DATABASE', 0);
            if ($database > 0) {
                $instance->select($database);
            }
        }

        return $instance;
    }
}
