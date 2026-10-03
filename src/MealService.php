<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Meal Service
 * ---------------------------------------------------------------------------
 * Weekly meal planning: the 7x3 grid, dish suggestions, up/down voting and
 * per-slot opt-in / opt-out.
 *
 * ---------------------------------------------------------------------------
 *  WHY OPT-IN EXISTS SEPARATELY FROM CHORES
 * ---------------------------------------------------------------------------
 * `meal_participants` answers "how much food do we buy?" and
 * "who shares the grocery bill?". It is a *food* signal.
 *
 * It is deliberately never consulted by DutyScheduler. Opting out of dinner
 * does not excuse you from the washroom rota — see src/DutyScheduler.php.
 *
 * Opting out of meals is expressed as a *refusal to consume*, never as a
 * *removal from the household*, so the two systems stay independent.
 */

declare(strict_types=1);

final class MealService
{
    public const MEAL_TYPES  = ['breakfast', 'lunch', 'dinner'];
    public const DAY_NAMES   = [
        1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
        5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
    ];
    public const DAY_SHORT   = [
        1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun',
    ];

    /* ================================================================== */
    /*  Plan                                                               */
    /* ================================================================== */

    /** Find or lazily create the plan for the week containing $date. */
    public static function planFor(int $apartmentId, string $date, bool $create = true): array
    {
        $weekStart = DutyScheduler::weekStart($date);

        $plan = Database::one(
            'SELECT * FROM meal_plans WHERE apartment_id = :a AND week_start = :w',
            ['a' => $apartmentId, 'w' => $weekStart]
        );

        if ($plan === null && $create) {
            $planId = Database::insert('meal_plans', [
                'apartment_id' => $apartmentId,
                'week_start'   => $weekStart,
                'status'       => 'draft',
            ]);
            // Materialise the 21 slots.
            for ($d = 1; $d <= 7; $d++) {
                foreach (self::MEAL_TYPES as $type) {
                    Database::insert('meals', [
                        'meal_plan_id' => $planId,
                        'day_of_week'  => $d,
                        'meal_type'    => $type,
                    ]);
                }
            }
            // Seed participation as "eating" so the grid is never empty and
            // residents only have to mark the exceptions.
            Database::query(
                "INSERT IGNORE INTO meal_participants (meal_id, user_id, status)
                 SELECT m.id, u.id, 'eating'
                   FROM meals m
                   JOIN users u ON u.apartment_id = :a AND u.status = 'active'
                  WHERE m.meal_plan_id = :p",
                ['a' => $apartmentId, 'p' => $planId]
            );
            $plan = Database::one('SELECT * FROM meal_plans WHERE id = :id', ['id' => $planId]);
        }

        return $plan ?? [];
    }

