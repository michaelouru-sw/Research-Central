<?php
namespace local_researchcentral\task;

defined('MOODLE_INTERNAL') || die();

use local_researchcentral\local\portal;
use local_researchcentral\local\research;
use local_researchcentral\local\risk;

/** Daily: check-in reminders, permit expiry, viva corrections deadlines and the weekly early-warning digest. */
class research_reminders extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task_research_reminders', 'local_researchcentral');
    }

    public function execute(): void {
        $projects = risk::all_active();
        $this->checkins($projects);
        $this->permits();
        $this->corrections();
        if ((int)userdate(time(), '%u', 99, false) === 1) {
            $this->digest($projects);
        }
    }

    /** First and eighth day of the month: remind students who have not checked in yet. */
    protected function checkins(array $projects): void {
        global $DB;
        if (!research::checkin_enabled()) {
            return;
        }
        $day = (int)userdate(time(), '%d', 99, false);
        if (!in_array($day, [1, 8], true)) {
            return;
        }
        $period = research::period();
        $done = $DB->get_fieldset_select('local_researchcentral_checkin', 'projid', 'period = ?', [$period]);
        foreach ($projects as $p) {
            $complete = $p->ms && count(array_filter($p->ms, fn($m) => $m->status === portal::APPROVED)) === count($p->ms);
            if ($complete || in_array($p->id, $done)) {
                continue;
            }
            portal::notify((int)$p->userid, get_string('msg_ci_remind_subject', 'local_researchcentral'),
                get_string('msg_ci_remind_body', 'local_researchcentral', $period), research::url((int)$p->id, 'checkins'), 'reminder');
        }
    }

    /** Permits: notify at 30 days, 7 days and on expiry (each once). remind: 3 = 30d sent, 2 = 7d sent, 1 = expired sent. */
    protected function permits(): void {
        global $DB;
        $now = time();
        foreach ($DB->get_records_select('local_researchcentral_permit', "status IN ('approved','expired') AND expiry > 0 AND expiry < ?", [$now + 30 * DAYSECS]) as $p) {
            $left = (int)$p->expiry - $now;
            $code = $left <= 0 ? 1 : ($left <= 7 * DAYSECS ? 2 : 3);
            if ((int)$p->remind && $code >= (int)$p->remind) {
                continue;
            }
            $proj = $DB->get_record('local_researchcentral_proj', ['id' => $p->projid]);
            if (!$proj) {
                continue;
            }
            $a = (object)['type' => get_string('permit_type_' . $p->type, 'local_researchcentral'), 'ref' => $p->reference ?: '-',
                'date' => userdate($p->expiry, get_string('strftimedatefullshort', 'langconfig')), 'student' => fullname(\core_user::get_user($proj->userid))];
            foreach (array_merge([(int)$proj->userid], portal::supervisor_ids($proj)) as $uid) {
                portal::notify((int)$uid, get_string($code === 1 ? 'msg_permit_expired_subject' : 'msg_permit_expiring_subject', 'local_researchcentral', $a),
                    get_string($code === 1 ? 'msg_permit_expired_body' : 'msg_permit_expiring_body', 'local_researchcentral', $a),
                    research::url((int)$proj->id, 'permits'), 'reminder');
            }
            $DB->update_record('local_researchcentral_permit', (object)['id' => $p->id, 'remind' => $code, 'status' => $code === 1 ? 'expired' : $p->status]);
        }
    }

    /** Corrections due within 7 days. */
    protected function corrections(): void {
        global $DB;
        $now = time();
        foreach ($DB->get_records_select('local_researchcentral_viva', "corrdone = 0 AND corrections <> '' AND corrdeadline > ? AND corrdeadline < ? AND remind = 0",
                [$now, $now + 7 * DAYSECS]) as $v) {
            $proj = $DB->get_record('local_researchcentral_proj', ['id' => $v->projid]);
            if (!$proj) {
                continue;
            }
            $a = (object)['date' => userdate($v->corrdeadline, get_string('strftimedatefullshort', 'langconfig')), 'student' => fullname(\core_user::get_user($proj->userid))];
            foreach (array_merge([(int)$proj->userid], portal::supervisor_ids($proj)) as $uid) {
                portal::notify((int)$uid, get_string('msg_corrdue_subject', 'local_researchcentral', $a), get_string('msg_corrdue_body', 'local_researchcentral', $a),
                    research::url((int)$proj->id, 'viva'), 'reminder');
            }
            $DB->set_field('local_researchcentral_viva', 'remind', 1, ['id' => $v->id]);
        }
    }

    /** Mondays: each supervisor gets the high and medium risk students; coordinators get counts for their schools. */
    protected function digest(array $projects): void {
        $scores = risk::scores($projects, false);
        $bysup = $bycoord = [];
        foreach ($projects as $p) {
            $r = $scores[$p->id] ?? null;
            if (!$r || $r['level'] === 'low') {
                continue;
            }
            $line = fullname(\core_user::get_user($p->userid)) . ' (' . get_string('risk_' . $r['level'], 'local_researchcentral') . '): ' .
                implode(', ', array_map(fn($s) => get_string('risk_sig_' . $s, 'local_researchcentral'), $r['signals']));
            foreach (portal::supervisor_ids($p) as $sid) {
                $bysup[$sid][$r['level']][] = $line;
            }
            foreach (portal::coordinator_ids($p) as $cid) {
                $bycoord[$cid][$r['level']] = ($bycoord[$cid][$r['level']] ?? 0) + 1;
            }
        }
        $url = new \moodle_url('/local/researchcentral/portal.php');
        foreach ($bysup as $sid => $levels) {
            $a = (object)['high' => count($levels['high'] ?? []), 'medium' => count($levels['medium'] ?? []),
                'list' => implode("\n", array_merge($levels['high'] ?? [], $levels['medium'] ?? []))];
            portal::notify((int)$sid, get_string('msg_risk_subject', 'local_researchcentral', $a), get_string('msg_risk_body', 'local_researchcentral', $a), $url, 'workflow');
        }
        foreach ($bycoord as $cid => $levels) {
            if (isset($bysup[$cid])) {
                continue; // Already received a personal digest.
            }
            $a = (object)['high' => $levels['high'] ?? 0, 'medium' => $levels['medium'] ?? 0, 'list' => get_string('risk_coord_hint', 'local_researchcentral')];
            portal::notify((int)$cid, get_string('msg_risk_subject', 'local_researchcentral', $a), get_string('msg_risk_body', 'local_researchcentral', $a), $url, 'workflow');
        }
    }
}
