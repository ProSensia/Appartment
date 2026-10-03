<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Duty Scheduler  (deterministic chore rotation)
 * ---------------------------------------------------------------------------
 * Turns the static configuration in `chore_areas` into concrete, per-day,
 * per-person assignments in `chore_tasks`.
 *
 * ---------------------------------------------------------------------------
 *  WHY A FORMULA AND NOT A COUNTER
 * ---------------------------------------------------------------------------
 * A "last assignee + 1" counter breaks the moment someone joins or leaves:
 * everybody after them silently shifts. Instead the assignee is a pure
 * function of (date, pool size, offset):
 *
 *      daily  :  i = D(date) + offset                  (mod n)
 *      weekly :  i = floor(D(date) / 7) + offset       (mod n)
 *
 * where D(date) = days since 1970-01-01.
 *
 * Consequences, all of them good:
 *   * Any page load can recompute the whole horizon — no cron required.
 *   * INSERT ... ON DUPLICATE KEY makes generation idempotent, so a
 *     re-run never duplicates or shuffles a completed chore.
 *   * `rotation_offset` is the manual re-sync knob. After someone leaves you
 *     bump it so the cycle continues from the next person instead of
 *     restarting from index 0.
 *
 * ---------------------------------------------------------------------------
 *  SCOPES — the pool is what makes "Washroom A rotates among 3" work
 * ---------------------------------------------------------------------------
 *   common : every active resident of the apartment
 *   group  : only residents with chore_areas.duty_group_id = X
 *   room   : only residents allocated to chore_areas.room_id = Y
 *
 * The pool NEVER consults meal_participants. Opting out of a shared meal has
 * no effect on cleaning duties — that decoupling is the whole point.
 */

declare(strict_types=1);

final class DutyScheduler
{
    /** Days since the Unix epoch for a Y-m-d string. */
    private const EPOCH = '1970-01-01';

    /* ================================================================== */
    /*  Rotation pool                                                     */
    /* ================================================================== */

    /**
     * Ordered, de-duplicated list of residents eligible for an area.
     * Ordering is stable (users.id) so the rotation is reproducible.
     *
     * @return int[]
     */
    public static function poolFor(array $area): array
    {
        [$sql, $params] = match ($area['scope']) {
            'group' => [
                'SELECT id FROM users
                  WHERE apartment_id = :a AND status = :st AND duty_group_id = :g
                  ORDER BY id',
                ['a' => $area['apartment_id'], 'st' => 'active', 'g' => $area['duty_group_id']],
            ],
            'room' => [
                'SELECT id FROM users
                  WHERE apartment_id = :a AND status = :st AND room_id = :r
                  ORDER BY id',
                ['a' => $area['apartment_id'], 'st' => 'active', 'r' => $area['room_id']],
            ],
            default => [   // 'common'
                'SELECT id FROM users
                  WHERE apartment_id = :a AND status = :st
                  ORDER BY id',
                ['a' => $area['apartment_id'], 'st' => 'active'],
            ],
        };

        return array_map('intval', array_column(Database::all($sql, $params), 'id'));
    }

    /**
     * Zero-based rotation position for a date.
     *
     * @param int $poolSize must be >= 1
     */
    public static function positionFor(
        string $date,
        string $frequency,
        int $offset,
        int $poolSize
    ): int {
        if ($poolSize < 1) {
            return 0;
        }
        $days = (int) floor((strtotime($date . ' 00:00:00 UTC') - strtotime(self::EPOCH . ' 00:00:00 UTC')) / 86400);

        $tick = $frequency === 'weekly'
            ? intdiv($days, 7)
            : $days;

        // Safe modulo: PHP's % keeps the sign of the left operand.
        return (($tick + $offset) % $poolSize + $poolSize) % $poolSize;
    }

