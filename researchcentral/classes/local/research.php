<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/** Researcher workspace: feedback responses, permits, publications, check-ins, viva and "what next". */
class research {
    const RESP_STATUS = ['open', 'addressed', 'partial', 'disagree'];
    const PERMIT_TYPES = ['ethics', 'nacosti', 'institutional', 'data', 'other'];
    const PERMIT_STATUS = ['pending', 'approved', 'expired', 'rejected'];
    const PUB_TYPES = ['journal', 'conference', 'chapter', 'preprint', 'poster', 'other'];
    const PUB_STATUS = ['draft', 'submitted', 'revision', 'accepted', 'published'];
    const VIVA_OUTCOMES = ['pass', 'minor', 'major', 'resubmit', 'fail'];

    public static function url(int $projid, string $tab = '', array $extra = []): \moodle_url {
        return new \moodle_url('/local/researchcentral/workspace.php', ['id' => $projid] + ($tab !== '' ? ['tab' => $tab] : []) + $extra);
    }

    // ---- Feedback responses (issue-by-issue) ----

    /** Split a reviewer comment into separate points (numbered lines, bullets or paragraphs). */
    public static function items(string $comment): array {
        $c = trim(str_replace("\r", '', $comment));
        if ($c === '') {
            return [];
        }
        $parts = preg_split('/\n\s*\n|\n(?=\s*(?:\d{1,2}[\.\)]|[-\x{2022}*])\s)/u', $c) ?: [$c];
        return array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
    }

    /**
     * Review points that need a response: for every stage whose latest decision asked for revision.
     * @return array list of ['pms' => record, 'round' => int, 'reviews' => [['review' => rec, 'items' => [..], 'resp' => [itemno => rec]]]]
     */
    public static function response_groups(\stdClass $proj): array {
        global $DB;
        $out = [];
        $ms = $DB->get_records_sql("SELECT m.*, s.name AS stagename FROM {local_researchcentral_pms} m JOIN {local_researchcentral_stage} s ON s.id = m.stageid
                                    WHERE m.projid = ? ORDER BY s.sortorder, s.id", [$proj->id]);
        foreach ($ms as $m) {
            $dec = $DB->get_records('local_researchcentral_decision', ['pmsid' => $m->id], 'round DESC', '*', 0, 1);
            $dec = $dec ? reset($dec) : null;
            if (!$dec || $dec->decision !== 'revision') {
                continue;
            }
            $reviews = [];
            foreach (review::active((int)$m->id, (int)$dec->round) as $r) {
                $items = self::items((string)$r->comment);
                if (!$items) {
                    continue;
                }
                $resp = [];
                foreach ($DB->get_records('local_researchcentral_fbresp', ['reviewid' => $r->id]) as $x) {
                    $resp[(int)$x->itemno] = $x;
                }
                $reviews[] = ['review' => $r, 'items' => $items, 'resp' => $resp];
            }
            if ($reviews) {
                $out[] = ['pms' => $m, 'round' => (int)$dec->round, 'reviews' => $reviews, 'decision' => $dec];
            }
        }
        return $out;
    }

    /** [open items, items waiting for the supervisor to verify] across a project. */
    public static function response_counts(\stdClass $proj): array {
        $open = $verify = 0;
        foreach (self::response_groups($proj) as $g) {
            foreach ($g['reviews'] as $rv) {
                foreach ($rv['items'] as $i => $text) {
                    $x = $rv['resp'][$i + 1] ?? null;
                    if (!$x || $x->status === 'open') {
                        $open++;
                    } else if ((int)$x->verified === 0) {
                        $verify++;
                    }
                }
            }
        }
        return [$open, $verify];
    }

    public static function save_responses(\stdClass $proj, array $resp, array $status): int {
        global $DB, $USER;
        $n = 0;
        $now = time();
        $valid = [];
        foreach (self::response_groups($proj) as $g) {
            foreach ($g['reviews'] as $rv) {
                $valid[(int)$rv['review']->id] = count($rv['items']);
            }
        }
        foreach ($resp as $rid => $items) {
            $rid = (int)$rid;
            if (!isset($valid[$rid])) {
                continue;
            }
            foreach ((array)$items as $no => $text) {
                $no = (int)$no;
                $text = trim((string)$text);
                $st = $status[$rid][$no] ?? 'open';
                if ($no < 1 || $no > $valid[$rid] || !in_array($st, self::RESP_STATUS, true)) {
                    continue;
                }
                $old = $DB->get_record('local_researchcentral_fbresp', ['reviewid' => $rid, 'itemno' => $no]);
                if ($old && $old->response === $text && $old->status === $st) {
                    continue;
                }
                if ($text === '' && $st === 'open' && !$old) {
                    continue;
                }
                $row = (object)['projid' => $proj->id, 'reviewid' => $rid, 'itemno' => $no, 'response' => $text, 'status' => $st,
                    'verified' => 0, 'vernote' => '', 'verby' => 0, 'vertime' => 0, 'timemodified' => $now];
                if ($old) {
                    $row->id = $old->id;
                    $DB->update_record('local_researchcentral_fbresp', $row);
                } else {
                    $row->timecreated = $now;
                    $DB->insert_record('local_researchcentral_fbresp', $row);
                }
                $n++;
            }
        }
        if ($n) {
            portal::log((int)$proj->id, 'response', (string)$n);
            $a = (object)['student' => fullname(\core_user::get_user($proj->userid)), 'n' => $n, 'title' => format_string($proj->title)];
            foreach (portal::supervisor_ids($proj) as $sid) {
                portal::notify($sid, get_string('msg_response_subject', 'local_researchcentral', $a),
                    get_string('msg_response_body', 'local_researchcentral', $a), self::url((int)$proj->id, 'responses'), 'workflow');
            }
        }
        return $n;
    }

    /** Supervisor verifies how a student addressed a point: 1 accepted, 2 needs more work. */
    public static function save_verification(\stdClass $proj, array $ver, array $note): int {
        global $DB, $USER;
        if (!portal::is_project_supervisor($proj) && !portal::can_manage_project($proj)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'verify');
        }
        $n = 0;
        foreach ($ver as $rid => $items) {
            foreach ((array)$items as $no => $v) {
                $v = (int)$v;
                $x = $DB->get_record('local_researchcentral_fbresp', ['reviewid' => (int)$rid, 'itemno' => (int)$no, 'projid' => $proj->id]);
                if (!$x || !in_array($v, [1, 2], true)) {
                    continue;
                }
                $vn = trim((string)($note[$rid][$no] ?? ''));
                if ((int)$x->verified === $v && $x->vernote === $vn) {
                    continue;
                }
                $DB->update_record('local_researchcentral_fbresp', (object)['id' => $x->id, 'verified' => $v, 'vernote' => $vn,
                    'verby' => $USER->id, 'vertime' => time()]);
                $n++;
            }
        }
        if ($n) {
            $a = (object)['n' => $n, 'title' => format_string($proj->title), 'who' => fullname($USER)];
            portal::notify((int)$proj->userid, get_string('msg_verified_subject', 'local_researchcentral', $a),
                get_string('msg_verified_body', 'local_researchcentral', $a), self::url((int)$proj->id, 'responses'), 'workflow');
        }
        return $n;
    }

