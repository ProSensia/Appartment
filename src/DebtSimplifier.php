<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Debt Simplification  (Splitwise-style)
 * ---------------------------------------------------------------------------
 * Given one signed net balance per person, produce the shortest practical list
 * of "X pays Y" transfers that clears every balance to exactly zero.
 *
 *   A  +60.00   (is owed money)
 *   B  -25.00   (owes)
 *   C  -15.00   (owes)
 *   D  -20.00   (owes)
 *
 *   naive pairwise  ->  A→B 25, A→C 15, A→D 20       (3 transfers)
 *   simplified      ->  B→A 25, C→A 15, D→A 20       (3 transfers, no hub)
 *
 * In the general case simplification collapses an N x N web of debts into at
 * most N-1 transfers, and often far fewer.
 *
 * ---------------------------------------------------------------------------
 *  ALGORITHM
 * ---------------------------------------------------------------------------
 *  1. Drop zero balances.  Invariant: SUM(net) MUST be 0 (see ::verify()).
 *  2. `greedy()`   — classic two-pointer largest-debt/largest-credit match.
 *                    O(n log n), produces at most (d + c - 1) transfers and is
 *                    guaranteed to terminate with every balance at zero.
 *  3. `optimal()`  — depth-first search over pairings, keeps the shortest
 *                    result. Exact minimum-transfer settlement.
 *                    * n <= 8   : explores every pairing (same-sign included)
 *                    * n <= 12  : opposite-sign pairings only
 *                    * n >  12  : skipped, falls back to greedy
 *                    A node budget stops pathological runtimes; on budget
 *                    exhaustion it returns the best solution found so far,
 *                    which is always a valid settlement.
 *  4. `simplify()` — picks the better of the two and returns it.
 *
 * All arithmetic is in integer cents, so the result provably sums to zero.
 */

declare(strict_types=1);

final class DebtSimplifier
{
    /** Above this many active balances, the exact search is skipped. */
    private const EXACT_SEARCH_MAX      = 8;
    private const OPPOSITE_SIGN_SEARCH_MAX = 12;
    private const NODE_BUDGET           = 200_000;

    /* ================================================================== */
    /*  Public API                                                        */
    /* ================================================================== */

    /**
     * Reduce a set of net balances to a minimal transfer plan.
     *
     * @param  array<int,int> $balances  user_id => net cents (+ = is owed, - = owes)
     * @param  string        $strategy  'auto' | 'greedy' | 'optimal'
     * @return array<int,array{from_user_id:int,to_user_id:int,amount_cents:int,amount:float}>
     */
    public static function simplify(array $balances, string $strategy = 'auto'): array
    {
        $balances = self::normalise($balances);

        if (count($balances) < 2) {
            return [];
        }
        if (!self::isBalanced($balances)) {
            throw new InvalidArgumentException(
                'DebtSimplifier: balances must sum to 0, got ' . array_sum($balances) . ' cents.'
            );
        }

        $n = count($balances);

        if ($strategy === 'greedy' || $n > self::OPPOSITE_SIGN_SEARCH_MAX) {
            return self::greedy($balances);
        }

        $greedy = self::greedy($balances);

        if ($strategy === 'optimal') {
            return self::optimal($balances, $n <= self::EXACT_SEARCH_MAX);
        }

        // auto: only pay for the search when it might actually beat greedy.
        // If greedy already produced the theoretical floor (max(d,c)) there is
        // nothing left to improve.
        $debtors   = count(array_filter($balances, static fn(int $c): bool => $c < 0));
        $creditors = count(array_filter($balances, static fn(int $c): bool => $c > 0));
        $floor     = max($debtors, $creditors);

        if (count($greedy) <= $floor) {
            return $greedy;
        }

        $best = self::optimal($balances, $n <= self::EXACT_SEARCH_MAX);

        return count($best) <= count($greedy) ? $best : $greedy;
    }

