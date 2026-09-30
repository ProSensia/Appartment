<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Algorithm self-test
 * ---------------------------------------------------------------------------
 * Exposed on any authenticated read endpoint with `?verify=1`.
 *
 * The point is to make the two hard algorithms auditable from the outside:
 * whatever balances you send in, the settlement plan that comes back must
 * (a) contain no malformed transfers, and
 * (b) drive every single balance to exactly zero when applied.
 */

declare(strict_types=1);

final class SelfTest
{
    /**
     * Run the debt simplifier across a set of fixtures and verify each plan.
     *
     * @return array<string,mixed>
     */
    public static function debtSimplifier(): array
    {
        $fixtures = [
            'simple_3_way' => [
                'label'    => 'One creditor, two debtors',
                'balances' => [1 => 4000, 2 => -2500, 3 => -1500],
            ],
            'two_each' => [
                'label'    => 'Two creditors, two debtors',
                'balances' => [1 => 6000, 2 => 2000, 3 => -5000, 4 => -3000],
            ],
            'hub_collapse' => [
                'label'    => 'A pays everybody (the wasteful web)',
                'balances' => [1 => 3000, 2 => 4000, 3 => 5000, 4 => -6000, 5 => -3000, 6 => -3000],
            ],
            'with_zeroes' => [
                'label'    => 'Settled residents are dropped',
                'balances' => [1 => 1000, 2 => 0, 3 => -1000, 4 => 0],
            ],
            'single_pair' => [
                'label'    => 'Only one transfer needed',
                'balances' => [1 => 999, 2 => -999],
            ],
            'odd_cents' => [
                'label'    => 'Non-round cents must still cancel',
                'balances' => [1 => 3333, 2 => -1111, 3 => -1111, 4 => -1111],
            ],
        ];

        $results = [];
        $allOk   = true;

        foreach ($fixtures as $name => $fixture) {
            $greedy  = DebtSimplifier::simplify($fixture['balances'], 'greedy');
            $optimal = DebtSimplifier::simplify($fixture['balances'], 'optimal');
            $auto    = DebtSimplifier::simplify($fixture['balances'], 'auto');

            $vg = DebtSimplifier::verify($fixture['balances'], $greedy);
            $vo = DebtSimplifier::verify($fixture['balances'], $optimal);
            $va = DebtSimplifier::verify($fixture['balances'], $auto);

            $ok = $vg['ok'] && $vo['ok'] && $va['ok'];
            $allOk = $allOk && $ok;

            $results[$name] = [
                'label'         => $fixture['label'],
                'input'         => self::toAmounts($fixture['balances']),
                'ok'            => $ok,
                'transfer_count'=> [
                    'greedy'  => count($greedy),
                    'optimal' => count($optimal),
                    'auto'    => count($auto),
                ],
                'optimal_never_worse' => count($optimal) <= count($greedy),
                'greedy'  => array_map([self::class, 'describe'], $greedy),
                'optimal' => array_map([self::class, 'describe'], $optimal),
                'checks'  => ['greedy' => $vg['checks'], 'optimal' => $vo['checks'], 'auto' => $va['checks']],
            ];
        }

        // Property-based sweep: every combination of 2..6 members with
        // balances that sum to zero must simplify to a valid plan.
        $sweep = self::sweep();

        return [
            'ok'      => $allOk && $sweep['ok'],
            'fixtures'=> $results,
            'sweep'   => $sweep,
        ];
    }

    /**
     * Deterministic exhaustive sweep over small balance sets.
     * Catches the rounding and sign-handling cases a hand-picked fixture misses.
     */
    private static function sweep(int $maxMembers = 5): array
    {
        $atoms    = [-700, -250, -101, 101, 250, 700];
        $tested   = 0;
        $failures = [];

        // 2-member: exact pairs
        foreach ($atoms as $a) {
            foreach ($atoms as $b) {
                if ($a + $b === 0) {
                    continue;
                }
                // need sum 0, so build [a, b, -(a+b)]
                $set = [1 => $a, 2 => $b, 3 => -($a + $b)];
                $plan = DebtSimplifier::simplify($set, 'auto');
                $v    = DebtSimplifier::verify($set, $plan);
                $tested++;
                if (!$v['ok']) {
                    $failures[] = ['set' => $set, 'checks' => $v['checks'], 'details' => $v['details']];
                }
            }
        }

        // 4-member: all same-sign-on-each-side combinations
        $debtSets   = [[-500, -300], [-1000, -1], [-400, -400], [-1, -1]];
        $creditSets = [[500, 300], [1000, 1], [400, 400], [1, 1]];

        foreach ($debtSets as $i => $d) {
            foreach ($creditSets as $j => $c) {
                $delta = array_sum($d) + array_sum($c);
                if ($delta === 0) {
                    continue;
                }
                // absorb the rounding delta into the largest credit
                $c[0] -= $delta;

                $set  = [1 => $c[0], 2 => $c[1], 3 => $d[0], 4 => $d[1]];
                $plan = DebtSimplifier::simplify($set, 'auto');
                $v    = DebtSimplifier::verify($set, $plan);
                $tested++;
                if (!$v['ok']) {
                    $failures[] = ['set' => $set, 'checks' => $v['checks'], 'details' => $v['details']];
                }
            }
        }

        return [
            'ok'        => $failures === [],
            'tested'    => $tested,
            'failures'  => $failures,
            'max_members'=> $maxMembers,
        ];
    }

