<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Core logic: thesis creation, milestone sync with activity completion, and analytics queries.
 */
class tracker {
    const PENDING = 0;
    const COMPLETE = 1;
    const OVERDUE = 2;

    /** Is this course designated as a thesis course? Only designated courses use the tracker. */
    public static function is_enabled(int $courseid): bool {
        global $DB;
        static $cache = [];
        if (!isset($cache[$courseid])) {
            $cache[$courseid] = $DB->record_exists('local_researchcentral_course', ['courseid' => $courseid]);
        }
        return $cache[$courseid];
    }

    public static function require_enabled(int $courseid): void {
        if (!self::is_enabled($courseid)) {
            throw new \moodle_exception('notenabled', 'local_researchcentral', new \moodle_url('/course/view.php', ['id' => $courseid]));
        }
    }

    public static function enable_course(int $courseid): void {
        global $DB;
        if (!$DB->record_exists('local_researchcentral_course', ['courseid' => $courseid]) && $DB->record_exists('course', ['id' => $courseid])) {
            $DB->insert_record('local_researchcentral_course', (object)['courseid' => $courseid, 'timecreated' => time()]);
        }
    }

    public static function disable_course(int $courseid): void {
        global $DB;
        $DB->delete_records('local_researchcentral_course', ['courseid' => $courseid]);
    }

    /**
     * Detect what the user is in a course: manager (capability), supervisor (capability OR assigned
     * on a thesis), student (has a thesis OR capability).
     */
    public static function get_roles(int $userid, int $courseid): array {
        global $DB;
        $context = \context_course::instance($courseid);
        return [
            'manager' => has_capability('local/researchcentral:manage', $context, $userid),
            'supervisor' => has_capability('local/researchcentral:supervise', $context, $userid)
                || $DB->record_exists('local_researchcentral_thesis', ['courseid' => $courseid, 'supervisorid' => $userid]),
            'student' => $DB->record_exists('local_researchcentral_thesis', ['courseid' => $courseid, 'userid' => $userid])
                || has_capability('local/researchcentral:viewown', $context, $userid),
        ];
    }

    /** Human label for the highest role: manager > supervisor > student. */
    public static function role_labels(array $roles): array {
        $out = [];
        foreach (['manager', 'supervisor', 'student'] as $r) {
            if (!empty($roles[$r])) {
                $out[] = get_string('role_' . $r, 'local_researchcentral');
            }
        }
        return $out;
    }

    /** Courses where the user has any thesis role: [courseid => roles]. */
    public static function get_user_courses(int $userid): array {
        global $DB;
        $ids = $DB->get_fieldset_select('local_researchcentral_thesis', 'DISTINCT courseid',
            'userid = ? OR supervisorid = ?', [$userid, $userid]);
        foreach (enrol_get_my_courses('id') as $c) {
            $ids[] = $c->id;
        }
        if (is_siteadmin($userid)) {
            $ids = array_merge($ids, $DB->get_fieldset_sql('SELECT courseid FROM {local_researchcentral_course}'));
        }
        $out = [];
        foreach (array_unique($ids) as $cid) {
            if (!self::is_enabled((int)$cid)) {
                continue; // Not a designated thesis course.
            }
            $roles = self::get_roles($userid, (int)$cid);
            if ($roles['manager'] || $roles['supervisor'] || $roles['student']) {
                $out[(int)$cid] = $roles;
            }
        }
        return $out;
    }

    /** @return string[] level key => label */
    public static function levels(bool $withall = false): array {
        $levels = [];
        if ($withall) {
            $levels['all'] = get_string('level_all', 'local_researchcentral');
        }
        $levels['diploma'] = get_string('level_diploma', 'local_researchcentral');
        $levels['ug'] = get_string('level_ug', 'local_researchcentral');
        $levels['masters'] = get_string('level_masters', 'local_researchcentral');
        $levels['phd'] = get_string('level_phd', 'local_researchcentral');
        return $levels;
    }

    /**
     * Create a thesis and instantiate milestones from the course's stage templates.
     */
    public static function create_thesis(int $courseid, int $userid, int $supervisorid, string $level,
            string $title, int $startdate): int {
        global $DB;
        $now = time();
        $thesisid = $DB->insert_record('local_researchcentral_thesis', (object)[
            'courseid' => $courseid, 'userid' => $userid, 'supervisorid' => $supervisorid,
            'level' => $level, 'title' => $title, 'startdate' => $startdate, 'timecreated' => $now,
        ]);
        $stages = $DB->get_records_select('local_researchcentral_stage',
            "courseid = :c AND (level = 'all' OR level = :l)", ['c' => $courseid, 'l' => $level], 'sortorder ASC, id ASC');
        foreach ($stages as $stage) {
            $DB->insert_record('local_researchcentral_ms', (object)[
                'thesisid' => $thesisid, 'stageid' => $stage->id,
                'duedate' => $startdate + ((int)$stage->defaultdays * DAYSECS),
                'status' => self::PENDING, 'timecompleted' => 0, 'timemodified' => $now,
            ]);
        }
        self::sync_for_user($courseid, $userid);
        return $thesisid;
    }

