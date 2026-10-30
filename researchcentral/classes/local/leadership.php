<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Figures for deans and the DVC: throughput, time to completion, supervisor load and bottlenecks. Everything is computed with
 * aggregate SQL (no per-student loops), scoped to the schools the viewer coordinates, and cached for an hour.
 */
class leadership {
    const TTL = 3600;

    /** @param int[]|null $schoolids null = all schools */
    public static function data(?array $schoolids, int $from, int $to, bool $refresh = false): array {
        $cache = \cache::make('local_researchcentral', 'leadership');
        $key = md5(json_encode([$schoolids, $from, $to]));
        if (!$refresh) {
            $hit = $cache->get($key);
            if ($hit && $hit['built'] > time() - self::TTL) {
                return $hit;
            }
        }
        $d = self::build($schoolids, $from, $to);
        $cache->set($key, $d);
        return $d;
    }

    private static function build(?array $schoolids, int $from, int $to): array {
        global $DB;
        $now = time();
        $where = '1 = 1';
        $params = [];
        if ($schoolids !== null) {
            [$in, $params] = $DB->get_in_or_equal($schoolids ?: [-1], SQL_PARAMS_NAMED, 'sc');
            $where = "g.schoolid $in";
        }
        $sla = (int)portal::cfg('review_sla_days', 7) * DAYSECS;
        $schools = $DB->get_records('local_researchcentral_school', null, 'name', 'id, name');
        $out = ['built' => $now, 'from' => $from, 'to' => $to, 'schools' => [], 'levels' => [], 'completed_n' => 0, 'active_n' => 0, 'started_n' => 0, 'overdue_n' => 0,
            'review_n' => 0, 'ttc_median' => null, 'ttc_mean' => null, 'first_response' => null, 'decide_days' => null, 'load' => [], 'stages' => [], 'monthly' => []];
        $ttc = [];
        $lead = [];
        $rows = $DB->get_recordset_sql("SELECT p.id, p.supervisorid, p.startdate, p.timecreated, g.schoolid, g.level,
                (SELECT COUNT(1) FROM {local_researchcentral_pms} m WHERE m.projid = p.id) AS total,
                (SELECT COUNT(1) FROM {local_researchcentral_pms} m WHERE m.projid = p.id AND m.status = 'approved') AS done,
                (SELECT COUNT(1) FROM {local_researchcentral_pms} m WHERE m.projid = p.id AND m.status = 'submitted') AS inreview,
                (SELECT COUNT(1) FROM {local_researchcentral_pms} m WHERE m.projid = p.id AND m.status IN ('pending','revision') AND m.duedate > 0 AND m.duedate < :now) AS overdue,
                (SELECT MAX(m.timedecided) FROM {local_researchcentral_pms} m WHERE m.projid = p.id AND m.status = 'approved') AS lastdec
            FROM {local_researchcentral_proj} p JOIN {local_researchcentral_prog} g ON g.id = p.progid WHERE $where", $params + ['now' => $now]);
        foreach ($rows as $r) {
            $sid = (int)$r->schoolid;
            $lvl = (string)$r->level;
            $s = &$out['schools'][$sid];
            $s ??= ['name' => $schools[$sid]->name ?? get_string('none'), 'active' => 0, 'completed' => 0, 'overdue' => 0, 'review' => 0, 'started' => 0];
            $l = &$out['levels'][$lvl];
            $l ??= ['active' => 0, 'completed' => 0, 'ttc' => []];
            $complete = (int)$r->total > 0 && (int)$r->done === (int)$r->total;
            $begin = (int)$r->startdate ?: (int)$r->timecreated;
            if ((int)$r->timecreated >= $from && (int)$r->timecreated <= $to) {
                $s['started']++;
                $out['started_n']++;
            }
            if ($complete) {
                if ((int)$r->lastdec >= $from && (int)$r->lastdec <= $to) {
                    $s['completed']++;
                    $l['completed']++;
                    $out['completed_n']++;
                    if ((int)$r->lastdec > $begin) {
                        $days = ((int)$r->lastdec - $begin) / DAYSECS;
                        $ttc[] = $days;
                        $l['ttc'][] = $days;
                    }
                    $m = date('Y-m', (int)$r->lastdec);
                    $out['monthly'][$m] = ($out['monthly'][$m] ?? 0) + 1;
                }
            } else {
                $s['active']++;
                $l['active']++;
                $out['active_n']++;
                $lead[(int)$r->supervisorid] = ($lead[(int)$r->supervisorid] ?? 0) + 1;
                if ((int)$r->overdue) {
                    $s['overdue']++;
                    $out['overdue_n']++;
                }
                if ((int)$r->inreview) {
                    $s['review']++;
                    $out['review_n']++;
                }
            }
            unset($s, $l);
        }
        $rows->close();
        $out['ttc_median'] = self::median($ttc);
        $out['ttc_mean'] = $ttc ? array_sum($ttc) / count($ttc) : null;
        foreach ($out['levels'] as &$l) {
            $l['median'] = self::median($l['ttc']);
            unset($l['ttc']);
        }
        unset($l);
        ksort($out['monthly']);
        // Co-supervision adds to load (active theses only).
        $co = $DB->get_records_sql("SELECT c.userid, COUNT(1) AS n FROM {local_researchcentral_cosup} c JOIN {local_researchcentral_proj} p ON p.id = c.projid
                JOIN {local_researchcentral_prog} g ON g.id = p.progid
               WHERE $where AND EXISTS (SELECT 1 FROM {local_researchcentral_pms} m WHERE m.projid = p.id AND m.status <> 'approved') GROUP BY c.userid", $params);
        $load = $lead;
        foreach ($co as $c) {
            $load[(int)$c->userid] = ($load[(int)$c->userid] ?? 0) + (int)$c->n;
        }
        arsort($load);
        $cap = (int)portal::cfg('sup_load_max', 10);
        $users = $load ? $DB->get_records_list('user', 'id', array_slice(array_keys($load), 0, 20), '', 'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename') : [];
        foreach (array_slice($load, 0, 20, true) as $uid => $n) {
            $out['load'][] = ['name' => isset($users[$uid]) ? fullname($users[$uid]) : '-', 'n' => $n, 'lead' => $lead[$uid] ?? 0, 'over' => $n > $cap];
        }
        $out['load_cap'] = $cap;
        $out['load_over_n'] = count(array_filter($load, fn($n) => $n > $cap));
        $out['supervisors_n'] = count($load);
        // Bottlenecks by stage name.
        $st = $DB->get_records_sql("SELECT s.name,
                COUNT(m.id) AS total,
                SUM(CASE WHEN m.status = 'approved' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN m.status IN ('pending','revision') AND m.duedate > 0 AND m.duedate < :now THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN m.status = 'submitted' THEN 1 ELSE 0 END) AS inreview,
                SUM(CASE WHEN m.status = 'submitted' AND m.timesubmitted > 0 AND m.timesubmitted < :cut THEN 1 ELSE 0 END) AS waiting,
                SUM(CASE WHEN m.status = 'revision' THEN 1 ELSE 0 END) AS revision
              FROM {local_researchcentral_pms} m JOIN {local_researchcentral_stage} s ON s.id = m.stageid
              JOIN {local_researchcentral_proj} p ON p.id = m.projid JOIN {local_researchcentral_prog} g ON g.id = p.progid
             WHERE $where GROUP BY s.name", $params + ['now' => $now, 'cut' => $now - $sla]);
        $out['stages'] = array_map(fn($r) => ['name' => $r->name, 'total' => (int)$r->total, 'approved' => (int)$r->approved, 'overdue' => (int)$r->overdue,
            'inreview' => (int)$r->inreview, 'waiting' => (int)$r->waiting, 'revision' => (int)$r->revision], array_values($st));
        usort($out['stages'], fn($a, $b) => [$b['overdue'] + $b['waiting'], $b['revision']] <=> [$a['overdue'] + $a['waiting'], $a['revision']]);
        // Review speed.
        $rv = $DB->get_record_sql("SELECT AVG(CASE WHEN d.firstresponse > d.timesubmitted THEN d.firstresponse - d.timesubmitted END) AS fr,
                AVG(CASE WHEN d.timecreated > d.timesubmitted THEN d.timecreated - d.timesubmitted END) AS dc
              FROM {local_researchcentral_decision} d JOIN {local_researchcentral_proj} p ON p.id = d.projid JOIN {local_researchcentral_prog} g ON g.id = p.progid
             WHERE $where AND d.timesubmitted > 0 AND d.timecreated BETWEEN :f AND :t", $params + ['f' => $from, 't' => $to]);
        $out['first_response'] = $rv && $rv->fr !== null ? (float)$rv->fr / DAYSECS : null;
        $out['decide_days'] = $rv && $rv->dc !== null ? (float)$rv->dc / DAYSECS : null;
        uasort($out['schools'], fn($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    private static function median(array $v): ?float {
        if (!$v) {
            return null;
        }
        sort($v);
        $n = count($v);
        return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }

    /** Quarter bounds [from, to] for "YYYY-Qn"; or a rolling last-12-months. */
    public static function period(string $p): array {
        if (preg_match('/^(\d{4})-Q([1-4])$/', $p, $m)) {
            $from = mktime(0, 0, 0, ((int)$m[2] - 1) * 3 + 1, 1, (int)$m[1]);
            return [$from, mktime(0, 0, 0, ((int)$m[2]) * 3 + 1, 1, (int)$m[1]) - 1];
        }
        if (preg_match('/^(\d{4})$/', $p, $m)) {
            return [mktime(0, 0, 0, 1, 1, (int)$m[1]), mktime(0, 0, 0, 1, 1, (int)$m[1] + 1) - 1];
        }
        return [time() - 365 * DAYSECS, time()];
    }

    public static function periods(): array {
        $y = (int)date('Y');
        $o = ['' => get_string('lead_last12', 'local_researchcentral')];
        for ($yy = $y; $yy >= $y - 3; $yy--) {
            $o[(string)$yy] = (string)$yy;
            for ($q = 4; $q >= 1; $q--) {
                if ($yy < $y || $q <= (int)ceil(date('n') / 3)) {
                    $o["$yy-Q$q"] = "$yy Q$q";
                }
            }
        }
        return $o;
    }

    public static function pdf(array $d, string $scope, string $label): string {
        global $CFG, $SITE;
        require_once($CFG->libdir . '/pdflib.php');
        $L = fn(string $k, $a = null) => get_string($k, 'local_researchcentral', $a);
        $e = fn($s) => s((string)$s);
        $n1 = fn($v) => $v === null ? '-' : format_float($v, 1);
        $pdf = new \pdf();
        $pdf->SetCreator('Research Central');
        $pdf->SetTitle($L('lead_title'));
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(true);
        $pdf->SetMargins(16, 16, 16);
        $pdf->AddPage();
        $pdf->SetFont('freeserif', '', 10.5);
        $h = '<h2>' . $e(format_string($SITE->fullname)) . ' – ' . $e($L('lead_title')) . '</h2><p>' . $e($scope) . ' · ' . $e($label) . ' · ' . $e(userdate($d['built'], get_string('strftimedatetimeshort', 'langconfig'))) . '</p>';
        $h .= '<table cellpadding="5" border="1"><tr bgcolor="#e8eef7"><td><b>' . $L('lead_completed') . '</b><br/>' . $d['completed_n'] . '</td><td><b>' . $L('lead_active') . '</b><br/>' . $d['active_n'] . '</td>' .
            '<td><b>' . $L('lead_overdue') . '</b><br/>' . $d['overdue_n'] . '</td><td><b>' . $L('lead_ttc') . '</b><br/>' . $n1($d['ttc_median'] !== null ? $d['ttc_median'] / 30.4 : null) . ' ' . $L('lead_months') . '</td>' .
            '<td><b>' . $L('lead_firstresp') . '</b><br/>' . $n1($d['first_response']) . ' ' . $L('lead_days') . '</td></tr></table>';
        $h .= '<h3>' . $L('lead_by_school') . '</h3><table cellpadding="4" border="1"><tr bgcolor="#e8eef7"><th>' . $L('school') . '</th><th>' . $L('lead_started') . '</th><th>' . $L('lead_active') . '</th><th>' . $L('lead_completed') . '</th><th>' . $L('lead_overdue') . '</th><th>' . $L('lead_inreview') . '</th></tr>';
        foreach ($d['schools'] as $s) {
            $h .= '<tr><td>' . $e($s['name']) . '</td><td>' . $s['started'] . '</td><td>' . $s['active'] . '</td><td>' . $s['completed'] . '</td><td>' . $s['overdue'] . '</td><td>' . $s['review'] . '</td></tr>';
        }
        $h .= '</table><h3>' . $L('lead_bottlenecks') . '</h3><table cellpadding="4" border="1"><tr bgcolor="#e8eef7"><th>' . $L('stage') . '</th><th>' . $L('lead_overdue') . '</th><th>' . $L('lead_waiting') . '</th><th>' . $L('lead_revision') . '</th><th>' . $L('lead_approved') . '</th></tr>';
        foreach (array_slice($d['stages'], 0, 12) as $s) {
            $h .= '<tr><td>' . $e(format_string($s['name'])) . '</td><td>' . $s['overdue'] . '</td><td>' . $s['waiting'] . '</td><td>' . $s['revision'] . '</td><td>' . $s['approved'] . '/' . $s['total'] . '</td></tr>';
        }
        $h .= '</table><h3>' . $L('lead_load') . '</h3><p>' . $e($L('lead_load_note', (object)['cap' => $d['load_cap'], 'n' => $d['load_over_n']])) . '</p><table cellpadding="4" border="1"><tr bgcolor="#e8eef7"><th>' . $L('supervisor') . '</th><th>' . $L('lead_theses') . '</th></tr>';
        foreach (array_slice($d['load'], 0, 10) as $s) {
            $h .= '<tr><td>' . $e($s['name']) . '</td><td>' . $s['n'] . ($s['over'] ? ' ⚠' : '') . '</td></tr>';
        }
        $h .= '</table>';
        $pdf->writeHTML($h, true, false, true, false, '');
        return $pdf->Output('', 'S');
    }
}
