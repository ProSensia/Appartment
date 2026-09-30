<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Dashboard + chore-area admin
 * ---------------------------------------------------------------------------
 * Aggregates the one screen a resident opens every day, and owns CRUD for
 * chore_areas (the rotation rules). Everything else reads through here so
 * the home page costs a fixed, small number of queries.
 */

declare(strict_types=1);

final class DashboardService
{
    /* ================================================================== */
    /*  Home screen                                                       */
    /* ================================================================== */

    /**
     * Everything the dashboard renders, in one payload.
     */
    public static function forUser(int $apartmentId, ?int $userId = null): array
    {
        $me     = $userId ?? (int) Auth::id();
        $today  = DutyScheduler::today();
        $week   = DutyScheduler::today(6);

return [
            'today'         => $today,
            'me'            => Auth::user(),
            'greeting'      => self::greeting(),
            'apartment'     => [
                'name'        => (string) config('app.name', 'FlatMate'),
                'household'   => self::household($apartmentId),
            ],

            // ---- chores -------------------------------------------------
            'chores'        => [
                'mine_today'   => DutyScheduler::forUser($apartmentId, $me, 1),
                'mine_week'    => DutyScheduler::forUser($apartmentId, $me, 7),
                'needs_verify' => self::needsVerification($apartmentId),
                'fairness'     => DutyScheduler::fairness($apartmentId),
            ],

            // ---- meals --------------------------------------------------
            'meals'         => [
                'today'        => self::todayMeals($apartmentId, $today),
                'coverage'     => self::mealCoverage($apartmentId, $week),
            ],

            // ---- money --------------------------------------------------
            'money'         => [
                'me'           => BalanceEngine::statementFor($apartmentId, $me),
                'summary'      => BalanceEngine::summary($apartmentId),
                'suggestion'   => DebtSimplifier::simplify(
                    self::netBalances($apartmentId),
                    'auto'
                ),
            ],

            // ---- social -------------------------------------------------
            'notices'       => array_slice(NoticeBoard::feed($apartmentId, $me, 5), 0, 5),
            'reminders'     => Reminder::inbox($apartmentId, $me, 6),
            'activity'      => ActivityLog::feed($apartmentId, 8),
        ];
    }

    /**
 * Net balance per user id, the exact shape DebtSimplifier wants.
 *
 * Only settled (net == 0) residents are dropped, so the map always sums to
 * zero and can be handed straight to the settlement algorithm.
 *
 * @return array<int,int> user_id => net cents
 */
private static function netBalances(int $apartmentId): array
    {
        $out = [];
        foreach (BalanceEngine::memberBalances($apartmentId, false) as $userId => $row) {
            $out[$userId] = (int) $row['net_cents'];
        }
        return $out;
    }

    /** Household composition, grouped for the roster card. */
    private static function household(int $apartmentId): array
    {
        $rows = Database::all(
            "SELECT id, full_name, avatar_color, status, role, room_id, duty_group_id
               FROM users WHERE apartment_id = :a AND status <> 'offboarded'
              ORDER BY FIELD(status, 'active', 'invited', 'suspended'), full_name",
            ['a' => $apartmentId]
        );
        return array_map(static fn(array $u): array => [
            'id'      => (int) $u['id'],
            'name'    => (string) $u['full_name'],
            'avatar'  => (string) $u['avatar_color'],
            'status'  => (string) $u['status'],
            'role'    => (string) $u['role'],
            'room_id' => $u['room_id'] === null ? null : (int) $u['room_id'],
        ], $rows);
    }

    /** The three meal slots for today, with this user's opt-in state. */
    private static function todayMeals(int $apartmentId, string $date): array
    {
        $me = (int) Auth::id();
        return Database::all(
            "SELECT m.id, m.meal_type, m.menu_title, m.menu_notes, m.cook_user_id,
                    m.status, p.locked,
                    u.full_name AS cook_name,
                    mp.status AS my_status, mp.is_cooking
               FROM meals m
               JOIN meal_plans p   ON p.id = m.meal_plan_id
               LEFT JOIN users u   ON u.id = m.cook_user_id
               LEFT JOIN meal_participants mp
                      ON mp.meal_id = m.id AND mp.user_id = :me
              WHERE p.apartment_id = :a AND p.week_start <= :d1
                AND p.week_start >= DATE_SUB(:d2, INTERVAL 6 DAY)
              ORDER BY m.day_of_week, FIELD(m.meal_type, 'breakfast', 'lunch', 'dinner')",
            ['a' => $apartmentId, 'd1' => $date, 'd2' => $date, 'me' => $me]
        );
    }

