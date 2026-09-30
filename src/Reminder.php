<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  In-app reminders
 * ---------------------------------------------------------------------------
 * Backs the header notification bell: chore nudges, meal opt-in deadlines,
 * balance alerts, vote requests, broadcast notices.
 *
 * user_id = NULL means "broadcast to everyone in the apartment".
 */

declare(strict_types=1);

final class Reminder
{
    /** Queue a reminder. */
    public static function push(
        int $apartmentId,
        ?int $userId,
        string $type,
        string $title,
        ?string $body = null,
        string $severity = 'info',
        ?string $refTable = null,
        ?int $refId = null,
        ?string $dueAt = null
    ): int {
        return Database::insert('reminders', [
            'apartment_id' => $apartmentId,
            'user_id'      => $userId,
            'type'         => $type,
            'title'        => mb_substr($title, 0, 160),
            'body'         => $body === null ? null : mb_substr($body, 0, 500),
            'severity'     => $severity,
            'ref_table'    => $refTable,
            'ref_id'       => $refId,
            'due_at'       => $dueAt,
        ]);
    }

    /** The current user's feed — unread first, then soonest due. */
    public static function inbox(int $apartmentId, int $userId, int $limit = 20): array
    {
        $rows = Database::all(
            'SELECT * FROM reminders
              WHERE apartment_id = :a
                AND (user_id = :u OR user_id IS NULL)
                AND (read_at IS NULL OR read_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY))
              ORDER BY (read_at IS NOT NULL), due_at IS NULL, due_at, created_at DESC
              LIMIT :lim',
            ['a' => $apartmentId, 'u' => $userId, 'lim' => max(1, min(100, $limit))]
        );

        return array_map(static function (array $r): array {
            $r['icon']      = self::iconFor((string) $r['type']);
            $r['is_unread'] = $r['read_at'] === null;
            $r['is_due']    = $r['due_at'] !== null && $r['due_at'] < gmdate('Y-m-d H:i:s');
            $r['ago']       = ActivityLog::ago((string) $r['created_at']);
            return $r;
        }, $rows);
    }

    public static function unreadCount(int $apartmentId, int $userId): int
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM reminders
              WHERE apartment_id = :a AND (user_id = :u OR user_id IS NULL) AND read_at IS NULL',
            ['a' => $apartmentId, 'u' => $userId]
        );
    }

    public static function markRead(int $reminderId, int $userId): int
    {
        return Database::update('reminders', ['read_at' => gmdate('Y-m-d H:i:s')], 'id', $reminderId);
    }

    public static function markAllRead(int $apartmentId, int $userId): int
    {
        return Database::query(
            'UPDATE reminders SET read_at = UTC_TIMESTAMP()
              WHERE apartment_id = :a AND (user_id = :u OR user_id IS NULL) AND read_at IS NULL',
            ['a' => $apartmentId, 'u' => $userId]
        )->rowCount();
    }

    private static function iconFor(string $type): string
    {
        return match ($type) {
            'chore'        => 'bi-stars',
            'meal_optin'   => 'bi-egg-fried',
            'balance'      => 'bi-cash-stack',
            'vote'         => 'bi-hand-thumbs-up',
            'announcement' => 'bi-megaphone',
            'system'       => 'bi-info-circle',
            default        => 'bi-bell',
        };
    }
}
