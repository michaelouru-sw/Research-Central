<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/** Supervision meetings: flexible slot generation, allocation to students, online links, attendance verification. */
class appt {
    const PLATFORMS = ['inperson', 'zoom', 'meet', 'teams', 'other'];

    public static function platforms(): array {
        $o = [];
        foreach (self::PLATFORMS as $p) {
            $o[$p] = get_string('platform_' . $p, 'local_researchcentral');
        }
        return $o;
    }

    public static function is_online(\stdClass $a): bool {
        return ($a->platform ?? 'inperson') !== 'inperson' || trim((string)($a->joinurl ?? '')) !== '';
    }

    /** Saved personal meeting link for a platform (zoom, meet, teams). */
    public static function default_link(int $userid, string $platform): string {
        return (string)get_user_preferences('local_researchcentral_link_' . $platform, '', $userid);
    }

    /** Zoom and Google Meet activities in the supervisor's courses: [url => label]. */
    public static function activity_links(int $userid): array {
        global $CFG;
        $out = [];
        try {
            require_once($CFG->libdir . '/enrollib.php');
            foreach (enrol_get_users_courses($userid, true, 'id, fullname, shortname') as $c) {
                $info = get_fast_modinfo($c, $userid);
                foreach (['zoom' => 'zoom', 'googlemeet' => 'meet'] as $mod => $platform) {
                    foreach ($info->get_instances_of($mod) as $cm) {
                        if ($cm->uservisible) {
                            $out[$platform . '|' . $cm->url->out(false)] = get_string('platform_' . $platform, 'local_researchcentral') . ': ' .
                                format_string($cm->name) . ' (' . format_string($c->shortname) . ')';
                        }
                    }
                }
                if (count($out) >= 60) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            debugging($e->getMessage(), DEBUG_DEVELOPER);
        }
        return $out;
    }

    /** Overlap test for a person across non-cancelled appointments. */
    public static function clash(int $userid, int $start, int $end, int $excludeid = 0): bool {
        global $DB;
        return $DB->record_exists_select('local_researchcentral_appt',
            "(supervisorid = :u OR studentid = :u2) AND status <> 'cancelled' AND id <> :x AND starttime < :e AND endtime > :s",
            ['u' => $userid, 'u2' => $userid, 'x' => $excludeid, 'e' => $end, 's' => $start]);
    }

    /**
     * Create many slots at once: a date range, chosen weekdays, up to three daily time windows.
     * @param array $p dates (day timestamps from/to), days (0-6), windows [[startmin,endmin],...], duration, gap, platform, location,
     *        joinurl, agenda, supnote, projid (reserve each slot for that student, optional)
     * @return int[] [created, skipped]
     */
    public static function generate(int $supervisorid, array $p): array {
        global $DB;
        $created = $skipped = 0;
        $now = time();
        $from = (int)$p['from'];
        $to = max($from, (int)($p['to'] ?? $from));
        $days = min(92, (int)floor(($to - $from) / DAYSECS) + 1);
        $dur = max(10, (int)$p['duration']) * 60;
        $gap = max(0, (int)($p['gap'] ?? 0)) * 60;
        $proj = !empty($p['projid']) ? $DB->get_record('local_researchcentral_proj', ['id' => (int)$p['projid']]) : null;
        if ($proj && !portal::is_project_supervisor($proj, $supervisorid)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'allocate');
        }
        for ($i = 0; $i < $days; $i++) {
            $d = usergetdate($from + $i * DAYSECS + 12 * HOURSECS);
            if ($days > 1 && !in_array((int)$d['wday'], $p['days'], true)) {
                continue;
            }
            foreach ($p['windows'] as [$ws, $we]) {
                for ($t = (int)$ws; $t + (int)($dur / 60) <= (int)$we; $t += (int)(($dur + $gap) / 60)) {
                    $start = make_timestamp($d['year'], $d['mon'], $d['mday'], intdiv($t, 60), $t % 60, 0);
                    $end = $start + $dur;
                    if ($start <= $now || self::clash($supervisorid, $start, $end) || ($proj && self::clash((int)$proj->userid, $start, $end))) {
                        $skipped++;
                        continue;
                    }
                    if ($created >= 300) {
                        break 3;
                    }
                    $id = $DB->insert_record('local_researchcentral_appt', (object)[
                        'supervisorid' => $supervisorid, 'studentid' => $proj ? $proj->userid : 0, 'projid' => $proj ? $proj->id : 0,
                        'starttime' => $start, 'endtime' => $end, 'location' => trim((string)$p['location']),
                        'status' => $proj ? 'booked' : 'open', 'topic' => $proj ? trim((string)($p['agenda'] ?? '')) : '', 'timecreated' => $now,
                        'platform' => $p['platform'], 'joinurl' => trim((string)$p['joinurl']), 'cmid' => 0,
                        'agenda' => trim((string)($p['agenda'] ?? '')), 'supnote' => trim((string)($p['supnote'] ?? '')), 'minutes' => '',
                        'allocated' => $proj ? 1 : 0]);
                    $created++;
                    if ($proj) {
                        $a = $DB->get_record('local_researchcentral_appt', ['id' => $id]);
                        cal::sync_events($a);
                        self::tell($a, 'allocated', (int)$proj->userid);
                    }
                }
            }
        }
        return [$created, $skipped];
    }

