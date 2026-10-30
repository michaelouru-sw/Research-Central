<?php
namespace local_researchcentral\checker;

/**
 * Rule engine (pure PHP). Input: reader output + stage rules. Output: results with key/status/params.
 * Status: pass | warn | fail | skip. Messages are rendered from language strings by the report page.
 */
class engine {

    public static function run(\stdClass $doc, \stdClass $rules, ?int $now = null): array {
        $now = $now ?? time();
        $sev = (($rules->severity ?? 'warn') === 'fail') ? 'fail' : 'warn';
        $paras = $doc->paragraphs;

        [$refheading, $refentries] = self::reference_section($paras);
        $excluderefs = !isset($rules->excluderefs) || (int)$rules->excluderefs === 1;

        $bodyparas = [];
        foreach ($paras as $i => $p) {
            if ($p['toc']) {
                continue;
            }
            if ($excluderefs && $refheading !== null && $i >= $refheading) {
                continue;
            }
            $bodyparas[$i] = $p;
        }
        $bodytext = implode("\n", array_column($bodyparas, 'text'));
        $words = self::count_words($bodytext);

        $results = [];
        $stats = ['words' => $words, 'pages' => $doc->pages, 'type' => $doc->type];

        // Word count.
        $min = (int)($rules->minwords ?? 0);
        $max = (int)($rules->maxwords ?? 0);
        if ($min || $max) {
            $ok = (!$min || $words >= $min) && (!$max || $words <= $max);
            $results[] = self::res('words', $ok ? 'pass' : $sev, ['words' => $words, 'range' => self::range($min, $max)]);
        }

        // Pages.
        $min = (int)($rules->minpages ?? 0);
        $max = (int)($rules->maxpages ?? 0);
        if ($min || $max) {
            if ($doc->pages === null) {
                $results[] = self::res('pages', 'skip', ['reason' => 'nopages']);
            } else {
                $ok = (!$min || $doc->pages >= $min) && (!$max || $doc->pages <= $max);
                $results[] = self::res('pages', $ok ? 'pass' : $sev, ['pages' => $doc->pages, 'range' => self::range($min, $max)]);
            }
        }

        // Required headings.
        $required = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)($rules->headings ?? '')))));
        if ($required) {
            $missing = [];
            foreach ($required as $req) {
                if (!self::has_heading($paras, $req)) {
                    $missing[] = $req;
                }
            }
            $results[] = self::res('headings', $missing ? $sev : 'pass',
                ['count' => count($required), 'missing' => implode(', ', $missing)]);
        }

        // Formatting.
        $wantfont = trim((string)($rules->font ?? ''));
        $wantsize = (float)($rules->fontsize ?? 0);
        $wantline = (int)($rules->linespacing ?? 0);
        $wantmargin = (int)($rules->minmargin ?? 0);
        if ($wantfont || $wantsize || $wantline || $wantmargin) {
            if (!$doc->format) {
                foreach (['font' => $wantfont, 'fontsize' => $wantsize, 'spacing' => $wantline, 'margins' => $wantmargin] as $k => $v) {
                    if ($v) {
                        $results[] = self::res($k, 'skip', ['reason' => 'noformat']);
                    }
                }
            } else {
                $fonts = [];
                $sizes = [];
                $lines = [];
                foreach ($bodyparas as $i => $p) {
                    if ($p['heading'] || $p['chars'] < 30 || ($refheading !== null && $i >= $refheading)) {
                        continue;
                    }
                    foreach ($p['fonts'] as $f => $c) {
                        $fonts[$f] = ($fonts[$f] ?? 0) + $c;
                    }
                    foreach ($p['sizes'] as $s => $c) {
                        $sizes[$s] = ($sizes[$s] ?? 0) + $c;
                    }
                    if ($p['spacing'] !== null) {
                        $k = (string)(round($p['spacing'] * 20) / 20);
                        $lines[$k] = ($lines[$k] ?? 0) + $p['chars'];
                    }
                }
                if ($wantfont) {
                    [$found, $share] = self::dominant($fonts);
                    $ok = $found !== null && strcasecmp($found, $wantfont) === 0 && $share >= 0.85;
                    $stats['font'] = $found;
                    $results[] = self::res('font', $ok ? 'pass' : $sev, ['found' => $found ?? '?', 'expected' => $wantfont]);
                }
                if ($wantsize) {
                    [$found, $share] = self::dominant($sizes);
                    $ok = $found !== null && abs((float)$found - $wantsize) < 0.01 && $share >= 0.85;
                    $stats['fontsize'] = $found;
                    $results[] = self::res('fontsize', $ok ? 'pass' : $sev, ['found' => $found ?? '?', 'expected' => $wantsize]);
                }
                if ($wantline) {
                    [$found, $share] = self::dominant($lines);
                    $want = $wantline / 100;
                    $ok = $found !== null && abs((float)$found - $want) <= 0.06 && $share >= 0.85;
                    $stats['spacing'] = $found;
                    $results[] = self::res('spacing', $ok ? 'pass' : $sev, ['found' => $found ?? '?', 'expected' => $want]);
                }
                if ($wantmargin) {
                    $found = $doc->minmargin;
                    $stats['margin'] = $found;
                    $ok = $found !== null && $found >= $wantmargin - 1;
                    $results[] = self::res('margins', $ok ? 'pass' : $sev, ['found' => $found ?? '?', 'expected' => $wantmargin]);
                }
            }
        }

        // Referencing.
        $style = (string)($rules->refstyle ?? 'none');
        $minrefs = (int)($rules->minrefs ?? 0);
        $recentyears = (int)($rules->recentyears ?? 0);
        $minrecent = (int)($rules->minrecent ?? 0);
        if ($style !== 'none' || $minrefs || ($recentyears && $minrecent)) {
            if ($refheading === null) {
                $results[] = self::res('reflist', $sev, []);
            } else {
                $results[] = self::res('reflist', 'pass', ['count' => count($refentries)]);
                $stats['refs'] = count($refentries);

                if ($minrefs) {
                    $results[] = self::res('refcount', count($refentries) >= $minrefs ? 'pass' : $sev,
                        ['count' => count($refentries), 'range' => self::range($minrefs, 0)]);
                }

                $refkeys = [];
                $years = [];
                foreach ($refentries as $e) {
                    $k = self::ref_key($e);
                    if ($k) {
                        $refkeys[$k[0] . ' ' . $k[1]] = true;
                        $years[] = (int)$k[1];
                    } else if (preg_match('/\b(?:1[5-9]|20)\d{2}/', $e, $m)) {
                        $years[] = (int)$m[0];
                    }
                }
                if ($recentyears && $minrecent && $years) {
                    $cut = (int)date('Y', $now) - $recentyears;
                    $recent = count(array_filter($years, fn($y) => $y >= $cut));
                    $pct = (int)round(100 * $recent / count($years));
                    $stats['recentpct'] = $pct;
                    $results[] = self::res('recent', $pct >= $minrecent ? 'pass' : $sev,
                        ['pct' => $pct, 'years' => $recentyears, 'expected' => $minrecent]);
                }

                [$cited, $withcomma, $nocomma] = self::citations($bodytext);
                $stats['citations'] = count($cited);
                if ($style !== 'none' && $cited) {
                    $missing = array_keys(array_diff_key($cited, $refkeys));
                    $unused = array_keys(array_diff_key($refkeys, $cited));
                    $results[] = self::res('citemissing', $missing ? $sev : 'pass',
                        ['count' => count($missing), 'examples' => self::examples($missing)]);
                    $results[] = self::res('citeunused', $unused ? $sev : 'pass',
                        ['count' => count($unused), 'examples' => self::examples($unused)]);

                    $tot = $withcomma + $nocomma;
                    if ($tot >= 5) {
                        $wrong = ($style === 'apa') ? $nocomma : $withcomma;
                        $share = $wrong / $tot;
                        $results[] = self::res('citestyle', $share > 0.3 ? $sev : 'pass',
                            ['style' => strtoupper($style) === 'APA' ? 'APA' : 'Harvard', 'pct' => (int)round($share * 100)]);
                    }
                } else if ($style !== 'none') {
                    $results[] = self::res('citemissing', 'skip', ['reason' => 'nocitations']);
                }
            }
        }

        $overall = 'pass';
        foreach ($results as $r) {
            if ($r['status'] === 'fail') {
                $overall = 'fail';
                break;
            }
            if ($r['status'] === 'warn') {
                $overall = 'warn';
            }
        }
        return ['overall' => $overall, 'results' => $results, 'stats' => $stats];
    }

    public static function count_words(string $text): int {
        $parts = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return $parts ? count($parts) : 0;
    }

    protected static function res(string $key, string $status, array $params): array {
        return ['key' => $key, 'status' => $status, 'params' => $params];
    }

    protected static function range(int $min, int $max): string {
        if ($min && $max) {
            return number_format($min) . '–' . number_format($max);
        }
        return $min ? '≥ ' . number_format($min) : '≤ ' . number_format($max);
    }

    protected static function dominant(array $counts): array {
        if (!$counts) {
            return [null, 0.0];
        }
        arsort($counts);
        $total = array_sum($counts);
        $k = (string)array_key_first($counts);
        return [$k, $total ? $counts[$k] / $total : 0.0];
    }

    protected static function examples(array $keys): string {
        $keys = array_slice($keys, 0, 6);
        return implode('; ', array_map(fn($k) => ucfirst($k), $keys));
    }

    protected static function norm_heading(string $t): string {
        $t = mb_strtolower(trim($t));
        $t = preg_replace('/^(chapter\s+\w+[\.:\-]?\s*|\d+(\.\d+)*[\.\)]?\s*)/u', '', $t);
        return trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $t));
    }

    protected static function has_heading(array $paras, string $required): bool {
        $want = self::norm_heading($required);
        foreach ($paras as $p) {
            if ($p['toc']) {
                continue;
            }
            $short = mb_strlen($p['text']) <= 90;
            if (($p['heading'] || $short) && self::norm_heading($p['text']) !== ''
                    && strpos(self::norm_heading($p['text']), $want) !== false) {
                return true;
            }
        }
        return false;
    }

    /** @return array [index of heading|null, string[] entries] */
    protected static function reference_section(array $paras): array {
        $idx = null;
        foreach ($paras as $i => $p) {
            if (!$p['toc'] && mb_strlen($p['text']) <= 40
                    && preg_match('/^\s*(\d+[\.\)]?\s*)?(references?|reference list|bibliography|works cited|list of references)\s*$/iu', $p['text'])) {
                $idx = $i; // Keep the last match; earlier ones are usually the table of contents.
            }
        }
        if ($idx === null) {
            return [null, []];
        }
        $entries = [];
        for ($i = $idx + 1; $i < count($paras); $i++) {
            $p = $paras[$i];
            if ($p['heading'] && preg_match('/^\s*(\w+\s+)?appendi/iu', $p['text'])) {
                break;
            }
            if (mb_strlen($p['text']) >= 25) {
                $entries[] = $p['text'];
            }
        }
        return [$idx, $entries];
    }

    /** @return array|null [firstword, year] */
    protected static function ref_key(string $entry): ?array {
        if (!preg_match('/^[^\p{L}]*(\p{L}[\p{L}\'’\-]*)/u', $entry, $a)) {
            return null;
        }
        if (!preg_match('/\b((?:1[5-9]|20)\d{2})[a-z]?\b/', $entry, $y)) {
            return null;
        }
        return [mb_strtolower($a[1]), $y[1]];
    }

    /** @return array [set of "name year" keys, count with comma, count without comma] */
    protected static function citations(string $text): array {
        $cited = [];
        $withcomma = 0;
        $nocomma = 0;
        if (preg_match_all('/\(([^()]{3,300})\)/u', $text, $groups)) {
            foreach ($groups[1] as $group) {
                foreach (explode(';', $group) as $seg) {
                    $seg = preg_replace('/^\s*(see also|see|e\.g\.,?|cf\.|for example|also|but see|i\.e\.,?)[,\s]*/iu', '', $seg);
                    if (!preg_match('/^\s*(.+?)([,\s]+)((?:1[5-9]|20)\d{2})[a-z]?\b/u', $seg, $m)) {
                        continue;
                    }
                    if (!preg_match('/^[^\p{L}]*(\p{Lu}[\p{L}\'’\-]*)/u', $m[1], $n)) {
                        continue;
                    }
                    $cited[mb_strtolower($n[1]) . ' ' . $m[3]] = true;
                    if (strpos($m[2], ',') !== false) {
                        $withcomma++;
                    } else {
                        $nocomma++;
                    }
                }
            }
        }
        if (preg_match_all('/\b(\p{Lu}[\p{L}\'’\-]+)(?:\s+et\s+al\.?|\s+(?:and|&)\s+\p{Lu}[\p{L}\'’\-]+)?\s*\(((?:1[5-9]|20)\d{2})[a-z]?\)/u', $text, $nar, PREG_SET_ORDER)) {
            foreach ($nar as $m) {
                $cited[mb_strtolower($m[1]) . ' ' . $m[2]] = true;
            }
        }
        return [$cited, $withcomma, $nocomma];
    }
}