    /**
     * The full 7 x 3 grid for a week, with coverage, cook and top suggestions.
     *
     * @return array<string,mixed>
     */
    public static function week(int $apartmentId, string $date, int $viewerId): array
    {
        $plan = self::planFor($apartmentId, $date);
        if ($plan === []) {
            return [];
        }

        $rows = Database::all(
            'SELECT m.id, m.day_of_week, m.meal_type, m.menu_title, m.menu_notes, m.locked,
                    m.cook_user_id,
                    cu.full_name AS cook_name, cu.avatar_color AS cook_avatar,
                    c.eaters, c.opting_out, c.responded, c.total_residents
               FROM meals m
               LEFT JOIN users cu ON cu.id = m.cook_user_id
               LEFT JOIN vw_meal_coverage c ON c.meal_id = m.id
              WHERE m.meal_plan_id = :p
              ORDER BY m.day_of_week, FIELD(m.meal_type, "breakfast","lunch","dinner")',
            ['p' => $plan['id']]
        );

        // My opt-in state per slot, in one query.
        $mine = [];
        foreach (Database::all(
            'SELECT mp.meal_id, mp.status FROM meal_participants mp
               JOIN meals m ON m.id = mp.meal_id
              WHERE m.meal_plan_id = :p AND mp.user_id = :u1',
            ['p' => $plan['id'], 'u1' => $viewerId]
        ) as $r) {
            $mine[(int) $r['meal_id']] = $r['status'];
        }

        // Winning suggestions per slot.
        $suggestions = Database::all(
            'SELECT s.id, s.meal_id, s.user_id, s.title, s.notes, s.estimated_cost,
                    s.is_winner, s.status, u.full_name, u.avatar_color,
                    COALESCE(SUM(CASE WHEN v.vote =  1 THEN 1 ELSE 0 END), 0) AS ups,
                    COALESCE(SUM(CASE WHEN v.vote = -1 THEN 1 ELSE 0 END), 0) AS downs
               FROM meal_suggestions s
               JOIN users u ON u.id = s.user_id
               LEFT JOIN suggestion_votes v ON v.suggestion_id = s.id
              WHERE s.meal_id IN (SELECT id FROM meals WHERE meal_plan_id = :p)
              GROUP BY s.id, s.meal_id, s.user_id, s.title, s.notes, s.estimated_cost,
                       s.is_winner, s.status, u.full_name, u.avatar_color
              ORDER BY ups DESC, downs ASC, s.id',
            ['p' => $plan['id']]
        );

        $byMeal = [];
        foreach ($suggestions as $s) {
            $byMeal[(int) $s['meal_id']][] = $s + ['score' => (int) $s['ups'] - (int) $s['downs']];
        }

        $myVotes = [];
        foreach (Database::all(
            'SELECT v.suggestion_id, v.vote FROM suggestion_votes v
               JOIN meal_suggestions s ON s.id = v.suggestion_id
               JOIN meals m ON m.id = s.meal_id
              WHERE m.meal_plan_id = :p AND v.user_id = :u1',
            ['p' => $plan['id'], 'u1' => $viewerId]
        ) as $v) {
            $myVotes[(int) $v['suggestion_id']] = (int) $v['vote'];
        }

        $grid = [];
        $totalEaters = 0;
        $totalSlots  = 0;
        $unanswered  = 0;

        foreach ($rows as $r) {
            $mealId = (int) $r['id'];
            $myStatus = $mine[$mealId] ?? null;
            $slotSuggestions = $byMeal[$mealId] ?? [];
            foreach ($slotSuggestions as &$s) {
                $s['my_vote'] = $myVotes[(int) $s['id']] ?? 0;
            }
            unset($s);

            $eaters = (int) ($r['eaters'] ?? 0);
            $totalEaters += $eaters;
            $totalSlots++;
            if ($myStatus === null) {
                $unanswered++;
            }

            $grid[] = [
                'meal_id'      => $mealId,
                'day_of_week'  => (int) $r['day_of_week'],
                'day_name'     => self::DAY_NAMES[(int) $r['day_of_week']],
                'day_short'    => self::DAY_SHORT[(int) $r['day_of_week']],
                'date'         => gmdate('Y-m-d', strtotime($plan['week_start'] . ' +' . ((int) $r['day_of_week'] - 1) . ' days')),
                'meal_type'    => $r['meal_type'],
                'menu_title'   => $r['menu_title'],
                'menu_notes'   => $r['menu_notes'],
                'locked'       => (int) $r['locked'] === 1,
                'cook'         => $r['cook_user_id'] === null ? null : [
                    'user_id' => (int) $r['cook_user_id'],
                    'name'    => $r['cook_name'],
                    'avatar'  => $r['cook_avatar'],
                ],
                'coverage'     => [
                    'eaters'        => $eaters,
                    'opting_out'    => (int) ($r['opting_out'] ?? 0),
                    'responded'     => (int) ($r['responded'] ?? 0),
                    'total_residents' => (int) ($r['total_residents'] ?? 0),
                    'missing'       => max(0, (int) ($r['total_residents'] ?? 0) - (int) ($r['responded'] ?? 0)),
                ],
                'my_status'    => $myStatus,
                'needs_my_response' => $myStatus === null,
                'suggestions'  => $slotSuggestions,
                'suggestion_count'  => count($slotSuggestions),
                'top_suggestion'   => $slotSuggestions[0]['title'] ?? null,
                'is_today'     => self::dateOf($plan['week_start'], (int) $r['day_of_week']) === DutyScheduler::today(),
                'is_past'      => self::dateOf($plan['week_start'], (int) $r['day_of_week']) < DutyScheduler::today(),
            ];
        }

        return [
            'plan'          => [
                'id'         => (int) $plan['id'],
                'week_start' => $plan['week_start'],
                'week_end'   => gmdate('Y-m-d', strtotime($plan['week_start'] . ' +6 days')),
                'status'     => $plan['status'],
                'notes'      => $plan['notes'],
                'label'      => self::weekLabel($plan['week_start']),
                'is_current' => $plan['week_start'] === DutyScheduler::weekStart(DutyScheduler::today()),
            ],
            'grid'          => $grid,
            'stats'         => [
                'total_slots'        => $totalSlots,
                'planned_slots'      => count(array_filter($grid, static fn(array $s): bool => $s['menu_title'] !== null)),
                'open_slots'         => count(array_filter($grid, static fn(array $s): bool => $s['menu_title'] === null)),
                'total_eater_slots'  => $totalEaters,
                'my_unanswered'      => $unanswered,
                'open_suggestions'   => array_sum(array_map(static fn(array $s): int => $s['suggestion_count'], $grid)),
            ],
        ];
    }

