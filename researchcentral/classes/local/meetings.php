<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Supervision meeting log: agenda before, minutes after, and agreed actions with owners and due dates.
 * Open actions appear in "what next" for their owner and feed the early-warning score when overdue.
 */
class meetings {
    const PLANNED = 'planned';
    const HELD = 'held';

    public static function modes(): array {
        return ['inperson' => get_string('meet_mode_inperson', 'local_researchcentral'), 'online' => get_string('meet_mode_online', 'local_researchcentral'),
            'phone' => get_string('meet_mode_phone', 'local_researchcentral')];
    }

    public static function for_project(int $projid): array {
        global $DB;
        return $DB->get_records('local_researchcentral_meet', ['projid' => $projid], 'meetdate DESC, id DESC');
    }

    /** @return array meetid => action[] */
    public static function actions_for(int $projid): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records('local_researchcentral_mact', ['projid' => $projid], 'status DESC, duedate, id') as $a) {
            $out[(int)$a->meetid][] = $a;
        }
        return $out;
    }

    /** Create or update. Staff write minutes; the student may log a meeting and its agenda. */
    public static function save(\stdClass $proj, int $id, int $date, string $mode, string $agenda, ?string $minutes, string $status): int {
        global $DB, $USER;
        $staff = annotate::is_staff($proj);
        $isstudent = (int)$proj->userid === (int)$USER->id;
        if (!$staff && !$isstudent) {
            throw new \moodle_exception('nopermissions', 'error', '', 'meeting');
        }
        $now = time();
        $mode = array_key_exists($mode, self::modes()) ? $mode : 'inperson';
        $status = $status === self::HELD ? self::HELD : self::PLANNED;
        if ($id) {
            $old = $DB->get_record('local_researchcentral_meet', ['id' => $id, 'projid' => $proj->id], '*', MUST_EXIST);
            if (!$staff && (int)$old->createdby !== (int)$USER->id) {
                throw new \moodle_exception('nopermissions', 'error', '', 'meeting');
            }
            $upd = (object)['id' => $id, 'meetdate' => $date, 'mode' => $mode, 'agenda' => $agenda, 'timemodified' => $now];
            if ($staff) {
                $upd->minutes = (string)$minutes;
                $upd->status = $status;
            }
            $DB->update_record('local_researchcentral_meet', $upd);
            if ($staff && trim((string)$minutes) !== '' && trim((string)$old->minutes) !== trim((string)$minutes)) {
                self::tell_minutes($proj, $id);
            }
            return $id;
        }
        $id = $DB->insert_record('local_researchcentral_meet', (object)['projid' => $proj->id, 'meetdate' => $date, 'mode' => $mode, 'agenda' => $agenda,
            'minutes' => $staff ? (string)$minutes : '', 'status' => $staff ? $status : self::PLANNED, 'createdby' => $USER->id, 'timecreated' => $now, 'timemodified' => $now]);
        portal::log((int)$proj->id, 'meeting', userdate($date, get_string('strftimedatefullshort', 'langconfig')));
        if ($staff && trim((string)$minutes) !== '') {
            self::tell_minutes($proj, $id);
        }
        return $id;
    }

    private static function tell_minutes(\stdClass $proj, int $meetid): void {
        global $USER;
        $a = (object)['who' => fullname($USER), 'title' => format_string($proj->title)];
        $url = research::url((int)$proj->id, 'meetings');
        foreach (array_unique(array_merge([(int)$proj->userid], portal::supervisor_ids($proj))) as $u) {
            if ((int)$u !== (int)$USER->id) {
                portal::notify((int)$u, get_string('msg_meet_subject', 'local_researchcentral', $a), get_string('msg_meet_body', 'local_researchcentral', $a), $url, 'workflow');
            }
        }
    }

    public static function delete(\stdClass $proj, int $id): void {
        global $DB;
        if (!annotate::is_staff($proj)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'meeting');
        }
        $DB->delete_records('local_researchcentral_receipt', ['kind' => 'meet', 'itemid' => $id]);
        $DB->delete_records('local_researchcentral_mact', ['meetid' => $id, 'projid' => $proj->id]);
        $DB->delete_records('local_researchcentral_meet', ['id' => $id, 'projid' => $proj->id]);
    }

    public static function add_action(\stdClass $proj, int $meetid, int $ownerid, string $descr, int $due): int {
        global $DB, $USER;
        if (!annotate::is_staff($proj)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'meeting');
        }
        $DB->get_record('local_researchcentral_meet', ['id' => $meetid, 'projid' => $proj->id], 'id', MUST_EXIST);
        $team = array_merge([(int)$proj->userid], portal::supervisor_ids($proj));
        $descr = \core_text::substr(trim($descr), 0, 250);
        if ($descr === '' || !in_array($ownerid, $team, true)) {
            return 0;
        }
        $now = time();
        $id = $DB->insert_record('local_researchcentral_mact', (object)['projid' => $proj->id, 'meetid' => $meetid, 'ownerid' => $ownerid, 'descr' => $descr,
            'duedate' => $due, 'status' => 'open', 'timecreated' => $now, 'timemodified' => $now, 'timedone' => 0]);
        if ($ownerid !== (int)$USER->id) {
            $a = (object)['who' => fullname($USER), 'action' => $descr, 'due' => $due ? userdate($due, get_string('strftimedatefullshort', 'langconfig')) : '-'];
            portal::notify($ownerid, get_string('msg_mact_subject', 'local_researchcentral', $a), get_string('msg_mact_body', 'local_researchcentral', $a),
                research::url((int)$proj->id, 'meetings'), 'workflow');
        }
        return $id;
    }

    public static function set_action(\stdClass $proj, int $actid, bool $done): bool {
        global $DB, $USER;
        $a = $DB->get_record('local_researchcentral_mact', ['id' => $actid, 'projid' => $proj->id]);
        if (!$a || ((int)$a->ownerid !== (int)$USER->id && !annotate::is_staff($proj))) {
            return false;
        }
        $DB->update_record('local_researchcentral_mact', (object)['id' => $a->id, 'status' => $done ? 'done' : 'open',
            'timedone' => $done ? time() : 0, 'timemodified' => time()]);
        return true;
    }

    public static function delete_action(\stdClass $proj, int $actid): void {
        global $DB;
        if (annotate::is_staff($proj)) {
            $DB->delete_records('local_researchcentral_mact', ['id' => $actid, 'projid' => $proj->id]);
        }
    }

    /** Open actions this person owns on a project: for the "what next" card. */
    public static function open_for(int $projid, int $userid): array {
        global $DB;
        return $DB->get_records('local_researchcentral_mact', ['projid' => $projid, 'ownerid' => $userid, 'status' => 'open'], 'duedate, id');
    }

    /** Projects (by id) with overdue open actions: for the risk score. [projid => count] */
    public static function overdue_counts(array $projids): array {
        global $DB;
        $out = [];
        if (!$projids) {
            return $out;
        }
        [$in, $p] = $DB->get_in_or_equal($projids, SQL_PARAMS_NAMED);
        foreach ($DB->get_records_sql("SELECT projid, COUNT(id) AS n FROM {local_researchcentral_mact}
                 WHERE projid $in AND status = 'open' AND duedate > 0 AND duedate < :n GROUP BY projid", $p + ['n' => time()]) as $r) {
            $out[(int)$r->projid] = (int)$r->n;
        }
        return $out;
    }

    /** Latest held meeting per project, for the "no recent meeting" signal. [projid => time] */
    public static function last_held(array $projids): array {
        global $DB;
        $out = [];
        if (!$projids) {
            return $out;
        }
        [$in, $p] = $DB->get_in_or_equal($projids, SQL_PARAMS_NAMED);
        foreach ($DB->get_records_sql("SELECT projid, MAX(meetdate) AS t FROM {local_researchcentral_meet}
                 WHERE projid $in AND status = 'held' AND meetdate < :n GROUP BY projid", $p + ['n' => time()]) as $r) {
            $out[(int)$r->projid] = (int)$r->t;
        }
        return $out;
    }
}