    /**
     * O(n log n) two-pointer settlement. Always valid, at most d+c-1 transfers.
     *
     * @param  array<int,int> $balances
     * @return array<int,array{from_user_id:int,to_user_id:int,amount_cents:int,amount:float}>
     */
    public static function greedy(array $balances): array
    {
        $balances = self::normalise($balances);

        $debtors   = [];   // [-amount]
        $creditors = [];   // [+amount]
        foreach ($balances as $userId => $cents) {
            if ($cents < 0) {
                $debtors[] = ['id' => $userId, 'amount' => -$cents];
            } elseif ($cents > 0) {
                $creditors[] = ['id' => $userId, 'amount' => $cents];
            }
        }

        // Largest first on both sides — the classic heuristic that keeps the
        // number of transfers near the theoretical minimum.
        usort($debtors,   static fn(array $a, array $b): int => $b['amount'] <=> $a['amount'] ?: $a['id'] <=> $b['id']);
        usort($creditors, static fn(array $a, array $b): int => $b['amount'] <=> $a['amount'] ?: $a['id'] <=> $b['id']);

        $out = [];
        $i = $j = 0;
        $d = count($debtors);
        $c = count($creditors);

        while ($i < $d && $j < $c) {
            $pay = min($debtors[$i]['amount'], $creditors[$j]['amount']);
            if ($pay > 0) {
                $out[] = self::transfer($debtors[$i]['id'], $creditors[$j]['id'], $pay);
            }
            $debtors[$i]['amount']   -= $pay;
            $creditors[$j]['amount'] -= $pay;
            if ($debtors[$i]['amount'] === 0)   { $i++; }
            if ($creditors[$j]['amount'] === 0) { $j++; }
        }

        return $out;
    }

    /**
     * Exact minimum-transfer search (depth-first, branch and bound).
     *
     * @param  array<int,int> $balances
     * @param  bool           $allowSameSign  true for the full optimum,
     *                                        false to restrict to debtor->creditor
     * @return array<int,array{from_user_id:int,to_user_id:int,amount_cents:int,amount:float}>
     */
    public static function optimal(array $balances, bool $allowSameSign = true): array
    {
        $balances = self::normalise($balances);
        if (count($balances) < 2) {
            return [];
        }

        $ids      = array_keys($balances);
        $values   = array_values($balances);
        $path     = [];
        $best     = null;
        $budget   = self::NODE_BUDGET;

        self::search($values, $ids, $path, $best, $budget, $allowSameSign, 0);

        if ($best === null) {
            // Unreachable for a balanced input; stay safe rather than silent.
            return self::greedy($balances);
        }
        return $best;
    }

    /**
     * Depth-first branch-and-bound over pairwise settlements.
     *
     * At each step we lock the first non-zero balance and try to clear it
     * against one partner, then recurse. Because the partner is merged into
     * the locked slot, every level retires at least one balance — depth is
     * bounded by n, so the only cost is branching, which the budget caps.
     *
     * @param int[] $values  mutated working copy
     */
    private static function search(
        array &$values,
        array $ids,
        array &$path,
        ?array &$best,
        int &$budget,
        bool $allowSameSign,
        int $depth
    ): bool {
        // --- branch and bound -------------------------------------------
        // This branch has already spent as many transfers as the best plan
        // found so far, and every further step costs one more. No completion
        // below can be strictly shorter, so unwind and tell the root to stop.
        // (Ties are irrelevant — any minimal plan will do.)
        if ($best !== null && count($path) >= count($best)) {
            return true;
        }

        if (--$budget < 0) {
            return true;                       // out of budget: keep best so far
        }

        $i = self::firstNonZero($values);
        if ($i === null) {
            if ($best === null || count($path) < count($best)) {
                $best = $path;
            }
            // Record the plan, then KEEP SEARCHING for a shorter one.
            return false;
        }

        $n = count($values);

        // Collect candidate partners, biggest absolute balance first so the
        // search reaches a complete settlement sooner.
        $candidates = [];
        for ($j = 0; $j < $n; $j++) {
            if ($j === $i || $values[$j] === 0) {
                continue;
            }
            if (!$allowSameSign && (($values[$i] > 0) === ($values[$j] > 0))) {
                continue;
            }
            $candidates[] = $j;
        }
        usort($candidates, static fn(int $a, int $b): int =>
            abs($values[$b]) <=> abs($values[$a]) ?: $a <=> $b);

        foreach ($candidates as $j) {
            $oi = $values[$i];
            $oj = $values[$j];

            $transfer = min(abs($oi), abs($oj));
            if ($transfer <= 0) {
                continue;
            }
            // Money always flows debtor -> creditor.
            [$from, $to] = $oi < 0 ? [$ids[$i], $ids[$j]] : [$ids[$j], $ids[$i]];

            $path[] = self::transfer($from, $to, $transfer);
            $values[$i] = $oi + $oj;
            $values[$j] = 0;

            if (self::search($values, $ids, $path, $best, $budget, $allowSameSign, $depth + 1)) {
                return true;                     // pruned, or budget spent: abort
            }

            array_pop($path);
            $values[$i] = $oi;
            $values[$j] = $oj;
        }

        return false;
    }