    /** Who is covered to eat on each day this week. */
    private static function mealCoverage(int $apartmentId, string $to): array
    {
        $rows = Database::all(
            'SELECT m.day_of_week, m.meal_type,
                    SUM(mp.status = "eating") AS eaters,
                    SUM(mp.is_cooking = 1)    AS cooks,
                    SUM(mp.status = "opting_out") AS opting_out
               FROM meals m
               JOIN meal_plans p ON p.id = m.meal_plan_id
               LEFT JOIN meal_participants mp ON mp.meal_id = m.id
              WHERE p.apartment_id = :a AND p.week_start <= :to
                AND p.week_start >= DATE_SUB(:to, INTERVAL 6 DAY)
              GROUP BY m.day_of_week, m.meal_type',
            ['a' => $apartmentId, 'to' => $to]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['day_of_week']][(string) $r['meal_type']] = [
                'eaters'     => (int) $r['eaters'],
                'cooks'      => (int) $r['cooks'],
                'opting_out' => (int) $r['opting_out'],
            ];
        }
        return $out;
    }

    /** Chores waiting for an admin to verify. */
    private static function needsVerification(int $apartmentId): array
    {
        return array_map(
            [DutyScheduler::class, 'decorateTask'],
            Database::all(
                "SELECT t.*, a.name AS area_name, a.apartment_id, a.points, a.is_mandatory,
                        u.full_name AS assignee_name, u.avatar_color AS assignee_avatar
                   FROM chore_tasks t
                   JOIN chore_areas a ON a.id = t.chore_area_id
                   LEFT JOIN users u   ON u.id = t.assigned_user_id
                  WHERE a.apartment_id = :a AND t.status = 'done'
                  ORDER BY t.task_date DESC
                  LIMIT 20",
                ['a' => $apartmentId]
            )
        );
    }

    private static function greeting(): string
    {
        $hour = (int) date('G', time());
        $name = explode(' ', (string) (Auth::user()['full_name'] ?? 'there'))[0];
        return match (true) {
            $hour < 5  => 'Up late, ' . $name,
            $hour < 12 => 'Good morning, ' . $name,
            $hour < 17 => 'Good afternoon, ' . $name,
            $hour < 22 => 'Good evening, ' . $name,
            default    => 'Good night, ' . $name,
        };
    }

    /* ================================================================== */
    /*  Chore areas (rotation rules)                                      */
    /* ================================================================== */

    /** All areas with pool size, today's assignee and upcoming run. */
    public static function choreAreas(int $apartmentId): array
    {
        $areas = Database::all(
            'SELECT a.*, r.code AS room_code, g.name AS group_name
               FROM chore_areas a
               LEFT JOIN rooms r       ON r.id = a.room_id
               LEFT JOIN duty_groups g ON g.id = a.duty_group_id
              WHERE a.apartment_id = :a
              ORDER BY a.is_active DESC,
                       FIELD(a.frequency, "daily", "weekdays", "weekend", "weekly"),
                       a.name',
            ['a' => $apartmentId]
        );

        $today = DutyScheduler::today();
        return array_map(
            static function (array $a) use ($today): array {
                $a['pool']      = DutyScheduler::poolFor($a);
                $a['pool_size'] = count($a['pool']);
                $a['runs_today']= DutyScheduler::runsOn($a, $today);
                $a['assignee_today'] = DutyScheduler::assigneeFor($a, $today);
                $a['frequency_label'] = match ($a['frequency']) {
                    'daily'    => 'Every day',
                    'weekdays' => 'Mon–Fri',
                    'weekend'  => 'Sat & Sun',
                    default    => 'Weekly',
                };
                $a['weekday_list'] = DutyScheduler::describeWeekdays((int) $a['weekday_mask']);
                $a['scope_label']  = match ($a['scope']) {
                    'group' => $a['group_name'] ?? 'Duty group',
                    'room'  => 'Room ' . ($a['room_code'] ?? '?'),
                    default => 'Everyone',
                };
                return $a;
            },
            $areas
        );
    }

    /** One area plus its actual generated tasks for the next two weeks. */
    public static function choreArea(int $apartmentId, int $id): array
    {
        $area = Database::one(
            'SELECT a.*, r.code AS room_code, g.name AS group_name
               FROM chore_areas a
               LEFT JOIN rooms r       ON r.id = a.room_id
               LEFT JOIN duty_groups g ON g.id = a.duty_group_id
              WHERE a.apartment_id = :a AND a.id = :id',
            ['a' => $apartmentId, 'id' => $id]
        );
        if ($area === null) {
            throw new RuntimeException('That chore area no longer exists.');
        }

        $area['pool']      = DutyScheduler::poolFor($area);
        $area['pool_size'] = count($area['pool']);
        $area['weekday_list'] = DutyScheduler::describeWeekdays((int) $area['weekday_mask']);

        $tasks = Database::all(
            'SELECT t.*, u.full_name AS assignee_name, u.avatar_color AS assignee_avatar
               FROM chore_tasks t
               LEFT JOIN users u ON u.id = t.assigned_user_id
              WHERE t.chore_area_id = :id
                AND t.task_date >= :from
                AND t.task_date <= :to
              ORDER BY t.task_date',
            [
                'id'   => $id,
                'from'=> DutyScheduler::today(),
                'to'  => DutyScheduler::today(14),
            ]
        );

        $area['tasks'] = array_map([DutyScheduler::class, 'decorateTask'], $tasks);
        return $area;
    }

    /**
     * Create a rotation rule. Scope determines which pool applies:
     *   common    -> every active resident
     *   group     -> the given duty_group_id
     *   room      -> residents in the given room_id
     */
    public static function createChoreArea(int $apartmentId, array $input): array
    {
        $clean = Validator::make($input)
            ->required('name', 'Area name')
            ->string('name', 'Area name', 2, 100)
            ->in('scope', 'Scope', ['common', 'group', 'room'])
            ->in('frequency', 'Frequency', ['daily', 'weekly', 'weekdays', 'weekend'])
            ->check();

        $scope = (string) $clean['scope'];
        $groupId = $scope === 'group' ? (int) $clean['duty_group_id'] : null;
        $roomId  = $scope === 'room'  ? (int) $clean['room_id']        : null;

        if ($scope === 'group' && ($groupId === null || $groupId <= 0)) {
            throw new ValidationException(['duty_group_id' => 'Pick the duty group that shares this area.']);
        }
        if ($scope === 'room' && ($roomId === null || $roomId <= 0)) {
            throw new ValidationException(['room_id' => 'Pick the room this area belongs to.']);
        }

        $slug = self::slug((string) $clean['name']);
        $clash = Database::value(
            'SELECT 1 FROM chore_areas WHERE apartment_id = :a AND slug = :s',
            ['a' => $apartmentId, 's' => $slug]
        );
        if ($clash !== null) {
            throw new ValidationException(['name' => 'You already have an area with that name.']);
        }

        $frequency = (string) $clean['frequency'];

        $id = Database::insert('chore_areas', [
            'apartment_id'    => $apartmentId,
            'name'            => trim((string) $clean['name']),
            'slug'            => $slug,
            'scope'           => $scope,
            'room_id'         => $roomId,
            'duty_group_id'   => $groupId,
            'icon'            => $input['icon'] ?? self::iconFor($slug),
            'frequency'       => $frequency,
            'weekday_mask'    => self::maskFor($frequency, $input['weekday_mask'] ?? null),
            'rotation_offset' => (int) ($input['rotation_offset'] ?? 0),
            'points'          => max(0, (int) ($input['points'] ?? 10)),
            'is_active'       => 1,
            'description'     => $input['description'] ?? null,
        ]);

        ActivityLog::record('chore.area_created', 'chore_area', $id, (string) $clean['name']);
        $generated = DutyScheduler::generate($apartmentId);

        return self::choreArea($apartmentId, $id) + ['generated' => $generated];
    }

    public static function updateChoreArea(int $apartmentId, array $input): array
    {
        $id   = (int) $input['id'];
        $area = Database::one(
            'SELECT * FROM chore_areas WHERE id = :id AND apartment_id = :a',
            ['id' => $id, 'a' => $apartmentId]
        );
        if ($area === null) {
            throw new RuntimeException('That chore area no longer exists.');
        }

        $data = [];

        if (isset($input['name']) && trim((string) $input['name']) !== '') {
            $data['name'] = trim((string) $input['name']);
            $data['slug'] = self::slug((string) $input['name']);
        }
        if (isset($input['description'])) {
            $data['description'] = $input['description'] === '' ? null : $input['description'];
        }
        if (isset($input['points'])) {
            $data['points'] = max(0, (int) $input['points']);
        }
        if (isset($input['is_active'])) {
            $data['is_active'] = !empty($input['is_active']) ? 1 : 0;
        }
        if (isset($input['rotation_offset'])) {
            $data['rotation_offset'] = (int) $input['rotation_offset'];
        }
        if (isset($input['frequency'])) {
            $frequency = (string) $input['frequency'];
            if (!in_array($frequency, ['daily', 'weekly', 'weekdays', 'weekend'], true)) {
                throw new ValidationException(['frequency' => 'Unknown frequency.']);
            }
            $data['frequency']    = $frequency;
            $data['weekday_mask'] = self::maskFor($frequency, $input['weekday_mask'] ?? null);
        }

        if ($data === []) {
            throw new ValidationException(['_' => 'Nothing to update.']);
        }

        Database::update('chore_areas', $data, 'id', $id);
        ActivityLog::record('chore.area_updated', 'chore_area', $id,
            implode(', ', array_keys($data)));

        // A changed rule means future tasks must be recomputed.
        $rebuilt = DutyScheduler::rebuildArea($id);

        return self::choreArea($apartmentId, $id) + ['rebuilt' => $rebuilt];
    }

    public static function deleteChoreArea(int $apartmentId, int $id): array
    {
        $area = Database::one(
            'SELECT * FROM chore_areas WHERE id = :id AND apartment_id = :a',
            ['id' => $id, 'a' => $apartmentId]
        );
        if ($area === null) {
            throw new RuntimeException('That chore area no longer exists.');
        }

        // Soft-delete: keep the historical rows for the audit trail.
        Database::update('chore_areas', ['is_active' => 0], 'id', $id);
        Database::query(
            "UPDATE chore_tasks SET status = 'skipped', notes = 'Chore area retired'
              WHERE chore_area_id = :id AND task_date >= UTC_DATE() AND status = 'pending'",
            ['id' => $id]
        );
        ActivityLog::record('chore.area_retired', 'chore_area', $id, (string) $area['name']);

        return ['id' => $id, 'retired' => true, 'name' => (string) $area['name']];
    }

    /* ================================================================== */
    /*  Internals                                                        */
    /* ================================================================== */

    private static function slug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return $slug === '' ? 'area' : mb_substr($slug, 0, 100);
    }

    /** Sensible default glyph per area, so the UI never shows a blank icon. */
    private static function iconFor(string $slug): string
    {
        return match (true) {
            str_contains($slug, 'wash') || str_contains($slug, 'bath') => 'bi-droplet',
            str_contains($slug, 'kitchen') => 'bi-cup-hot',
            str_contains($slug, 'floor')   || str_contains($slug, 'hall') => 'bi-signpost-split',
            str_contains($slug, 'trash')   || str_contains($slug, 'bin')  => 'bi-trash3',
            str_contains($slug, 'laundry') || str_contains($slug, 'cloth') => 'bi-basket',
            str_contains($slug, 'room')    => 'bi-door-open',
            default => 'bi-stars',
        };
    }

    /** Weekday bitmask: 1=Mon .. 7=Sun, stored as bits 0..6. */
    private static function maskFor(string $frequency, mixed $explicit): int
    {
        return match ($frequency) {
            'weekdays' => 0b0011111,   // Mon..Fri
            'weekend'  => 0b1100000,   // Sat..Sun
            'weekly'   => 0b1000000,   // Saturday by default
            default     => $explicit !== null
                ? ((int) $explicit & 0b1111111)
                : 0b1111111,           // daily
        };
    }
}