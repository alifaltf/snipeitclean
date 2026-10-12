<?php

namespace App\Services\AssetUniqueness;

use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * ERS: recognises a write that MySQL/MariaDB stopped because of another
 * transaction (deadlock, error 1213 / SQLSTATE 40001, or lock wait timeout,
 * error 1205). With the live uniqueness keys this is how a writer can lose
 * a race against an import holding the same value: the database rolls the
 * victim's transaction back. It is reported as "busy, try again", never as
 * a uniqueness error, and its message (which embeds SQL and values) is
 * never shown or logged.
 */
final class ConcurrentWriteConflict
{
    public const DEADLOCK = 1213;

    public const LOCK_WAIT_TIMEOUT = 1205;

    /** The MySQL error code (1213 or 1205), or null for anything else. */
    public static function detect(Throwable $exception): ?int
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $info = match (true) {
                $current instanceof QueryException => $current->errorInfo,
                $current instanceof PDOException => $current->errorInfo,
                default => null,
            };
            if (! is_array($info)) {
                continue;
            }
            $code = (int) ($info[1] ?? 0);
            if (($code === self::DEADLOCK && ($info[0] ?? null) === '40001') || ($code === self::LOCK_WAIT_TIMEOUT && ($info[0] ?? null) === 'HY000')) {
                return $code;
            }
        }

        return null;
    }
}
