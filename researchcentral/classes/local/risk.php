<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/** Early-warning score per thesis from observable signals (no student-facing labels). */
class risk {
    const WEIGHTS = ['overdue' => 3, 'overdue_many' => 1, 'inactive' => 2, 'revision_stale' => 2, 'review_waiting' => 1, 'no_meeting' => 1,
        'missed_meeting' => 1, 'checkin_missed' => 1, 'needs_support' => 2, 'permit_expired' => 2, 'similarity' => 1, 'actions_overdue' => 1];

    public static function level(int $score): string {
        return $score >= 5 ? 'high' : ($score >= 3 ? 'medium' : 'low');
    }

    /**
     * @param \stdClass[] $projects with ->id, ->userid, ->timecreated and ->ms (milestones)
     * @return array projid => ['score' => int, 'level' => string, 'signals' => string[]]
     */
    public static function scores(array $projects, bool $usecache = true): array {
        global $DB;
        $out = [];
        if (!$projects) {
            return $out;
        }
        $now = time();
        $ids = array_map(fn($p) => (int)$p->id, $projects);
        // Large dashboards (coordinators) reuse a two-minute cached result; small views are always live.
        $cache = null;
        $ckey = '';
        if ($usecache && count($ids) > 200) {
            $cache = \cache::make('local_researchcentral', 'risk');
            $ckey = md5(implode(',', $ids));
            if (($hit = $cache->get($ckey)) !== false) {
                return $hit;
            }
        }
        [$in, $pr] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $inactive = (int)portal::cfg('risk_inactive_days', 30) * DAYSECS;
        $simmax = (int)portal::cfg('sim_max', 20);
        $sla = (int)portal::cfg('review_sla_days', 7) * DAYSECS;
        $last = [];
        foreach ($DB->get_records_sql("SELECT a.projid, MAX(a.timecreated) AS t FROM {local_researchcentral_act} a
                  JOIN {local_researchcentral_proj} p ON p.id = a.projid AND a.userid = p.userid WHERE a.projid $in GROUP BY a.projid", $pr) as $r) {
            $last[(int)$r->projid] = (int)$r->t;
        }
        $meet = $missed = $checks = $support = $permits = $sims = [];
        foreach ($DB->get_records_sql("SELECT projid, MAX(starttime) AS t FROM {local_researchcentral_appt}
                  WHERE projid $in AND status IN ('booked','done') AND starttime < :n GROUP BY projid", $pr + ['n' => $now]) as $r) {
            $meet[(int)$r->projid] = (int)$r->t;
        }
        foreach (meetings::last_held($ids) as $pid => $t) {
            $meet[$pid] = max($meet[$pid] ?? 0, $t);
        }
        $actover = meetings::overdue_counts($ids);
        foreach ($DB->get_records_sql("SELECT projid, COUNT(id) AS n FROM {local_researchcentral_appt}
                  WHERE projid $in AND (studentconf = 2 OR supconf = 2) AND starttime > :o GROUP BY projid", $pr + ['o' => $now - 60 * DAYSECS]) as $r) {
            $missed[(int)$r->projid] = (int)$r->n;
        }
        foreach ($DB->get_records_sql("SELECT projid, MAX(period) AS p FROM {local_researchcentral_checkin} WHERE projid $in GROUP BY projid", $pr) as $r) {
            $checks[(int)$r->projid] = (string)$r->p;
        }
        foreach ($DB->get_records_sql("SELECT c.projid, c.needsupport FROM {local_researchcentral_checkin} c
                  WHERE c.projid $in AND c.timecreated = (SELECT MAX(c2.timecreated) FROM {local_researchcentral_checkin} c2 WHERE c2.projid = c.projid)", $pr) as $r) {
            $support[(int)$r->projid] = (int)$r->needsupport;
        }
        foreach ($DB->get_records_sql("SELECT projid, COUNT(id) AS n FROM {local_researchcentral_permit}
                  WHERE projid $in AND (status = 'expired' OR (status = 'approved' AND expiry > 0 AND expiry < :n)) GROUP BY projid", $pr + ['n' => $now]) as $r) {
            $permits[(int)$r->projid] = (int)$r->n;
        }
        if ($simmax > 0) {
            foreach ($DB->get_records_sql("SELECT m.projid, MAX(v.version) AS dummy, MAX(v.simpct) AS s FROM {local_researchcentral_ver} v
                      JOIN {local_researchcentral_pms} m ON m.id = v.pmsid WHERE m.projid $in AND v.simpct >= 0 GROUP BY m.projid", $pr) as $r) {
                $sims[(int)$r->projid] = (int)$r->s;
            }
        }
        $cfgcheck = research::checkin_enabled();
        $curperiod = research::period();
        $prevperiod = research::period(strtotime('-1 month'));
        foreach ($projects as $p) {
            $sig = [];
            $ms = $p->ms ?? [];
            $done = $ms && count(array_filter($ms, fn($m) => $m->status === portal::APPROVED)) === count($ms);
            if ($done) {
                $out[(int)$p->id] = ['score' => 0, 'level' => 'low', 'signals' => []];
                continue;
            }
            $over = count(array_filter($ms, fn($m) => portal::is_overdue($m)));
            if ($over) {
                $sig[] = 'overdue';
                if ($over > 1) {
                    $sig[] = 'overdue_many';
                }
            }
            $act = $last[(int)$p->id] ?? (int)$p->timecreated;
            if ($now - $act > $inactive && $now - (int)$p->timecreated > $inactive) {
                $sig[] = 'inactive';
            }
            foreach ($ms as $m) {
                if ($m->status === portal::REVISION && (int)$m->timedecided && $now - (int)$m->timedecided > 21 * DAYSECS) {
                    $sig[] = 'revision_stale';
                    break;
                }
            }
            foreach ($ms as $m) {
                if ($m->status === portal::SUBMITTED && (int)$m->timesubmitted && $sla && $now - (int)$m->timesubmitted > $sla) {
                    $sig[] = 'review_waiting';
                    break;
                }
            }
            if (($meet[(int)$p->id] ?? 0) < $now - 60 * DAYSECS && $now - (int)$p->timecreated > 60 * DAYSECS) {
                $sig[] = 'no_meeting';
            }
            if (!empty($actover[(int)$p->id])) {
                $sig[] = 'actions_overdue';
            }
            if (!empty($missed[(int)$p->id])) {
                $sig[] = 'missed_meeting';
            }
            if ($cfgcheck && $now - (int)$p->timecreated > 62 * DAYSECS && !in_array($checks[(int)$p->id] ?? '', [$curperiod, $prevperiod], true)) {
                $sig[] = 'checkin_missed';
            }
            if (!empty($support[(int)$p->id])) {
                $sig[] = 'needs_support';
            }
            if (!empty($permits[(int)$p->id])) {
                $sig[] = 'permit_expired';
            }
            if ($simmax > 0 && ($sims[(int)$p->id] ?? 0) > $simmax) {
                $sig[] = 'similarity';
            }
            $score = array_sum(array_map(fn($s) => self::WEIGHTS[$s], $sig));
            $out[(int)$p->id] = ['score' => $score, 'level' => self::level($score), 'signals' => $sig];
        }
        if ($cache) {
            $cache->set($ckey, $out);
        }
        return $out;
    }

    /** Active (not completed) projects across the site, for scheduled digests. */
    public static function all_active(): array {
        global $DB;
        $projects = $DB->get_records_sql("SELECT p.*, g.name AS progname FROM {local_researchcentral_proj} p JOIN {local_researchcentral_prog} g ON g.id = p.progid");
        $ms = portal::milestones(array_keys($projects));
        foreach ($projects as $p) {
            $p->ms = $ms[$p->id] ?? [];
        }
        return $projects;
    }
}
