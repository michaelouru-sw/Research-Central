<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/** Paragraph and word level comparison of two document versions (pure PHP). */
class diff {
    const MAX_MIDDLE = 1500;
    const MAX_WORDS = 500;

    /**
     * Compare two lists of paragraphs.
     * @return array|null list of ops [type eq|del|ins|mod, a, b, words (for mod)], or null when the texts are too different to compare.
     */
    public static function paragraphs(array $a, array $b): ?array {
        $a = array_values($a);
        $b = array_values($b);
        $n = count($a);
        $m = count($b);
        $pre = 0;
        while ($pre < $n && $pre < $m && $a[$pre] === $b[$pre]) {
            $pre++;
        }
        $suf = 0;
        while ($suf < $n - $pre && $suf < $m - $pre && $a[$n - 1 - $suf] === $b[$m - 1 - $suf]) {
            $suf++;
        }
        $ma = array_slice($a, $pre, $n - $pre - $suf);
        $mb = array_slice($b, $pre, $m - $pre - $suf);
        if (count($ma) > self::MAX_MIDDLE || count($mb) > self::MAX_MIDDLE) {
            return null;
        }
        $ops = [];
        foreach (array_slice($a, 0, $pre) as $i => $t) {
            $ops[] = ['type' => 'eq', 'a' => $t, 'b' => $t];
        }
        foreach (self::pair(self::lcs_ops($ma, $mb)) as $op) {
            $ops[] = $op;
        }
        foreach (array_slice($a, $n - $suf) as $t) {
            $ops[] = ['type' => 'eq', 'a' => $t, 'b' => $t];
        }
        return $ops;
    }

    /** Raw LCS edit script: list of [type, a, b]. */
    protected static function lcs_ops(array $x, array $y): array {
        $n = count($x);
        $m = count($y);
        $t = [];
        for ($i = $n; $i >= 0; $i--) {
            $t[$i] = array_fill(0, $m + 1, 0);
        }
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $t[$i][$j] = $x[$i] === $y[$j] ? $t[$i + 1][$j + 1] + 1 : max($t[$i + 1][$j], $t[$i][$j + 1]);
            }
        }
        $ops = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($x[$i] === $y[$j]) {
                $ops[] = ['type' => 'eq', 'a' => $x[$i], 'b' => $y[$j]];
                $i++;
                $j++;
            } else if ($t[$i + 1][$j] >= $t[$i][$j + 1]) {
                $ops[] = ['type' => 'del', 'a' => $x[$i], 'b' => null];
                $i++;
            } else {
                $ops[] = ['type' => 'ins', 'a' => null, 'b' => $y[$j]];
                $j++;
            }
        }
        for (; $i < $n; $i++) {
            $ops[] = ['type' => 'del', 'a' => $x[$i], 'b' => null];
        }
        for (; $j < $m; $j++) {
            $ops[] = ['type' => 'ins', 'a' => null, 'b' => $y[$j]];
        }
        return $ops;
    }

    /** Turn runs of deletions followed by insertions into modified paragraphs with word-level changes. */
    protected static function pair(array $ops): array {
        $out = [];
        $k = 0;
        $c = count($ops);
        while ($k < $c) {
            if ($ops[$k]['type'] === 'eq') {
                $out[] = $ops[$k++];
                continue;
            }
            $dels = $inss = [];
            while ($k < $c && $ops[$k]['type'] !== 'eq') {
                if ($ops[$k]['type'] === 'del') {
                    $dels[] = $ops[$k]['a'];
                } else {
                    $inss[] = $ops[$k]['b'];
                }
                $k++;
            }
            $pairs = min(count($dels), count($inss));
            for ($i = 0; $i < $pairs; $i++) {
                $out[] = ['type' => 'mod', 'a' => $dels[$i], 'b' => $inss[$i], 'words' => self::words($dels[$i], $inss[$i])];
            }
            for ($i = $pairs; $i < count($dels); $i++) {
                $out[] = ['type' => 'del', 'a' => $dels[$i], 'b' => null];
            }
            for ($i = $pairs; $i < count($inss); $i++) {
                $out[] = ['type' => 'ins', 'a' => null, 'b' => $inss[$i]];
            }
        }
        return $out;
    }

    /** Word-level edit script for two paragraphs: list of [type eq|del|ins, text]. */
    public static function words(string $a, string $b): array {
        $x = preg_split('/\s+/u', trim($a), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $y = preg_split('/\s+/u', trim($b), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($x) > self::MAX_WORDS || count($y) > self::MAX_WORDS) {
            return [['del', $a], ['ins', $b]];
        }
        $out = [];
        foreach (self::lcs_ops($x, $y) as $op) {
            $out[] = [$op['type'], $op['type'] === 'ins' ? $op['b'] : $op['a']];
        }
        return $out;
    }

    /** Counts of words added and removed and paragraphs changed. */
    public static function stats(array $ops): array {
        $add = $del = $chg = 0;
        $wc = fn($t) => count(preg_split('/\s+/u', trim((string)$t), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        foreach ($ops as $op) {
            if ($op['type'] === 'eq') {
                continue;
            }
            $chg++;
            if ($op['type'] === 'ins') {
                $add += $wc($op['b']);
            } else if ($op['type'] === 'del') {
                $del += $wc($op['a']);
            } else {
                foreach ($op['words'] as [$t, $w]) {
                    if ($t === 'ins') {
                        $add++;
                    } else if ($t === 'del') {
                        $del++;
                    }
                }
            }
        }
        return ['added' => $add, 'removed' => $del, 'changed' => $chg];
    }
}
