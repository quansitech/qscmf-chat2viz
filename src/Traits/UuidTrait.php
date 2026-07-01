<?php

namespace Qscmf\Chat2Viz\Traits;

/**
 * UUID v4 generation and validation.
 */
trait UuidTrait
{
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        // Set version to 0100 (UUID v4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set variant to RFC 4122
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Validate a UUID v4 string.
     *
     * @param string $uid String to validate
     * @return bool True if valid UUID v4 format
     */
    public static function validateUuid(string $uid): bool
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        return preg_match($pattern, $uid) === 1;
    }
}