    /** Re-evaluate every milestone of one student in one course. */
    public static function sync_for_user(int $courseid, int $userid): void {
        global $DB;
        $sql = "SELECT m.id, m.status, m.duedate, s.cmid
                  FROM {local_researchcentral_ms} m
                  JOIN {local_researchcentral_thesis} t ON t.id = m.thesisid
                  JOIN {local_researchcentral_stage} s ON s.id = m.stageid
                 WHERE t.courseid = :courseid AND t.userid = :userid";
        foreach ($DB->get_records_sql($sql, ['courseid' => $courseid, 'userid' => $userid]) as $m) {
            self::refresh_milestone($m, $userid);
        }
    }

    /** Re-evaluate every thesis in a course. */
    public static function sync_course(int $courseid): void {
        global $DB;
        foreach ($DB->get_records('local_researchcentral_thesis', ['courseid' => $courseid], '', 'id, userid') as $t) {
            self::sync_for_user($courseid, (int)$t->userid);
        }
    }

    /** Re-evaluate everything (used by the scheduled task). */
    public static function sync_all(): void {
        global $DB;
        $sql = "SELECT t.id, t.courseid, t.userid
                  FROM {local_researchcentral_thesis} t
                  JOIN {local_researchcentral_course} c ON c.courseid = t.courseid";
        foreach ($DB->get_records_sql($sql) as $t) {
            self::sync_for_user((int)$t->courseid, (int)$t->userid);
        }
    }

    protected static function refresh_milestone(\stdClass $m, int $userid): void {
        global $DB;
        $now = time();
        $new = self::PENDING;
        $done = 0;
        if (!empty($m->cmid)) {
            $c = $DB->get_record_select('course_modules_completion',
                'coursemoduleid = ? AND userid = ? AND completionstate IN (1, 2)',
                [$m->cmid, $userid], 'id, timemodified');
            if ($c) {
                $new = self::COMPLETE;
                $done = (int)$c->timemodified;
            }
        } else if ((int)$m->status === self::COMPLETE) {
            return; // Manual stage already marked complete by a supervisor.
        }
        if ($new !== self::COMPLETE && $m->duedate && $m->duedate < $now) {
            $new = self::OVERDUE;
        }
        if ($new !== (int)$m->status) {
            $DB->update_record('local_researchcentral_ms', (object)[
                'id' => $m->id, 'status' => $new, 'timecompleted' => $done, 'timemodified' => $now,
            ]);
        }
    }

    /** Supervisor/manager overview: one row per thesis with progress counts. */
    public static function get_overview(int $courseid, int $supervisorid = 0): array {
        global $DB;
        $params = ['courseid' => $courseid];
        $where = 't.courseid = :courseid';
        if ($supervisorid) {
            $where .= ' AND t.supervisorid = :sup';
            $params['sup'] = $supervisorid;
        }
        $sql = "SELECT t.id, t.userid, t.supervisorid, t.level, t.title, t.startdate,
                       COUNT(m.id) AS total,
                       SUM(CASE WHEN m.status = 1 THEN 1 ELSE 0 END) AS done,
                       SUM(CASE WHEN m.status = 2 THEN 1 ELSE 0 END) AS overdue,
                       MIN(CASE WHEN m.status <> 1 THEN m.duedate ELSE NULL END) AS nextdue
                  FROM {local_researchcentral_thesis} t
             LEFT JOIN {local_researchcentral_ms} m ON m.thesisid = t.id
                 WHERE $where
              GROUP BY t.id, t.userid, t.supervisorid, t.level, t.title, t.startdate
              ORDER BY t.id";
        return $DB->get_records_sql($sql, $params);
    }

    /** Milestones of one thesis with stage info. */
    public static function get_milestones(int $thesisid): array {
        global $DB;
        $sql = "SELECT m.id, m.stageid, m.duedate, m.status, m.timecompleted, s.name, s.cmid
                  FROM {local_researchcentral_ms} m
                  JOIN {local_researchcentral_stage} s ON s.id = m.stageid
                 WHERE m.thesisid = :t
              ORDER BY s.sortorder, s.id";
        return $DB->get_records_sql($sql, ['t' => $thesisid]);
    }

    // Document checks.

    /** @return array stageid => true for stages in this course that have document rules. */
    public static function stage_ids_with_rules(int $courseid): array {
        global $DB;
        $sql = "SELECT s.id FROM {local_researchcentral_stage} s
                  JOIN {local_researchcentral_rules} r ON r.stageid = s.id
                 WHERE s.courseid = :c";
        return array_fill_keys(array_keys($DB->get_records_sql($sql, ['c' => $courseid])), true);
    }

