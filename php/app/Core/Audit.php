<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * The append-only record of everything an admin does. Port of src/lib/audit.ts.
 *
 * Two properties this class exists to guarantee:
 *
 * APPEND-ONLY. This file contains exactly two statements: the INSERT in log()
 * and the SELECT that recent() reads the table back with. There is no UPDATE
 * and no DELETE, here or anywhere else in the application. (The claim used to
 * be "one statement in this file and it is an INSERT", which read well and was
 * not true - recent() has been here since the audit viewer was written. A
 * guarantee that is stated wrongly is a guarantee nobody can check, and this
 * one is checked, by tests/Core/AuditTest.php, against the source of this
 * file.) On a host that allows triggers, db/migrations/0001_init.sql writes
 * out the two that enforce the same rule inside the database; on Hostinger's
 * single-user grant, this class is the enforcement.
 *
 * NEVER THROWS. A failed audit write is logged loudly and swallowed. The
 * action it describes has already happened - throwing here would roll back a
 * price publish because the diary was full, and would hide the event instead
 * of recording it.
 */
final class Audit
{
    /** Detail is a JSON column, not a bucket: a huge blob in it is a bug, so it is capped. */
    private const MAX_DETAIL = 16000;

    /**
     * @param string              $actor  admin email, or "system" for cron and seeds
     * @param string              $action verb_noun, e.g. "rates_published", "admin_added"
     * @param array<string,mixed> $detail what changed - never a password, token or full card of PII
     */
    public static function log(string $actor, string $action, array $detail = [], ?Request $request = null): void
    {
        try {
            $json = Db::json(self::clamp($detail));

            // Caps, not hand-written numbers: the widths belong to the schema
            // and are mirrored in exactly one place. Truncating rather than
            // refusing is deliberate here - see the note on Caps::fit().
            Db::insert('audit_log', [
                'at'         => Clock::sql(),
                'actor'      => Caps::fit('audit_log.actor', $actor !== '' ? $actor : 'system'),
                'action'     => Caps::fit('audit_log.action', $action),
                'detail'     => $json,
                'ip_hash'    => $request === null ? null : Ip::hash($request->ip()),
                'user_agent' => $request === null ? null : Caps::fit('audit_log.user_agent', $request->userAgent()),
            ]);
        } catch (\Throwable $e) {
            Log::exception('audit_write_failed', $e, ['actor' => Str::maskEmail($actor), 'action' => $action]);
        }
    }

    /**
     * Recent entries, newest first. Reading is the only other thing anyone
     * does with this table.
     *
     * @return list<array<string,mixed>>
     */
    public static function recent(int $limit = 100, int $offset = 0): array
    {
        return Db::all(
            'SELECT id, at, actor, action, detail, ip_hash, user_agent
               FROM audit_log
              ORDER BY at DESC, id DESC
              LIMIT ? OFFSET ?',
            [max(1, min(500, $limit)), max(0, $offset)]
        );
    }

    /**
     * @param  array<string,mixed> $detail
     * @return array<string,mixed>
     */
    private static function clamp(array $detail): array
    {
        $encoded = json_encode($detail);
        if ($encoded !== false && strlen($encoded) <= self::MAX_DETAIL) {
            return $detail;
        }

        return ['truncated' => true, 'keys' => array_slice(array_keys($detail), 0, 40)];
    }
}