    /* ================================================================== */
    /*  Menu                                                               */
    /* ================================================================== */

    public static function setMenu(int $mealId, int $actorId, bool $isAdmin, ?string $title, ?string $notes = null): array
    {
        $meal = Database::one(
            'SELECT m.*, p.apartment_id, p.status AS plan_locked
               FROM meals m JOIN meal_plans p ON p.id = m.meal_plan_id
              WHERE m.id = :id',
            ['id' => $mealId]
        );
        if ($meal === null) {
            throw new RuntimeException('That meal slot no longer exists.');
        }
        if ($meal['plan_locked'] === 'locked' && !$isAdmin) {
            throw new RuntimeException('This week’s plan is locked. Ask an admin to reopen it.');
        }

        $title = $title === null || trim($title) === '' ? null : trim($title);

        Database::update('meals', [
            'menu_title' => $title,
            'menu_notes' => $notes,
        ], 'id', $mealId);

        ActivityLog::record(
            'meal.menu_set', 'meal', $mealId,
            $title === null
                ? 'Cleared the menu for ' . self::DAY_SHORT[(int) $meal['day_of_week']] . ' ' . $meal['meal_type']
                : 'Set ' . self::DAY_SHORT[(int) $meal['day_of_week']] . ' ' . $meal['meal_type'] . ' to "' . $title . '"'
        );

        return self::slot($mealId, $actorId);
    }

    /** Promote a suggestion to the confirmed menu for that slot. */
    public static function acceptSuggestion(int $suggestionId, int $actorId, bool $isAdmin): array
    {
        $s = Database::one(
            'SELECT s.*, m.meal_plan_id, m.day_of_week, m.meal_type, p.apartment_id
               FROM meal_suggestions s
               JOIN meals m       ON m.id = s.meal_id
               JOIN meal_plans p  ON p.id = m.meal_plan_id
              WHERE s.id = :id',
            ['id' => $suggestionId]
        );
        if ($s === null) {
            throw new RuntimeException('That suggestion no longer exists.');
        }
        if ($s['user_id'] != $actorId && !$isAdmin) {
            throw new RuntimeException('Only the person who suggested a dish, or an admin, can confirm it.');
        }

        Database::transaction(static function () use ($suggestionId, $s): void {
            Database::query(
                'UPDATE meal_suggestions SET is_winner = 0, status = "open" WHERE meal_id = :m',
                ['m' => $s['meal_id']]
            );
            Database::update('meal_suggestions', ['is_winner' => 1, 'status' => 'chosen'], 'id', $suggestionId);
            Database::update('meals', [
                'menu_title' => $s['title'],
                'menu_notes' => $s['notes'],
            ], 'id', (int) $s['meal_id']);
        });

        ActivityLog::record('meal.suggested_accepted', 'meal_suggestion', (int) $suggestionId,
            '"' . $s['title'] . '" confirmed for ' . self::DAY_SHORT[(int) $s['day_of_week']] . ' ' . $s['meal_type']);

        return self::slot((int) $s['meal_id'], $actorId);
    }

    public static function suggest(int $mealId, int $actorId, string $title, ?string $notes, float $cost): array
    {
        $meal = Database::one(
            'SELECT m.id, p.apartment_id FROM meals m
               JOIN meal_plans p ON p.id = m.meal_plan_id WHERE m.id = :id',
            ['id' => $mealId]
        );
        if ($meal === null) {
            throw new RuntimeException('That meal slot no longer exists.');
        }

        $id = Database::insert('meal_suggestions', [
            'meal_id'        => $mealId,
            'user_id'        => $actorId,
            'title'          => trim($title),
            'notes'          => $notes,
            'estimated_cost' => round($cost, 2),
        ]);

        // Authors implicitly endorse their own dish.
        Database::insert('suggestion_votes', [
            'suggestion_id' => $id,
            'user_id'       => $actorId,
            'vote'          => 1,
        ]);

        // Nudge the rest of the flat to vote.
        foreach (array_column(
            Database::all(
                "SELECT id FROM users WHERE apartment_id = :a AND status = 'active' AND id <> :u1",
                ['a' => (int) $meal['apartment_id'], 'u1' => $actorId]
            ),
            'id'
        ) as $uid) {
            Reminder::push((int) $meal['apartment_id'], (int) $uid, 'vote', 'Your vote is needed',
                'A new dish was suggested: ' . $title, 'info', 'meal_suggestions', $id);
        }

        ActivityLog::record('meal.suggested', 'meal_suggestion', $id, 'Suggested "' . $title . '"');
        return self::slot($mealId, $actorId);
    }