    /** Does this area need a task on this date? (weekly / weekday masks) */
    public static function runsOn(array $area, string $date): bool
    {
        $dow      = (int) date('N', strtotime($date . ' 00:00:00 UTC'));   // 1=Mon .. 7=Sun
        $bit      = 1 << ($dow - 1);
        $mask     = (int) $area['weekday_mask'];

        if ($area['frequency'] === 'weekdays') {
            return $dow <= 5;
        }
        if ($area['frequency'] === 'weekend') {
            return $dow >= 6;
        }
        // 'daily' relies on the mask too, so a masked area can be daily-partial.
        return ($mask & $bit) !== 0;
    }

    /**
     * Who is on duty for an area on a date, regardless of what is stored.
     * Returns null when the pool is empty.
     */
    public static function assigneeFor(array $area, string $date, ?array $pool = null): ?int
    {
        $pool = $pool ?? self::poolFor($area);
        if ($pool === []) {
            return null;
        }
        $pos = self::positionFor($date, (string) $area['frequency'], (int) $area['rotation_offset'], count($pool));
        return $pool[$pos] ?? null;
    }

    /* ================================================================== */
    /*  Generation                                                         */
    /* ================================================================== */

    /**
     * Materialise the rotation for every active area over a date window.
     * Idempotent: existing tasks are left untouched, including their status.
     *
     * @return array{created:int,skipped:int,areas:int,window:array{from:string,to:string},unassigned:int}
     */
    public static function generate(int $apartmentId, ?string $from = null, ?string $to = null): array
    {
        $from = $from ?? self::today(-(int) config('app.chore_horizon_back', 14));
        $to   = $to   ?? self::today((int) config('app.chore_horizon_forward', 21));

        $areas = Database::all(
            'SELECT * FROM chore_areas
              WHERE apartment_id = :a AND is_active = 1
              ORDER BY FIELD(frequency, "daily", "weekdays", "weekend", "weekly"), name',
            ['a' => $apartmentId]
        );

        $created = 0;
        $skipped = 0;
        $unassigned = 0;

        foreach ($areas as $area) {
            $pool = self::poolFor($area);
            $n    = count($pool);
            $one  = match ($area['frequency']) {
                'weekly' => '1',
                'weekdays' => '5',
                'weekend'  => '2',
                default    => '7',
            };
            $lo = new DateTimeImmutable($from, new DateTimeZone('UTC'));
            $hi = new DateTimeImmutable($to,   new DateTimeZone('UTC'));

            for ($d = $lo; $d <= $hi; $d = $d->modify('+' . $one . ' days')) {
                $date = $d->format('Y-m-d');
                if (!self::runsOn($area, $date)) {
                    continue;
                }

                $assignee = $n > 0
                    ? $pool[self::positionFor($date, (string) $area['frequency'], (int) $area['rotation_offset'], $n)]
                    : null;

                if ($assignee === null) {
                    $unassigned++;
                }

                // INSERT IGNORE keeps done/verified rows exactly as they are.
                $inserted = Database::insertOrIgnore('chore_tasks', [
                    'chore_area_id'    => (int) $area['id'],
                    'assigned_user_id' => $assignee,
                    'task_date'        => $date,
                    'status'           => 'pending',
                ]);
                if ($inserted > 0) {
                    $created++;
                } else {
                    $skipped++;
                }
            }
        }

        return [
            'created'    => $created,
            'skipped'    => $skipped,
            'areas'      => count($areas),
            'unassigned' => $unassigned,
            'window'     => ['from' => $from, 'to' => $to],
        ];
    }