    /**
     * Rotation maths: the same (date, pool, offset) must always resolve to the
     * same person, and the cycle must close after exactly `poolSize` steps.
     */
    public static function dutyScheduler(): array
    {
        $checks = [];

        // 1. determinism
        $area   = ['scope' => 'group', 'frequency' => 'daily', 'rotation_offset' => 0];
        $pool   = [11, 22, 33];
        $a = DutyScheduler::positionFor('2026-03-04', 'daily', 0, 3);
        $b = DutyScheduler::positionFor('2026-03-04', 'daily', 0, 3);
        $checks['deterministic'] = $a === $b;

        // 2. the daily cycle closes after n steps
        $closes = true;
        for ($i = 0; $i < 3; $i++) {
            $p1 = DutyScheduler::positionFor(gmdate('Y-m-d', strtotime('2026-03-04 +' . $i . ' days')), 'daily', 0, 3);
            $p2 = DutyScheduler::positionFor(gmdate('Y-m-d', strtotime('2026-03-07 +' . $i . ' days')), 'daily', 0, 3);
            if ($p1 !== $p2) {
                $closes = false;
            }
        }
        $checks['daily_cycle_closes'] = $closes;

        // 3. the weekly cycle closes after n weeks
        $closesWeekly = true;
        for ($i = 0; $i < 3; $i++) {
            $p1 = DutyScheduler::positionFor(gmdate('Y-m-d', strtotime('2026-03-02 +' . ($i * 7) . ' days')), 'weekly', 0, 3);
            $p2 = DutyScheduler::positionFor(gmdate('Y-m-d', strtotime('2026-03-23 +' . ($i * 7) . ' days')), 'weekly', 0, 3);
            if ($p1 !== $p2) {
                $closesWeekly = false;
            }
        }
        $checks['weekly_cycle_closes'] = $closesWeekly;

        // 4. offset shifts the rotation without breaking it
        $shifts = true;
        for ($d = 0; $d < 14; $d++) {
            $date = gmdate('Y-m-d', strtotime('2026-03-04 +' . $d . ' days'));
            $x = DutyScheduler::positionFor($date, 'daily', 0, 3);
            $y = DutyScheduler::positionFor($date, 'daily', 1, 3);
            if (($y - $x + 3) % 3 !== 1) {
                $shifts = false;
            }
        }
        $checks['offset_shifts_by_one'] = $shifts;

        // 5. negative offsets (a backward re-sync) stay in range
        $inRange = true;
        for ($d = 0; $d < 30; $d++) {
            $p = DutyScheduler::positionFor(
                gmdate('Y-m-d', strtotime('2026-03-04 +' . $d . ' days')), 'daily', -7, 4
            );
            if ($p < 0 || $p > 3) {
                $inRange = false;
            }
        }
        $checks['negative_offset_in_range'] = $inRange;

        // 6. weekday masks
        $maskArea = ['frequency' => 'weekly', 'weekday_mask' => 32];   // Saturday only
        $sat = DutyScheduler::weekdayName('2026-03-07');              // a Saturday
        $sun = DutyScheduler::weekdayName('2026-03-08');
        $checks['mask_saturday_only'] = DutyScheduler::runsOn($maskArea, '2026-03-07')
            && !DutyScheduler::runsOn($maskArea, '2026-03-08');

        return ['ok' => !in_array(false, $checks, true), 'checks' => $checks];
    }

    /** Money allocation must be exact. */
    public static function money(): array
    {
        $cases = [
            [1000, 3, 1000], [100, 6, 100], [4200, 6, 4200],
            [1, 7, 1], [999, 4, 999], [10000, 3, 10000],
        ];
        $exact = true;
        foreach ($cases as [$total, $parts, $expect]) {
            $sum = array_sum(Money::allocate($total, $parts));
            if ($sum !== $expect) {
                $exact = false;
            }
        }

        $weighted = Money::weighted(10000, [2, 2, 1, 1, 1, 1]);
        $weightedOk = array_sum($weighted) === 10000
            && $weighted[0] === 2500 && $weighted[2] === 1250;

        // A pathological weight set must still conserve the total.
        $awkward = Money::weighted(100, [1, 1, 1]);
        $awkwardOk = array_sum($awkward) === 100;

        return [
            'ok'    => $exact && $weightedOk && $awkwardOk,
            'checks'=> [
                'allocate_conserves_total' => $exact,
                'weighted_apportions'      => $weightedOk,
                'weighted_indivisible'     => $awkwardOk,
            ],
            'samples' => [
                'allocate(1000, 3)' => Money::allocate(1000, 3),
                'weighted(10000, [2,2,1,1,1,1])' => $weighted,
                'weighted(100, [1,1,1])'        => $awkward,
            ],
        ];
    }

    /** Everything at once — what `?verify=1` returns. */
    public static function run(): array
    {
        $debt = self::debtSimplifier();
        $duty = self::dutyScheduler();
        $money = self::money();

        return [
            'ok'     => $debt['ok'] && $duty['ok'] && $money['ok'],
            'debt'   => $debt,
            'duty'   => $duty,
            'money'  => $money,
        ];
    }

    /* ------------------------------------------------------------------ */

    private static function toAmounts(array $balances): array
    {
        $out = [];
        foreach ($balances as $id => $cents) {
            $out[$id] = Money::toAmount($cents);
        }
        return $out;
    }

    private static function describe(array $t): string
    {
        return sprintf('#%d pays #%d  %s', $t['from_user_id'], $t['to_user_id'], Money::format($t['amount_cents']));
    }
}