    /** Message context values for an appointment. */
    public static function info(\stdClass $a): \stdClass {
        $stu = $a->studentid ? \core_user::get_user($a->studentid) : null;
        $sup = \core_user::get_user($a->supervisorid);
        $when = userdate($a->starttime, get_string('strftimedatetimeshort', 'langconfig')) . ' – ' . userdate($a->endtime, '%H:%M');
        return (object)['when' => $when, 'who' => $stu ? fullname($stu) : '-', 'supervisor' => $sup ? fullname($sup) : '-',
            'topic' => (string)$a->topic ?: '-', 'where' => cal::where($a) ?: '-',
            'join' => trim((string)$a->joinurl) !== '' ? (new \moodle_url('/local/researchcentral/join.php', ['id' => $a->id]))->out(false) : '-',
            'gcal' => cal::google_url($a), 'agenda' => (string)$a->agenda ?: '-'];
    }

    /** Notify the student of an allocated slot or booking details. $kind: allocated | changed */
    public static function tell(\stdClass $a, string $kind, int $to): void {
        $info = self::info($a);
        portal::notify($to, get_string('msg_appt_' . $kind . '_subject', 'local_researchcentral', $info),
            get_string('msg_appt_' . $kind . '_body', 'local_researchcentral', $info),
            new \moodle_url('/local/researchcentral/appointments.php'), 'booking');
    }

    /** Verification state: upcoming | awaiting | verified | disputed | missed | cancelled | open */
    public static function verification(\stdClass $a): string {
        if ($a->status === 'cancelled') {
            return 'cancelled';
        }
        if ($a->status === 'open') {
            return 'open';
        }
        $s = (int)$a->studentconf;
        $v = (int)$a->supconf;
        if ($s === 1 && $v === 1) {
            return 'verified';
        }
        if ($s === 2 && $v === 2) {
            return 'missed';
        }
        if ($s && $v && $s !== $v) {
            return 'disputed';
        }
        return time() < (int)$a->endtime ? 'upcoming' : 'awaiting';
    }