    /**
     * Rebuild one area's future rotation. Completed work is preserved; only
     * pending rows are realigned to the current pool. Use after a resident
     * joins or leaves so the cycle re-syncs.
     */
    public static function rebuildArea(int $areaId, bool $includeToday = false): array
    {
        $area = Database::one('SELECT * FROM chore_areas WHERE id = :id', ['id' => $areaId]);
        if ($area === null) {
            throw new InvalidArgumentException('Chore area not found: ' . $areaId);
        }

        $pool = self::poolFor($area);
        $n    = count($pool);
        $from = self::today($includeToday ? 0 : 1);
        $to   = self::today((int) config('app.chore_horizon_forward', 21));

        $reassigned = 0;
        $lo = new DateTimeImmutable($from, new DateTimeZone('UTC'));
        $hi = new DateTimeImmutable($to,   new DateTimeZone('UTC'));

        for ($d = $lo; $d <= $hi; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');
            if (!self::runsOn($area, $date)) {
                continue;
            }
            $assignee = $n > 0
                ? $pool[self::positionFor($date, (string) $area['frequency'], (int) $area['rotation_offset'], $n)]
                : null;

            $stmt = Database::query(
                'INSERT INTO chore_tasks (chore_area_id, assigned_user_id, task_date, status)
                      VALUES (:area, :user, :date, :status)
                   ON DUPLICATE KEY UPDATE
                        assigned_user_id = IF(status = :pending, VALUES(assigned_user_id), assigned_user_id)',
                [
                    'area'  => $areaId,
                    'user'  => $assignee,
                    'date'  => $date,
                    'status'=> 'pending',
                    'pending' => 'pending',
                ]
            );
            $reassigned += $stmt->rowCount();
        }

        return ['area_id' => $areaId, 'pool_size' => $n, 'pool' => $pool, 'touched' => $reassigned];
    }