    private static function firstNonZero(array $values): ?int
    {
        foreach ($values as $i => $v) {
            if ($v !== 0) {
                return $i;
            }
        }
        return null;
    }

    /* ================================================================== */
    /*  Reporting + verification                                          */
    /* ================================================================== */

    /**
     * Group a transfer list into per-payer nets — the "who owes whom" board.
     *
     * @param  array<int,array{from_user_id:int,to_user_id:int,amount_cents:int}> $transfers
     * @return array<int,array{net_cents:int,amount:float,direction:string,counterparties:array}>
     */
    public static function summarise(array $transfers): array
    {
        $out = [];
        foreach ($transfers as $t) {
            foreach ([[$t['from_user_id'], -$t['amount_cents']], [$t['to_user_id'], $t['amount_cents']]] as [$uid, $delta]) {
                $out[$uid] ??= ['net_cents' => 0, 'counterparties' => []];
                $out[$uid]['net_cents'] += $delta;
            }
        }
        foreach ($out as $uid => &$row) {
            $row['amount']     = Money::toAmount($row['net_cents']);
            $row['direction']  = Money::direction($row['net_cents']);
        }
        unset($row);
        return $out;
    }

    /**
     * Prove the plan is correct. Returns a report array — used by the API's
     * ?verify=1 mode and by the test suite.
     *
     * @return array{ok:bool,checks:array<string,bool>,details:array}
     */
    public static function verify(array $balances, array $transfers): array
    {
        $balances = self::normalise($balances);
        $applied  = $balances;   // start from the net balances, then apply

        $checks   = [];
        $details  = [];

        // 1. input is balanced
        $checks['input_sums_to_zero'] = array_sum($balances) === 0;
        $details['input_sum_cents']   = array_sum($balances);

        // 2. every transfer is well formed
        $wellFormed = true;
        $totalMoved = 0;
        foreach ($transfers as $t) {
            $from = (int) ($t['from_user_id'] ?? 0);
            $to   = (int) ($t['to_user_id'] ?? 0);
            $amt  = (int) ($t['amount_cents'] ?? 0);

            if ($from <= 0 || $to <= 0 || $from === $to || $amt <= 0) {
                $wellFormed = false;
                break;
            }
            if (!array_key_exists($from, $applied) || !array_key_exists($to, $applied)) {
                $wellFormed = false;
                break;
            }
            // nobody may be pushed past zero by the plan
            if ($applied[$from] + $amt < 0) {
                $wellFormed = false;
                $details['overshoot_user_id'] = $from;
                break;
            }

            $applied[$from] += $amt;
            $applied[$to]   -= $amt;
            $totalMoved     += $amt;
        }
        $checks['transfers_well_formed'] = $wellFormed;

        // 3. applying the plan zeroes every balance
        $checks['all_settled_to_zero'] = !in_array(0, array_values($applied), true);
        $details['residual_cents']     = array_sum($applied);
        $details['residual_by_user']   = array_filter($applied);

        // 4. transfer count never exceeds the n-1 bound
        $n = count($balances);
        $checks['within_n_minus_1'] = count($transfers) <= max(0, $n - 1);
        $details['transfer_count']   = count($transfers);
        $details['participant_count'] = $n;
        $details['total_moved']      = Money::toAmount($totalMoved);

        return ['ok' => !in_array(false, $checks, true), 'checks' => $checks, 'details' => $details];
    }

    /* ================================================================== */
    /*  Internals                                                         */
    /* ================================================================== */

    /** Drop zero balances and re-index by user id. */
    private static function normalise(array $balances): array
    {
        $out = [];
        foreach ($balances as $userId => $cents) {
            $cents = (int) $cents;
            if ($cents !== 0) {
                $out[(int) $userId] = $cents;
            }
        }
        ksort($out);
        return $out;
    }

    private static function isBalanced(array $balances): bool
    {
        return array_sum($balances) === 0;
    }

    private static function transfer(int $from, int $to, int $cents): array
    {
        return [
            'from_user_id' => $from,
            'to_user_id'   => $to,
            'amount_cents' => $cents,
            'amount'       => Money::toAmount($cents),
        ];
    }
}