    /** Record one party's confirmation that the meeting took place (or not). */
    public static function confirm(\stdClass $a, int $userid, bool $attended): void {
        global $DB;
        $isstu = (int)$a->studentid === $userid;
        $issup = (int)$a->supervisorid === $userid;
        if (!$isstu && !$issup) {
            throw new \moodle_exception('nopermissions', 'error', '', 'confirm');
        }
        if (time() < (int)$a->starttime) {
            throw new \moodle_exception('appt_toosoon', 'local_researchcentral');
        }
        $upd = (object)['id' => $a->id];
        if ($isstu) {
            $upd->studentconf = $attended ? 1 : 2;
            $upd->studentconftime = time();
            $a->studentconf = $upd->studentconf;
        } else {
            $upd->supconf = $attended ? 1 : 2;
            $upd->supconftime = time();
            $a->supconf = $upd->supconf;
        }
        if ((int)$a->studentconf === 1 && (int)$a->supconf === 1) {
            $upd->status = 'done';
        }
        $DB->update_record('local_researchcentral_appt', $upd);
        if ($a->projid) {
            portal::log((int)$a->projid, 'meeting', userdate($a->starttime, get_string('strftimedatetimeshort', 'langconfig')) . ': ' .
                get_string('av_' . self::verification($a), 'local_researchcentral'), $userid);
        }
        if (self::verification($a) === 'disputed') {
            $info = self::info($a);
            foreach ([(int)$a->studentid, (int)$a->supervisorid] as $uid) {
                portal::notify($uid, get_string('msg_appt_disputed_subject', 'local_researchcentral', $info),
                    get_string('msg_appt_disputed_body', 'local_researchcentral', $info), new \moodle_url('/local/researchcentral/appointments.php'), 'booking');
            }
        }
    }

    /** Record that a participant used the join link (evidence for verification). */
    public static function record_join(\stdClass $a, int $userid): void {
        global $DB;
        $f = (int)$a->studentid === $userid ? 'studentjoin' : ((int)$a->supervisorid === $userid ? 'supjoin' : '');
        if ($f && !(int)$a->$f && time() > (int)$a->starttime - 1800 && time() < (int)$a->endtime + 3600) {
            $DB->set_field('local_researchcentral_appt', $f, time(), ['id' => $a->id]);
        }
    }

    /** Scheduled: 24h and 1h reminders and post-meeting confirmation requests. */
    public static function run_reminders(): void {
        global $DB;
        $now = time();
        $url = new \moodle_url('/local/researchcentral/appointments.php');
        $rs = $DB->get_recordset_select('local_researchcentral_appt', "status = 'booked' AND studentid > 0 AND starttime > :a AND starttime < :b",
            ['a' => $now, 'b' => $now + DAYSECS + 900]);
        foreach ($rs as $a) {
            $left = (int)$a->starttime - $now;
            $kind = null;
            if ($left <= 3600 + 900 && !(int)$a->rem1) {
                $kind = 'rem1';
            } else if ($left > 3 * 3600 && !(int)$a->rem24) {
                $kind = 'rem24';
            }
            if (!$kind) {
                continue;
            }
            $info = self::info($a);
            foreach ([(int)$a->studentid, (int)$a->supervisorid] as $uid) {
                portal::notify($uid, get_string('msg_appt_' . $kind . '_subject', 'local_researchcentral', $info),
                    get_string('msg_appt_remind_body', 'local_researchcentral', $info), $url, 'booking');
            }
            $DB->set_field('local_researchcentral_appt', $kind, $now, ['id' => $a->id]);
        }
        $rs->close();
        // Confirmation requests once the meeting has ended, repeated every three days while someone has not answered.
        $sql = "SELECT * FROM {local_researchcentral_appt} WHERE status = 'booked' AND studentid > 0 AND endtime < :e AND endtime > :old
                   AND (studentconf = 0 OR supconf = 0) AND (confasked = 0 OR confasked < :again)";
        foreach ($DB->get_recordset_sql($sql, ['e' => $now - 600, 'old' => $now - 14 * DAYSECS, 'again' => $now - 3 * DAYSECS]) as $a) {
            $info = self::info($a);
            foreach ([[(int)$a->studentid, (int)$a->studentconf], [(int)$a->supervisorid, (int)$a->supconf]] as [$uid, $done]) {
                if (!$done) {
                    portal::notify($uid, get_string('msg_appt_confirm_subject', 'local_researchcentral', $info),
                        get_string('msg_appt_confirm_body', 'local_researchcentral', $info), $url, 'booking');
                }
            }
            $DB->set_field('local_researchcentral_appt', 'confasked', $now, ['id' => $a->id]);
        }
    }
}