    /**
     * Up / down vote. Re-sending the same value clears the vote, so the UI
     * can be a simple toggle.
     */
    public static function vote(int $suggestionId, int $actorId, int $vote): array
    {
        if ($vote !== 1 && $vote !== -1) {
            throw new ValidationException(['vote' => 'Vote must be 1 (up) or -1 (down).']);
        }
        $s = Database::one(
            'SELECT s.id, s.meal_id, s.user_id, p.apartment_id
               FROM meal_suggestions s
               JOIN meals m      ON m.id = s.meal_id
               JOIN meal_plans p ON p.id = m.meal_plan_id
              WHERE s.id = :id',
            ['id' => $suggestionId]
        );
        if ($s === null) {
            throw new RuntimeException('That suggestion no longer exists.');
        }

        $current = Database::value(
            'SELECT vote FROM suggestion_votes WHERE suggestion_id = :s AND user_id = :u1',
            ['s' => $suggestionId, 'u1' => $actorId]
        );

        if ($current === null) {
            Database::insert('suggestion_votes', [
                'suggestion_id' => $suggestionId,
                'user_id'       => $actorId,
                'vote'          => $vote,
            ]);
        } elseif ((int) $current === $vote) {
            // Same value again = retract the vote.
            Database::query(
                'DELETE FROM suggestion_votes WHERE suggestion_id = :s AND user_id = :u1',
                ['s' => $suggestionId, 'u1' => $actorId]
            );
        } else {
            Database::query(
                'UPDATE suggestion_votes SET vote = :v
                  WHERE suggestion_id = :s AND user_id = :u1',
                ['v' => $vote, 's' => $suggestionId, 'u1' => $actorId]
            );
        }

        return self::slot((int) $s['meal_id'], $actorId);
    }

    public static function assignCook(int $mealId, int $userId): array
    {
        $ok = Database::value(
            "SELECT 1 FROM users WHERE id = :u1 AND status = 'active'",
            ['u1' => $userId]
        );
        if ($ok === null) {
            throw new RuntimeException('That person is not an active resident.');
        }
        Database::update('meals', ['cook_user_id' => $userId], 'id', $mealId);
        ActivityLog::record('meal.cook_assigned', 'meal', $mealId, 'Cook assigned to user #' . $userId);
        return self::slot($mealId, $userId);
    }

    /* ================================================================== */
    /*  Opt-in / opt-out                                                   */
    /* ================================================================== */

