<?php

declare(strict_types=1);

namespace HyperFields\Transfer;

/**
 * Request-scoped transfer audit context.
 *
 * Tracks whether execution is currently inside Transfer\Manager export/import,
 * allowing nested transfer logs to be de-duplicated.
 */
final class AuditContext
{
    private static int $managerDepth = 0;

    public static function enterManager(): void
    {
        self::$managerDepth++;
    }

    public static function leaveManager(): void
    {
        self::$managerDepth = max(0, self::$managerDepth - 1);
    }

    public static function isInsideManager(): bool
    {
        return self::$managerDepth > 0;
    }
}