    /** @return array stageid => latest report record for the thesis. */
    public static function get_latest_reports(int $thesisid): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records('local_researchcentral_report', ['thesisid' => $thesisid], 'id ASC', 'id, stageid, status, timecreated') as $r) {
            $out[$r->stageid] = $r; // Later ids overwrite earlier ones.
        }
        return $out;
    }

    /** Latest report per thesis+stage: counts by status per stage, and most frequent failing checks. */
    public static function doc_check_summary(int $courseid): array {
        global $DB;
        $sql = "SELECT r.id, r.thesisid, r.stageid, r.status, r.results, s.name
                  FROM {local_researchcentral_report} r
                  JOIN {local_researchcentral_thesis} t ON t.id = r.thesisid
                  JOIN {local_researchcentral_stage} s ON s.id = r.stageid
                 WHERE t.courseid = :c ORDER BY r.id ASC";
        $latest = [];
        foreach ($DB->get_records_sql($sql, ['c' => $courseid]) as $r) {
            $latest[$r->thesisid . '-' . $r->stageid] = $r;
        }
        $stages = [];
        $issues = [];
        foreach ($latest as $r) {
            $stages[$r->stageid]['name'] = $r->name;
            $stages[$r->stageid][$r->status] = ($stages[$r->stageid][$r->status] ?? 0) + 1;
            $data = json_decode((string)$r->results, true);
            foreach ($data['results'] ?? [] as $res) {
                if (in_array($res['status'], ['warn', 'fail'], true)) {
                    $issues[$res['key']] = ($issues[$res['key']] ?? 0) + 1;
                }
            }
        }
        arsort($issues);
        return ['stages' => $stages, 'issues' => $issues];
    }

    // Analytics.

    public static function status_counts(int $courseid): array {
        global $DB;
        $sql = "SELECT m.status, COUNT(1) AS cnt
                  FROM {local_researchcentral_ms} m
                  JOIN {local_researchcentral_thesis} t ON t.id = m.thesisid
                 WHERE t.courseid = :c GROUP BY m.status";
        $out = [self::PENDING => 0, self::COMPLETE => 0, self::OVERDUE => 0];
        foreach ($DB->get_records_sql($sql, ['c' => $courseid]) as $r) {
            $out[(int)$r->status] = (int)$r->cnt;
        }
        return $out;
    }

    public static function stage_stats(int $courseid): array {
        global $DB;
        $sql = "SELECT s.id, s.name, s.sortorder,
                       SUM(CASE WHEN m.status = 1 THEN 1 ELSE 0 END) AS done,
                       SUM(CASE WHEN m.status = 0 THEN 1 ELSE 0 END) AS pending,
                       SUM(CASE WHEN m.status = 2 THEN 1 ELSE 0 END) AS overdue
                  FROM {local_researchcentral_stage} s
             LEFT JOIN {local_researchcentral_ms} m ON m.stageid = s.id
                 WHERE s.courseid = :c
              GROUP BY s.id, s.name, s.sortorder
              ORDER BY s.sortorder, s.id";
        return $DB->get_records_sql($sql, ['c' => $courseid]);
    }

    public static function supervisor_load(int $courseid): array {
        global $DB;
        $sql = "SELECT t.supervisorid, COUNT(DISTINCT t.id) AS theses,
                       SUM(CASE WHEN m.status = 2 THEN 1 ELSE 0 END) AS overdue,
                       SUM(CASE WHEN m.status = 1 THEN 1 ELSE 0 END) AS done,
                       COUNT(m.id) AS total
                  FROM {local_researchcentral_thesis} t
             LEFT JOIN {local_researchcentral_ms} m ON m.thesisid = t.id
                 WHERE t.courseid = :c
              GROUP BY t.supervisorid
              ORDER BY theses DESC";
        return $DB->get_records_sql($sql, ['c' => $courseid]);
    }

    public static function level_counts(int $courseid): array {
        global $DB;
        $sql = "SELECT t.level, COUNT(1) AS cnt FROM {local_researchcentral_thesis} t
                 WHERE t.courseid = :c GROUP BY t.level";
        return $DB->get_records_sql($sql, ['c' => $courseid]);
    }

    /** Average days from thesis start to completion, per stage. */
    public static function avg_days_to_complete(int $courseid): array {
        global $DB;
        $sql = "SELECT m.id, s.id AS stageid, s.name, t.startdate, m.timecompleted
                  FROM {local_researchcentral_ms} m
                  JOIN {local_researchcentral_thesis} t ON t.id = m.thesisid
                  JOIN {local_researchcentral_stage} s ON s.id = m.stageid
                 WHERE t.courseid = :c AND m.status = 1 AND m.timecompleted > 0 AND t.startdate > 0
              ORDER BY s.sortorder, s.id";
        $acc = [];
        foreach ($DB->get_records_sql($sql, ['c' => $courseid]) as $r) {
            $days = max(0, ($r->timecompleted - $r->startdate) / DAYSECS);
            $acc[$r->stageid]['name'] = $r->name;
            $acc[$r->stageid]['sum'] = ($acc[$r->stageid]['sum'] ?? 0) + $days;
            $acc[$r->stageid]['n'] = ($acc[$r->stageid]['n'] ?? 0) + 1;
        }
        $out = [];
        foreach ($acc as $a) {
            $out[] = ['name' => $a['name'], 'avg' => round($a['sum'] / $a['n'], 1), 'n' => $a['n']];
        }
        return $out;
    }
}