    /**
     * Record one person's participation for a slot.
     *
     * This affects grocery sizing and the "split among eaters" expense mode.
     * It has NO effect on chore assignments — see DutyScheduler.
     */
    public static function setParticipation(int $mealId, int $userId, string $status): array
    {
        if (!in_array($status, ['eating', 'opting_out'], true)) {
            throw new ValidationException(['status' => 'Status must be "eating" or "opting_out".']);
        }
        $ok = Database::value(
            "SELECT 1 FROM users WHERE id = :u1 AND status = 'active'",
            ['u1' => $userId]
        );
        if ($ok === null) {
            throw new RuntimeException('Only active residents can be marked for a meal.');
        }

        Database::query(
            'INSERT INTO meal_participants (meal_id, user_id, status, responded_at)
                  VALUES (:m, :u1, :s, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE status = :s2, responded_at = UTC_TIMESTAMP()',
            ['m' => $mealId, 'u1' => $userId, 's' => $status, 's2' => $status]
        );

        return self::slot($mealId, $userId);
    }

    /** Bulk opt-in for a whole day, or the whole week. */
    public static function bulkRespond(int $apartmentId, int $userId, string $status, string $scope, ?string $date): int
    {
        $from = $date ?? DutyScheduler::weekStart(DutyScheduler::today());
        $to   = $scope === 'day'
            ? $from
            : gmdate('Y-m-d', strtotime($from . ' +6 days'));

        $meals = Database::all(
            'SELECT m.id FROM meals m
               JOIN meal_plans p ON p.id = m.meal_plan_id
              WHERE p.apartment_id = :a
                AND p.week_start >= :from AND p.week_start <= :to',
            ['a' => $apartmentId, 'from' => $from, 'to' => $to]
        );

        foreach ($meals as $m) {
            Database::query(
                'INSERT INTO meal_participants (meal_id, user_id, status, responded_at)
                      VALUES (:m, :u1, :s, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE status = :s2, responded_at = UTC_TIMESTAMP()',
                ['m' => $m['id'], 'u1' => $userId, 's' => $status, 's2' => $status]
            );
        }
        return count($meals);
    }

    /* ================================================================== */
    /*  Grocery list (derived from opt-in + winning suggestions)           */
    /* ================================================================== */

    /**
     * Aggregate the week into a shopping list: one line per confirmed dish
     * with the number of mouths to feed.
     */
    public static function groceryList(int $apartmentId, string $date): array
    {
        $plan = self::planFor($apartmentId, $date, false);
        if ($plan === []) {
            return [];
        }

        $rows = Database::all(
            'SELECT m.day_of_week, m.meal_type, m.menu_title, COALESCE(c.eaters, 0) AS eaters
               FROM meals m
               LEFT JOIN vw_meal_coverage c ON c.meal_id = m.id
              WHERE m.meal_plan_id = :p AND m.menu_title IS NOT NULL
              ORDER BY m.day_of_week, FIELD(m.meal_type, "breakfast","lunch","dinner")',
            ['p' => $plan['id']]
        );

        $byMeal = [];
        foreach ($rows as $r) {
            $byMeal[(string) $r['menu_title']]['dish']   = $r['menu_title'];
            $byMeal[(string) $r['menu_title']]['eaters'] = (int) $r['eaters'];
            $byMeal[(string) $r['menu_title']]['slots'][] = [
                'day'   => self::DAY_SHORT[(int) $r['day_of_week']],
                'type'  => $r['meal_type'],
            ];
        }

        $list = array_values($byMeal);
        usort($list, static fn(array $a, array $b): int => $b['eaters'] <=> $a['eaters']);
        foreach ($list as $i => $item) {
            $list[$i]['head_count'] = $item['eaters'];
        }

        $unplanned = (int) Database::value(
            'SELECT COUNT(*) FROM meals WHERE meal_plan_id = :p AND menu_title IS NULL',
            ['p' => $plan['id']]
        );

        return [
            'week_start'   => $plan['week_start'],
            'items'        => $list,
            'total_planned'=> count($rows),
            'unplanned'    => $unplanned,
            'total_covers' => array_sum(array_column($list, 'eaters')),
        ];
    }

    /* ================================================================== */
    /*  Plan locking                                                       */
    /* ================================================================== */

    public static function setStatus(int $planId, string $status, int $actorId): void
    {
        if (!in_array($status, ['draft', 'locked', 'archived'], true)) {
            throw new ValidationException(['status' => 'Status must be draft, locked or archived.']);
        }
        Database::update('meal_plans', [
            'status'    => $status,
            'locked_at' => $status === 'locked' ? gmdate('Y-m-d H:i:s') : null,
            'locked_by' => $status === 'locked' ? $actorId : null,
        ], 'id', $planId);

        ActivityLog::record('meal.plan_' . $status, 'meal_plan', $planId);
    }

    /* ================================================================== */
    /*  Internals                                                          */
    /* ================================================================== */

    public static function slot(int $mealId, int $viewerId): array
    {
        $week = null;
        $meal = Database::one(
            'SELECT m.*, p.apartment_id, p.week_start, p.status AS plan_status
               FROM meals m JOIN meal_plans p ON p.id = m.meal_plan_id
              WHERE m.id = :id',
            ['id' => $mealId]
        );
        if ($meal === null) {
            return [];
        }
        $week = self::week((int) $meal['apartment_id'], (string) $meal['week_start'], $viewerId);

        foreach ($week['grid'] as $slot) {
            if ($slot['meal_id'] === $mealId) {
                return $slot;
            }
        }
        return [];
    }

    private static function dateOf(string $weekStart, int $dayOfWeek): string
    {
        return gmdate('Y-m-d', strtotime($weekStart . ' +' . ($dayOfWeek - 1) . ' days'));
    }

    public static function weekLabel(string $weekStart): string
    {
        $end = gmdate('Y-m-d', strtotime($weekStart . ' +6 days'));
        return gmdate('j M', strtotime($weekStart)) . ' – ' . gmdate('j M Y', strtotime($end));
    }
}