    // ---- Check-ins ----

    public static function period(?int $t = null): string {
        return userdate($t ?? time(), '%Y-%m', 99, false);
    }

    public static function checkin_enabled(): bool {
        return (bool)portal::cfg('checkin_on', 1);
    }

    public static function checkin_done(int $projid, ?string $period = null): bool {
        global $DB;
        return $DB->record_exists('local_researchcentral_checkin', ['projid' => $projid, 'period' => $period ?? self::period()]);
    }

    // ---- Permits ----

    public static function permit_state(\stdClass $p): string {
        if ($p->status === 'approved' && (int)$p->expiry > 0 && (int)$p->expiry < time()) {
            return 'expired';
        }
        return (string)$p->status;
    }

    // ---- What next ----

    /**
     * Ordered next actions for a person on a project.
     * @return array of ['key' => lang key, 'a' => params, 'url' => moodle_url, 'level' => high|med|info]
     */
    public static function next_actions(\stdClass $proj, int $userid): array {
        global $DB;
        $now = time();
        $out = [];
        $isstu = (int)$proj->userid === $userid;
        $issup = portal::is_project_supervisor($proj, $userid);
        $purl = portal::project_url($proj);
        $ms = portal::milestones([$proj->id])[$proj->id] ?? [];
        $add = function (string $key, $a, \moodle_url $url, string $level) use (&$out) {
            $out[] = ['key' => $key, 'a' => $a, 'url' => $url, 'level' => $level];
        };
        if ($isstu) {
            foreach ($ms as $m) {
                $name = format_string($m->stagename);
                if (portal::is_overdue($m)) {
                    $add('na_overdue', (object)['stage' => $name, 'date' => userdate($m->duedate, get_string('strftimedatefullshort', 'langconfig'))], $purl, 'high');
                } else if ($m->status === portal::REVISION) {
                    $add('na_revise', $name, $purl, 'high');
                } else if ($m->status === portal::PENDING && $m->duedate && $m->duedate - $now < 14 * DAYSECS && $m->duedate > $now) {
                    $add('na_due_soon', (object)['stage' => $name, 'date' => userdate($m->duedate, get_string('strftimedatefullshort', 'langconfig'))], $purl, 'med');
                }
            }
            [$open, $verify] = self::response_counts($proj);
            if ($open) {
                $add('na_respond', $open, self::url((int)$proj->id, 'responses'), 'high');
            }
            if ($verify) {
                $add('na_waiting_verify', $verify, self::url((int)$proj->id, 'responses'), 'info');
            }
            if (self::checkin_enabled() && !self::checkin_done((int)$proj->id)) {
                $add('na_checkin', null, self::url((int)$proj->id, 'checkins'), 'med');
            }
            foreach ($DB->get_records('local_researchcentral_permit', ['projid' => $proj->id]) as $p) {
                $st = self::permit_state($p);
                if ($st === 'expired' || ($st === 'approved' && $p->expiry && $p->expiry - $now < 30 * DAYSECS)) {
                    $add('na_permit', (object)['type' => get_string('permit_type_' . $p->type, 'local_researchcentral'),
                        'date' => userdate($p->expiry, get_string('strftimedatefullshort', 'langconfig'))], self::url((int)$proj->id, 'permits'), $st === 'expired' ? 'high' : 'med');
                }
            }
            $viva = $DB->get_record_select('local_researchcentral_viva', 'projid = ? AND corrdone = 0 AND corrections <> ?', [$proj->id, ''], '*', IGNORE_MULTIPLE);
            if ($viva) {
                $add('na_corrections', $viva->corrdeadline ? userdate($viva->corrdeadline, get_string('strftimedatefullshort', 'langconfig')) : '-',
                    self::url((int)$proj->id, 'viva'), 'high');
            }
        }
        if ($issup) {
            foreach ($ms as $m) {
                if ($m->status === portal::SUBMITTED && review::can_review($proj, $m, $userid) && !isset(review::active((int)$m->id)[$userid])) {
                    $add('na_review', format_string($m->stagename), new \moodle_url('/local/researchcentral/stage.php', ['id' => $m->id]), 'high');
                }
            }
            [, $verify] = self::response_counts($proj);
            if ($verify) {
                $add('na_verify', $verify, self::url((int)$proj->id, 'responses'), 'med');
            }
            $ci = $DB->get_records('local_researchcentral_checkin', ['projid' => $proj->id], 'timecreated DESC', '*', 0, 1);
            $ci = $ci ? reset($ci) : null;
            if ($ci && !$ci->supseen) {
                $add($ci->needsupport ? 'na_checkin_support' : 'na_checkin_new', null, self::url((int)$proj->id, 'checkins'), $ci->needsupport ? 'high' : 'info');
            }
        }
        foreach (meetings::open_for((int)$proj->id, $userid) as $act) {
            $late = $act->duedate && $act->duedate < $now;
            $add('na_mact', (object)['action' => $act->descr, 'due' => $act->duedate ? userdate($act->duedate, get_string('strftimedatefullshort', 'langconfig')) : '-'],
                self::url((int)$proj->id, 'meetings'), $late ? 'high' : 'med');
        }
        // Meetings for both sides.
        $col = $isstu ? 'studentid' : 'supervisorid';
        $col2 = $isstu ? 'studentconf' : 'supconf';
        foreach ($DB->get_records_select('local_researchcentral_appt', "projid = ? AND $col = ? AND status IN ('booked','done') AND starttime > ? ORDER BY starttime",
                [$proj->id, $userid, $now - 14 * DAYSECS]) as $a) {
            if ($a->starttime > $now && $a->starttime - $now < 7 * DAYSECS) {
                $add('na_meeting_soon', userdate($a->starttime, get_string('strftimedatetimeshort', 'langconfig')), new \moodle_url('/local/researchcentral/appointments.php'), 'info');
            } else if ($a->endtime < $now && !(int)$a->$col2) {
                $add('na_confirm_meeting', userdate($a->starttime, get_string('strftimedatetimeshort', 'langconfig')), new \moodle_url('/local/researchcentral/appointments.php'), 'med');
            }
        }
        $rank = ['high' => 0, 'med' => 1, 'info' => 2];
        usort($out, fn($x, $y) => $rank[$x['level']] <=> $rank[$y['level']]);
        return $out;
    }
}