    /** Re-sync every area after a membership change. */
    public static function rebuildAll(int $apartmentId): array
    {
        $ids = array_column(
            Database::all('SELECT id FROM chore_areas WHERE apartment_id = :a AND is_active = 1',
                ['a' => $apartmentId]),
            'id'
        );
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = self::rebuildArea((int) $id);
        }
        return $out;
    }

    /**
     * Nudge an area's rotation by $steps positions. The recovery tool when
     * somebody leaves mid-cycle and you want the next person to be up.
     */
    public static function rotate(int $areaId, int $steps = 1): array
    {
        $area = Database::one('SELECT * FROM chore_areas WHERE id = :id', ['id' => $areaId]);
        if ($area === null) {
            throw new InvalidArgumentException('Chore area not found: ' . $areaId);
        }
        $poolSize = max(1, count(self::poolFor($area)));
        $newOffset = (((int) $area['rotation_offset'] + $steps) % $poolSize + $poolSize) % $poolSize;

        Database::update('chore_areas', ['rotation_offset' => $newOffset], 'id', $areaId);
        $result = self::rebuildArea($areaId);

        return ['area_id' => $areaId, 'rotation_offset' => $newOffset, 'pool_size' => $poolSize] + $result;
    }

    /* ================================================================== */
    /*  Queries                                                            */
    /* ================================================================== */

    /**
     * The task dashboard for a date range.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function board(int $apartmentId, string $from, string $to, ?int $userId = null): array
    {
        $sql = 'SELECT t.id AS task_id, t.task_date, t.status, t.completed_at, t.notes, t.proof_photo,
                       t.assigned_user_id,
                       u.full_name AS assignee_name, u.participant_code, u.avatar_color,
                       c.full_name AS completed_by_name,
                       v.full_name AS verified_by_name,
                       a.id AS area_id, a.name AS area_name, a.icon AS area_icon,
                       a.scope, a.frequency, a.points, a.description, a.duty_group_id,
                       g.name AS duty_group, g.color AS duty_group_color,
                       r.code AS room_code
                  FROM chore_tasks t
                  JOIN chore_areas a ON a.id = t.chore_area_id
                  LEFT JOIN users u       ON u.id = t.assigned_user_id
                  LEFT JOIN users c       ON c.id = t.completed_by
                  LEFT JOIN users v       ON v.id = t.verified_by
                  LEFT JOIN duty_groups g ON g.id = a.duty_group_id
                  LEFT JOIN rooms r       ON r.id = a.room_id
                 WHERE a.apartment_id = :a AND t.task_date BETWEEN :from AND :to';
        $params = ['a' => $apartmentId, 'from' => $from, 'to' => $to];

        if ($userId !== null) {
            $sql .= ' AND t.assigned_user_id = :u1';
            $params['u1'] = $userId;
        }
        $sql .= ' ORDER BY t.task_date, FIELD(t.status,"pending","done","verified","skipped"), a.name';

        $rows = Database::all($sql, $params);
        return array_map([self::class, 'decorateTask'], $rows);
    }

    /** Add the derived fields the UI needs (is_mine, is_overdue, weekday…). */
    public static function decorateTask(array $r): array
    {
        $today   = self::today();
        $date    = (string) $r['task_date'];
        $status  = (string) $r['status'];

        // Callers alias the primary key differently; normalise to `id` so the
        // client never has to care which query it came from.
        $r['id']     = (int) ($r['task_id'] ?? $r['id'] ?? 0);
        $r['icon']   = (string) ($r['icon'] ?? $r['area_icon'] ?? 'bi-stars');
        $r['area_name'] = (string) ($r['area_name'] ?? 'Chore');
        $r['assignee_name'] = $r['assignee_name'] ?? null;

        $r['is_today']    = $date === $today;
        $r['is_past']     = $date < $today;
        $r['is_overdue']  = $date < $today && $status === 'pending';
        $r['is_open']     = $status === 'pending';

        $mine = Auth::id();
        $r['is_mine']     = $mine !== null && (int) ($r['assigned_user_id'] ?? 0) === $mine;
        $r['is_unassigned'] = $r['assigned_user_id'] === null;

        $r['day_label']   = $r['is_today'] ? 'Today'
                          : ($date === self::today(1) ? 'Tomorrow' : self::weekdayName($date));
        $r['badge']       = match ($status) {
            'pending'  => $r['is_past'] ? 'Overdue' : ($r['is_today'] ? 'Due today' : 'Upcoming'),
            'done'     => 'Done',
            'verified' => 'Verified',
            default    => 'Skipped',
        };
        $r['badge_class'] = match ($status) {
            'pending'  => $r['is_past'] ? 'danger' : ($r['is_today'] ? 'warning' : 'secondary'),
            'done'     => 'success',
            'verified' => 'primary',
            default    => 'dark',
        };
        return $r;
    }

    /**
     * Upcoming duties for one person.
     *
     * @return array{today:array,upcoming:array,overdue:array,count:int}
     */
    public static function forUser(int $apartmentId, int $userId, int $days = 14): array
    {
        $today = self::today();
        $rows  = self::board($apartmentId, $today, self::today($days), $userId);

        $out = ['today' => [], 'upcoming' => [], 'overdue' => [], 'count' => count($rows)];
        foreach ($rows as $r) {
            if ($r['is_overdue'])                     { $out['overdue'][]   = $r; }
            elseif ($r['is_today'])                   { $out['today'][]     = $r; }
            else                                      { $out['upcoming'][]  = $r; }
        }
        return $out;
    }

    /**
     * Fairness report — how the load is distributed over a window.
     * Flags anyone carrying noticeably more than an even share.
     */
    public static function fairness(int $apartmentId, ?int $days = null, ?int $areaId = null): array
    {
        $days = $days ?? 28;
        $sql = 'SELECT t.assigned_user_id AS uid,
                       u.full_name, u.participant_code, u.avatar_color, u.room_id,
                       COUNT(*) AS total,
                       SUM(t.status IN ("done","verified")) AS completed,
                       SUM(t.status = "skipped")            AS skipped,
                       SUM(t.status = "pending")            AS pending,
                       COALESCE(SUM(t.status = "pending" AND t.task_date < :today), 0) AS overdue,
                       COALESCE(SUM(a.points), 0)           AS points
                  FROM chore_tasks t
                  JOIN chore_areas a ON a.id = t.chore_area_id
                  JOIN users u       ON u.id = t.assigned_user_id
                 WHERE a.apartment_id = :a
                   AND t.task_date BETWEEN :from AND :to';
        $params = [
            'a'     => $apartmentId,
            'today' => self::today(),
            'from'  => self::today(-$days),
            'to'    => self::today(0),
        ];
        if ($areaId !== null) {
            $sql .= ' AND t.chore_area_id = :area';
            $params['area'] = $areaId;
        }
        $sql .= ' GROUP BY t.assigned_user_id, u.full_name, u.participant_code, u.avatar_color, u.room_id
                  ORDER BY total DESC';

        $rows = array_map(static function (array $r): array {
            return [
                'user_id'   => (int) $r['uid'],
                'full_name' => $r['full_name'],
                'code'      => $r['participant_code'],
                'avatar'    => $r['avatar_color'],
                'total'     => (int) $r['total'],
                'completed' => (int) $r['completed'],
                'skipped'   => (int) $r['skipped'],
                'pending'   => (int) $r['pending'],
                'overdue'   => (int) $r['overdue'],
                'points'    => (int) $r['points'],
                'completion_rate' => (int) $r['total'] > 0
                    ? round((int) $r['completed'] / (int) $r['total'] * 100, 1)
                    : 0.0,
            ];
        }, Database::all($sql, $params));

        $totals = array_sum(array_column($rows, 'total'));
        $ideal  = $rows === [] ? 0.0 : $totals / count($rows);

        foreach ($rows as $i => $r) {
            $rows[$i]['share_percent'] = $totals > 0 ? round($r['total'] / $totals * 100, 1) : 0.0;
            $rows[$i]['variance']      = $ideal > 0 ? round(($r['total'] - $ideal) / $ideal * 100, 1) : 0.0;
            $rows[$i]['verdict']       = $rows[$i]['variance'] > 15  ? 'over'
                                       : ($rows[$i]['variance'] < -15 ? 'under' : 'fair');
        }

        return [
            'window_days' => $days,
            'window'      => ['from' => $params['from'], 'to' => $params['to']],
            'total_tasks' => $totals,
            'ideal_each'  => round($ideal, 1),
            'rows'        => $rows,
        ];
    }

    /**
     * Who is up next in an area, with a look-ahead so residents can plan.
     *
     * @return array<int,array{date:string,user_id:?int,full_name:?string,position:int,is_today:bool}>
     */
    public static function upcoming(int $areaId, int $count = 7): array
    {
        $area = Database::one('SELECT * FROM chore_areas WHERE id = :id', ['id' => $areaId]);
        if ($area === null) {
            throw new InvalidArgumentException('Chore area not found: ' . $areaId);
        }
        $pool = self::poolFor($area);
        $n    = max(1, count($pool));

        $names = [];
        if ($pool !== []) {
            $in    = implode(',', array_fill(0, count($pool), '?'));
            $names = array_column(
                Database::query("SELECT id, full_name FROM users WHERE id IN ($in)", $pool)->fetchAll(),
                'full_name',
                'id'
            );
        }

        $out  = [];
        $date = new DateTimeImmutable(self::today(), new DateTimeZone('UTC'));
        $seen = 0;
        for ($i = 0; $seen < $count && $i < $count * 4; $i++) {
            $d     = $date->modify('+' . $i . ' days')->format('Y-m-d');
            if (!self::runsOn($area, $d)) {
                continue;
            }
            $uid = $pool === [] ? null : $pool[self::positionFor($d, (string) $area['frequency'], (int) $area['rotation_offset'], $n)];

            $out[] = [
                'date'       => $d,
                'weekday'    => self::weekdayName($d),
                'user_id'    => $uid,
                'full_name'  => $uid === null ? null : ($names[(string) $uid] ?? null),
                'position'   => $uid === null ? -1 : self::positionFor($d, (string) $area['frequency'], (int) $area['rotation_offset'], $n),
                'pool_size'  => count($pool),
                'is_today'   => $d === self::today(),
            ];
            $seen++;
        }
        return $out;
    }

    /* ================================================================== */
    /*  Mutations                                                          */
    /* ================================================================== */

    /**
     * Tick a task off. Only the assignee, an admin, or anyone when the task
     * is unassigned (pool empty) may complete it.
     */
    public static function complete(int $taskId, int $actorId, bool $isAdmin, ?string $note = null, ?string $proof = null): array
    {
        $task = Database::one(
            'SELECT t.*, a.name AS area_name, a.apartment_id, a.points, a.is_mandatory
               FROM chore_tasks t
               JOIN chore_areas a ON a.id = t.chore_area_id
              WHERE t.id = :id',
            ['id' => $taskId]
        );
        if ($task === null) {
            throw new RuntimeException('That chore no longer exists.');
        }
        if ((int) $task['assigned_user_id'] !== $actorId && !$isAdmin && $task['assigned_user_id'] !== null) {
            throw new RuntimeException('This chore is assigned to someone else. Ask an admin if you covered for them.');
        }
        if (in_array($task['status'], ['done', 'verified'], true)) {
            throw new RuntimeException('This chore is already complete.');
        }

        Database::update('chore_tasks', [
            'status'       => 'done',
            'completed_at' => gmdate('Y-m-d H:i:s'),
            'completed_by' => $actorId,
            'notes'        => $note,
            'proof_photo'  => $proof,
        ], 'id', $taskId);

        ActivityLog::record('chore.completed', 'chore_task', $taskId,
            sprintf('%s completed "%s"', self::actorName($actorId), $task['area_name']));

        return self::task($taskId);
    }

    /** Admin verification pass — locks a done chore as verified. */
    public static function verify(int $taskId, int $actorId, ?string $note = null): array
    {
        $task = Database::one('SELECT * FROM chore_tasks WHERE id = :id', ['id' => $taskId]);
        if ($task === null) {
            throw new RuntimeException('That chore no longer exists.');
        }
        if ($task['status'] !== 'done') {
            throw new RuntimeException('Only a completed chore can be verified.');
        }

        Database::update('chore_tasks', [
            'status'      => 'verified',
            'verified_by' => $actorId,
            'verified_at' => gmdate('Y-m-d H:i:s'),
            'notes'       => $note ?? $task['notes'],
        ], 'id', $taskId);

        ActivityLog::record('chore.verified', 'chore_task', $taskId);
        return self::task($taskId);
    }

    /** Mark a chore as deliberately skipped (needs a reason). */
    public static function skip(int $taskId, int $actorId, bool $isAdmin, string $reason): array
    {
        $task = Database::one(
            'SELECT t.*, a.apartment_id FROM chore_tasks t
               JOIN chore_areas a ON a.id = t.chore_area_id WHERE t.id = :id',
            ['id' => $taskId]
        );
        if ($task === null) {
            throw new RuntimeException('That chore no longer exists.');
        }
        if (!$isAdmin && (int) $task['assigned_user_id'] !== $actorId) {
            throw new RuntimeException('Only the assignee or an admin can skip a chore.');
        }
        if (in_array($task['status'], ['done', 'verified'], true)) {
            throw new RuntimeException('This chore is already complete — verify it instead of skipping.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('A skip needs a reason so the cycle stays auditable.');
        }

        Database::update('chore_tasks', [
            'status'     => 'skipped',
            'notes'      => $reason,
            'verified_by'=> $actorId,
            'verified_at'=> gmdate('Y-m-d H:i:s'),
        ], 'id', $taskId);

        // Hand the duty to the next person in the pool for that date.
        // Safe to always do: done/verified rows were rejected above.
        $area = Database::one('SELECT * FROM chore_areas WHERE id = :id', ['id' => $task['chore_area_id']]);
        $next = self::nextInPool($area, (string) $task['task_date'], (int) $task['assigned_user_id']);
        if ($next !== null) {
            Database::update('chore_tasks', ['assigned_user_id' => $next], 'id', $taskId);
        }

        ActivityLog::record('chore.skipped', 'chore_task', $taskId, $reason);
        return self::task($taskId);
    }

    /** Reassign a single task (admin override). */
    public static function reassign(int $taskId, int $newUserId): array
    {
        $ok = Database::value(
            "SELECT 1 FROM users WHERE id = :u1 AND status = 'active'",
            ['u1' => $newUserId]
        );
        if ($ok === null) {
            throw new RuntimeException('That person is not an active resident.');
        }
        Database::update('chore_tasks', ['assigned_user_id' => $newUserId], 'id', $taskId);
        ActivityLog::record('chore.reassigned', 'chore_task', $taskId, 'now user #' . $newUserId);
        return self::task($taskId);
    }

    public static function task(int $taskId): array
    {
        $row = Database::one(
            'SELECT t.id AS task_id, t.task_date, t.status, t.completed_at, t.notes, t.proof_photo,
                    t.assigned_user_id,
                    u.full_name AS assignee_name, u.avatar_color, u.participant_code,
                    a.id AS area_id, a.name AS area_name, a.icon AS area_icon,
                    a.scope, a.points, a.apartment_id, a.description
               FROM chore_tasks t
               JOIN chore_areas a ON a.id = t.chore_area_id
               LEFT JOIN users u ON u.id = t.assigned_user_id
              WHERE t.id = :id',
            ['id' => $taskId]
        );
        return $row === null ? [] : self::decorateTask($row);
    }

    /** The person after $userId in the rotation pool for a given date. */
    private static function nextInPool(array $area, string $date, int $userId): ?int
    {
        $pool = self::poolFor($area);
        if ($pool === [] || count($pool) < 2) {
            return $pool[0] ?? null;
        }
        $current = self::positionFor($date, (string) $area['frequency'], (int) $area['rotation_offset'], count($pool));
        $mine    = array_search($userId, $pool, true);
        $start   = $mine === false ? $current : $mine;

        for ($step = 1; $step <= count($pool); $step++) {
            $candidate = $pool[($start + $step) % count($pool)];
            if ($candidate !== $userId) {
                return $candidate;
            }
        }
        return null;
    }

    /* ================================================================== */
    /*  Dates                                                             */
    /* ================================================================== */

    public static function today(int $offsetDays = 0): string
    {
        return gmdate('Y-m-d', strtotime("today $offsetDays days"));
    }

    public static function weekdayName(string $date, bool $short = false): string
    {
        $dow = (int) date('N', strtotime($date . ' 00:00:00 UTC'));   // 1 = Mon
        $names = $short
            ? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']
            : ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        return $names[$dow - 1] ?? '';
    }

    /**
 * Render a weekday bitmask as human text.
 *
 * @return array{label:string,days:array<int,string>,all_days:bool}
 */
    public static function describeWeekdays(int $mask): array
    {
        $short = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $days  = [];
        for ($dow = 1; $dow <= 7; $dow++) {
            if (($mask & (1 << ($dow - 1))) !== 0) {
                $days[] = $short[$dow - 1];
            }
        }

        if ($days === []) {
            $label = 'Never';
        } elseif (count($days) === 7) {
            $label = 'Every day';
        } elseif ($days === ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']) {
            $label = 'Weekdays';
        } elseif ($days === ['Sat', 'Sun']) {
            $label = 'Weekends';
        } else {
            $label = implode(', ', $days);
        }

        return ['label' => $label, 'days' => $days, 'all_days' => count($days) === 7];
    }

    /** Monday of the week containing $date. */
    public static function weekStart(string $date): string
    {
        $ts  = strtotime($date . ' 00:00:00 UTC');
        $dow = (int) date('N', $ts);
        return gmdate('Y-m-d', $ts - (($dow - 1) * 86400));
    }

    private static function actorName(int $userId): string
    {
        return (string) Database::value('SELECT full_name FROM users WHERE id = :id', ['id' => $userId]);
    }
}
