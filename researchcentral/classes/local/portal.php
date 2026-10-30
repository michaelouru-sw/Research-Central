<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Central tracker (site-level): projects, stage submissions, review decisions and access rules.
 */
class portal {
    /**
     * Entry check for every plugin page. People who are not signed in (or are only on the guest account) get a short,
     * clear message and are sent to the login page; after signing in they come straight back to the page they wanted.
     */
    public static function gate(): void {
        global $PAGE, $FULLME, $SESSION;
        if (!isloggedin() || isguestuser()) {
            if (optional_param('ajax', 0, PARAM_INT) === 1) {
                @header('Content-Type: application/json');
                http_response_code(401);
                echo json_encode(['ok' => false, 'errors' => [get_string('login_expired', 'local_researchcentral')], 'login' => (new \moodle_url('/login/index.php'))->out(false)]);
                exit;
            }
            $SESSION->wantsurl = $FULLME;
            $PAGE->set_context(\context_system::instance());
            $PAGE->set_url(new \moodle_url('/local/researchcentral/portal.php'));
            $PAGE->set_pagelayout('login');
            $PAGE->set_title(get_string('login_required_title', 'local_researchcentral'));
            redirect(new \moodle_url('/login/index.php'), get_string('login_required', 'local_researchcentral'), 4, \core\output\notification::NOTIFY_INFO);
        }
        require_login(null, false);
    }

    const PENDING = 'pending';
    const SUBMITTED = 'submitted';
    const REVISION = 'revision';
    const APPROVED = 'approved';
    /** Levels that may have more than one supervisor. */
    const POSTGRAD = ['masters', 'phd'];

    /** Plugin setting with a default (get_config returns false when never saved). */
    public static function cfg(string $name, $default) {
        $v = get_config('local_researchcentral', $name);
        return ($v === false || $v === null || $v === '') ? $default : $v;
    }

    /** User ids supervising a project: lead first, then co-supervisors. */
    public static function supervisor_ids(\stdClass $proj): array {
        global $DB;
        $co = $DB->get_fieldset_select('local_researchcentral_cosup', 'userid', 'projid = ?', [$proj->id], 'id');
        return array_values(array_unique(array_merge([(int)$proj->supervisorid], array_map('intval', $co))));
    }

    public static function is_project_supervisor(\stdClass $proj, ?int $userid = null): bool {
        global $USER;
        $userid = $userid ?? (int)$USER->id;
        return in_array($userid, self::supervisor_ids($proj), true);
    }

    public static function is_postgrad_prog(int $progid): bool {
        global $DB;
        return in_array((string)$DB->get_field('local_researchcentral_prog', 'level', ['id' => $progid]), self::POSTGRAD, true);
    }

    /** Site-wide coordinator: site admin or system role with the coordinate capability. */
    public static function is_global_coordinator(?int $userid = null): bool {
        global $USER;
        $userid = $userid ?? (int)$USER->id;
        return is_siteadmin($userid)
            || has_capability('local/researchcentral:coordinate', \context_system::instance(), $userid);
    }

    /** @return int[]|null school ids the user coordinates; null means all schools */
    public static function managed_schools(?int $userid = null): ?array {
        global $DB, $USER;
        $userid = $userid ?? (int)$USER->id;
        if (self::is_global_coordinator($userid)) {
            return null;
        }
        return array_map('intval', $DB->get_fieldset_select('local_researchcentral_scoord', 'schoolid', 'userid = ?', [$userid]));
    }

    /** Any coordinator rights at all (global or for at least one school). */
    public static function is_coordinator(?int $userid = null): bool {
        $m = self::managed_schools($userid);
        return $m === null || !empty($m);
    }

    public static function can_manage_school(int $schoolid, ?int $userid = null): bool {
        $m = self::managed_schools($userid);
        return $m === null || ($schoolid > 0 && in_array($schoolid, $m, true));
    }

    public static function can_manage_prog(\stdClass $prog, ?int $userid = null): bool {
        return self::can_manage_school((int)$prog->schoolid, $userid);
    }

    public static function can_manage_project(\stdClass $proj, ?int $userid = null): bool {
        global $DB;
        $m = self::managed_schools($userid);
        if ($m === null) {
            return true;
        }
        if (!$m) {
            return false;
        }
        $sid = (int)$DB->get_field('local_researchcentral_prog', 'schoolid', ['id' => $proj->progid]);
        return in_array($sid, $m, true);
    }

    public static function require_manage_project(\stdClass $proj): void {
        if (!self::can_manage_project($proj)) {
            throw new \moodle_exception('nopermissions', 'error', '', get_string('role_coordinator', 'local_researchcentral'));
        }
    }

    public static function require_manage_prog(\stdClass $prog): void {
        if (!self::can_manage_prog($prog)) {
            throw new \moodle_exception('nopermissions', 'error', '', get_string('role_coordinator', 'local_researchcentral'));
        }
    }

    public static function is_supervisor(?int $userid = null): bool {
        global $DB, $USER;
        $userid = $userid ?? (int)$USER->id;
        return $DB->record_exists('local_researchcentral_proj', ['supervisorid' => $userid])
            || $DB->record_exists('local_researchcentral_cosup', ['userid' => $userid]);
    }

    public static function is_student(?int $userid = null): bool {
        global $DB, $USER;
        return $DB->record_exists('local_researchcentral_proj', ['userid' => $userid ?? (int)$USER->id]);
    }

    /** Roles available to the user, in display order. */
    public static function roles(): array {
        global $USER;
        static $memo = [];
        $key = (int)($USER->id ?? 0);
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        $cache = \cache::make('local_researchcentral', 'roles');
        $hit = $key ? $cache->get($key) : false;
        if ($hit === false) {
            $hit = self::compute_roles();
            if ($key) {
                $cache->set($key, $hit);
            }
        }
        return $memo[$key] = $hit;
    }

    /** Drop cached roles after any change to projects, supervisors or coordinators. */
    public static function forget_roles(): void {
        \cache::make('local_researchcentral', 'roles')->purge();
    }

    private static function compute_roles(): array {
        $roles = [];
        if (self::is_student()) {
            $roles[] = 'student';
        }
        if (self::is_supervisor()) {
            $roles[] = 'supervisor';
        }
        if (self::is_coordinator()) {
            $roles[] = 'coordinator';
        }
        return $roles;
    }

    /** Supervisors see only their own supervisees; students only their own project; coordinators everything. */
    public static function can_view(\stdClass $proj, ?int $userid = null): bool {
        global $USER;
        $userid = $userid ?? (int)$USER->id;
        return (int)$proj->userid === $userid || self::is_project_supervisor($proj, $userid) || self::can_manage_project($proj, $userid);
    }

    public static function require_view(\stdClass $proj): void {
        if (!self::can_view($proj)) {
            throw new \moodle_exception('nopermissions', 'error', '', get_string('pluginname', 'local_researchcentral'));
        }
    }

    /** @return array school id => name; with $managedonly, only schools the user may manage */
    public static function schools(bool $managedonly = false): array {
        global $DB;
        $all = array_map(fn($r) => $r->name, $DB->get_records('local_researchcentral_school', null, 'name', 'id, name'));
        if ($managedonly && ($m = self::managed_schools()) !== null) {
            $all = array_filter($all, fn($id) => in_array((int)$id, $m, true), ARRAY_FILTER_USE_KEY);
        }
        return $all;
    }

    /** @return array school id => list of user records */
    public static function school_coordinators(): array {
        global $DB;
        $out = [];
        $sql = "SELECT c.id AS cid, c.schoolid, u.* FROM {local_researchcentral_scoord} c JOIN {user} u ON u.id = c.userid
                 WHERE u.deleted = 0 ORDER BY u.lastname, u.firstname";
        foreach ($DB->get_records_sql($sql) as $r) {
            $out[$r->schoolid][] = $r;
        }
        return $out;
    }

    public static function school_name(\stdClass $prog): string {
        global $DB;
        return $prog->schoolid ? (string)$DB->get_field('local_researchcentral_school', 'name', ['id' => $prog->schoolid]) : '';
    }

    public static function require_admin(): void {
        if (!is_siteadmin()) {
            throw new \moodle_exception('nopermissions', 'error', '', get_string('schools', 'local_researchcentral'));
        }
    }

    public static function require_coordinator(): void {
        if (!self::is_coordinator()) {
            throw new \moodle_exception('nopermissions', 'error', '', get_string('role_coordinator', 'local_researchcentral'));
        }
    }

    /**
     * Projects with progress counters. $scope: 'student' | 'supervisor' | 'coordinator'.
     * @return \stdClass[] keyed by project id
     */
    public static function projects(string $scope, array $filters = []): array {
        global $DB, $USER;
        $now = time();
        $where = ['1 = 1'];
        $params = [];
        if ($scope === 'student') {
            $where[] = 'p.userid = :me';
            $params['me'] = $USER->id;
        } else if ($scope === 'supervisor') {
            $where[] = '(p.supervisorid = :me OR EXISTS (SELECT 1 FROM {local_researchcentral_cosup} cs WHERE cs.projid = p.id AND cs.userid = :me2))';
            $params['me'] = $params['me2'] = $USER->id;
        } else {
            self::require_coordinator();
            $managed = self::managed_schools();
            if ($managed !== null) {
                [$sin, $sparams] = $DB->get_in_or_equal($managed ?: [-1], SQL_PARAMS_NAMED, 'ms');
                $where[] = "g.schoolid $sin";
                $params += $sparams;
            }
        }
        if (!empty($filters['schoolid'])) {
            $where[] = 'g.schoolid = :schoolid';
            $params['schoolid'] = (int)$filters['schoolid'];
        }
        if (!empty($filters['level'])) {
            $where[] = 'g.level = :level';
            $params['level'] = $filters['level'];
        }
        if (!empty($filters['progid'])) {
            $where[] = 'p.progid = :progid';
            $params['progid'] = (int)$filters['progid'];
        }
        if (!empty($filters['supervisorid'])) {
            $where[] = '(p.supervisorid = :supid OR EXISTS (SELECT 1 FROM {local_researchcentral_cosup} cf WHERE cf.projid = p.id AND cf.userid = :supid2))';
            $params['supid'] = $params['supid2'] = (int)$filters['supervisorid'];
        }
        $sql = "SELECT p.*, g.name AS progname, g.schoolid, sc.name AS school, g.level
                  FROM {local_researchcentral_proj} p
                  JOIN {local_researchcentral_prog} g ON g.id = p.progid
             LEFT JOIN {local_researchcentral_school} sc ON sc.id = g.schoolid
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY p.timecreated DESC, p.id DESC";
        $projects = $DB->get_records_sql($sql, $params);
        if (!$projects) {
            return [];
        }
        $users = [];
        $ids = [];
        $co = [];
        [$cin, $cparams] = $DB->get_in_or_equal(array_keys($projects), SQL_PARAMS_NAMED, 'cp');
        foreach ($DB->get_records_sql("SELECT id, projid, userid FROM {local_researchcentral_cosup} WHERE projid $cin ORDER BY id", $cparams) as $c) {
            $co[$c->projid][] = (int)$c->userid;
            $ids[$c->userid] = true;
        }
        foreach ($projects as $p) {
            $ids[$p->userid] = $ids[$p->supervisorid] = true;
        }
        // Only the columns the portal shows (name parts, email, picture) instead of whole user rows.
        $users = $DB->get_records_list('user', 'id', array_keys($ids), '',
            'id, email, picture, imagealt, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename');
        $ms = self::milestones(array_keys($projects));
        foreach ($projects as $p) {
            $p->student = $users[$p->userid] ?? null;
            $p->supervisor = $users[$p->supervisorid] ?? null;
            $p->cosupervisors = array_values(array_filter(array_map(fn($u) => $users[$u] ?? null, $co[$p->id] ?? [])));
            $p->ms = $ms[$p->id] ?? [];
            // Counters from the milestones already loaded (replaces five correlated sub-queries per project).
            $p->total = count($p->ms);
            $p->done = $p->review = $p->revision = $p->overdue = 0;
            foreach ($p->ms as $m) {
                $p->done += $m->status === self::APPROVED ? 1 : 0;
                $p->review += $m->status === self::SUBMITTED ? 1 : 0;
                $p->revision += $m->status === self::REVISION ? 1 : 0;
                $p->overdue += (in_array($m->status, [self::PENDING, self::REVISION], true) && $m->duedate > 0 && $m->duedate < $now) ? 1 : 0;
            }
            $p->state = self::state($p);
            $p->current = null;
            foreach ($p->ms as $m) {
                if ($m->status !== self::APPROVED) {
                    $p->current = $m;
                    break;
                }
            }
        }
        if (!empty($filters['state'])) {
            $projects = array_filter($projects, fn($p) => $p->state === $filters['state']);
        }
        if (!empty($filters['q'])) {
            $q = \core_text::strtolower($filters['q']);
            $projects = array_filter($projects, function ($p) use ($q) {
                $hay = \core_text::strtolower(($p->student ? fullname($p->student) : '') . ' ' . $p->title . ' ' . $p->progname);
                return strpos($hay, $q) !== false;
            });
        }
        return $projects;
    }

    /** @return array projid => list of milestone records (with stagename), in stage order */
    public static function milestones(array $projids): array {
        global $DB;
        if (!$projids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($projids, SQL_PARAMS_NAMED);
        $sql = "SELECT m.*, s.name AS stagename, s.sortorder, s.needsupload, s.maxgrade, s.weight
                  FROM {local_researchcentral_pms} m
                  JOIN {local_researchcentral_stage} s ON s.id = m.stageid
                 WHERE m.projid $in
              ORDER BY m.projid, s.sortorder, s.id";
        $out = [];
        foreach ($DB->get_records_sql($sql, $params) as $m) {
            $out[$m->projid][] = $m;
        }
        return $out;
    }

    public static function is_overdue(\stdClass $m): bool {
        return in_array($m->status, [self::PENDING, self::REVISION], true) && $m->duedate > 0 && $m->duedate < time();
    }

    /** Overall project state: completed | review | revision | overdue | active */
    public static function state(\stdClass $p): string {
        if ($p->total > 0 && (int)$p->done === (int)$p->total) {
            return 'completed';
        }
        if ($p->overdue > 0) {
            return 'overdue';
        }
        if ($p->review > 0) {
            return 'review';
        }
        if ($p->revision > 0) {
            return 'revision';
        }
        return 'active';
    }

    public static function create_project(int $userid, int $supervisorid, int $progid, string $title, int $startdate, array $cosupervisors = []): int {
        global $DB;
        self::require_manage_prog($DB->get_record('local_researchcentral_prog', ['id' => $progid], '*', MUST_EXIST));
        $stages = $DB->get_records('local_researchcentral_stage', ['progid' => $progid], 'sortorder, id');
        self::forget_roles();
        $tx = $DB->start_delegated_transaction();
        $projid = $DB->insert_record('local_researchcentral_proj', (object)[
            'userid' => $userid, 'supervisorid' => $supervisorid, 'progid' => $progid,
            'title' => $title, 'startdate' => $startdate, 'timecreated' => time(),
        ]);
        if (self::is_postgrad_prog($progid)) {
            foreach (array_slice(array_unique(array_map('intval', $cosupervisors)), 0, (int)self::cfg('cosup_max', 3)) as $cu) {
                if ($cu && $cu !== $supervisorid && $cu !== $userid) {
                    $DB->insert_record('local_researchcentral_cosup', (object)['projid' => $projid, 'userid' => $cu, 'timecreated' => time()]);
                }
            }
        }
        foreach ($stages as $s) {
            $DB->insert_record('local_researchcentral_pms', (object)[
                'projid' => $projid, 'stageid' => $s->id, 'duedate' => $startdate + (int)$s->defaultdays * DAYSECS,
                'status' => self::PENDING, 'timesubmitted' => 0, 'timedecided' => 0,
            ]);
        }
        $tx->allow_commit();
        self::forget_roles();
        self::log($projid, 'created', '');

        $proj = $DB->get_record('local_researchcentral_proj', ['id' => $projid]);
        $a = (object)['title' => format_string($title), 'student' => fullname(\core_user::get_user($userid)),
            'supervisor' => fullname(\core_user::get_user($supervisorid))];
        foreach (array_merge([$userid], self::supervisor_ids($proj)) as $uid) {
            self::notify($uid, get_string('msg_assigned_subject', 'local_researchcentral', $a),
                get_string('msg_assigned_body', 'local_researchcentral', $a), self::project_url($proj), 'workflow');
        }
        return $projid;
    }

    public static function project_url(\stdClass $proj): \moodle_url {
        return new \moodle_url('/local/researchcentral/project.php', ['id' => $proj->id]);
    }

    /** Save an upload as a new version; marks the stage as submitted and tells the supervisor. */
    public static function submit(\stdClass $proj, \stdClass $m, string $tmppath, string $filename, string $note, array $figitems = []): int {
        global $DB, $USER;
        $n = 1 + (int)$DB->get_field_sql('SELECT MAX(version) FROM {local_researchcentral_ver} WHERE pmsid = ?', [$m->id]);
        $verid = $DB->insert_record('local_researchcentral_ver', (object)[
            'pmsid' => $m->id, 'version' => $n, 'userid' => $USER->id, 'note' => $note, 'timecreated' => time(),
        ]);
        get_file_storage()->create_file_from_pathname(['contextid' => \context_system::instance()->id, 'component' => 'local_researchcentral',
            'filearea' => 'submission', 'itemid' => $verid, 'filepath' => '/', 'filename' => clean_param($filename, PARAM_FILE)], $tmppath);
        if ($figitems) {
            figures::save_all($proj, $m, $verid, $figitems);
        }
        $DB->update_record('local_researchcentral_pms', (object)[
            'id' => $m->id, 'status' => self::SUBMITTED, 'timesubmitted' => time(), 'timedecided' => 0, 'decisionnote' => null,
            'firstresponse' => 0,
        ]);
        $stage = $DB->get_record('local_researchcentral_stage', ['id' => $m->stageid]);
        self::log($proj->id, 'submitted', format_string($stage->name) . ' v' . $n);
        $a = (object)['stage' => format_string($stage->name), 'student' => fullname($USER),
            'version' => $n, 'title' => format_string($proj->title)];
        foreach (self::supervisor_ids($proj) as $sid) {
            self::notify($sid, get_string('msg_submitted_subject', 'local_researchcentral', $a),
                get_string('msg_submitted_body', 'local_researchcentral', $a), self::project_url($proj), 'workflow');
        }

        $task = new \local_researchcentral\task\check_version();
        $task->set_custom_data((object)['verid' => $verid]);
        $task->set_next_run_time(time() + 30);
        \core\task\manager::queue_adhoc_task($task);
        return $verid;
    }

    /** Direct decision (coordinator override or non-reviewed confirmation); recorded like any other decision. */
    public static function decide(\stdClass $proj, \stdClass $m, string $decision, string $note, ?float $grade = null): void {
        review::finalize($proj, $m, $decision, 'coordinator', $note, $grade);
    }

    /** Append to the project's audit trail. */
    public static function log(int $projid, string $action, string $detail = '', ?int $userid = null): void {
        global $DB, $USER;
        $DB->insert_record('local_researchcentral_act', (object)['projid' => $projid, 'userid' => $userid ?? (int)$USER->id,
            'action' => $action, 'detail' => \core_text::substr($detail, 0, 250), 'timecreated' => time()]);
    }

    /** Project discussion: stored in the project and sent to the other party. */
    public static function post_message(\stdClass $proj, string $body): void {
        global $DB, $USER;
        $DB->insert_record('local_researchcentral_msg', (object)['projid' => $proj->id, 'userid' => $USER->id,
            'body' => $body, 'timecreated' => time()]);
        $team = self::supervisor_ids($proj);
        $to = (int)$USER->id === (int)$proj->userid ? $team : array_merge([(int)$proj->userid], $team);
        $subject = get_string('msg_message_subject', 'local_researchcentral', fullname($USER));
        foreach (array_unique($to) as $uid) {
            if ((int)$uid !== (int)$USER->id) {
                self::notify((int)$uid, $subject, $body, new \moodle_url(self::project_url($proj), [], 'discussion'), 'workflow');
            }
        }
        self::log($proj->id, 'message', '');
    }

    /** Coordinator hands the project to another supervisor. */
    public static function reassign(\stdClass $proj, int $newsupervisor): void {
        global $DB;
        self::require_manage_project($proj);
        $old = (int)$proj->supervisorid;
        if ($old === $newsupervisor) {
            return;
        }
        $DB->set_field('local_researchcentral_proj', 'supervisorid', $newsupervisor, ['id' => $proj->id]);
        self::forget_roles();
        $DB->delete_records('local_researchcentral_cosup', ['projid' => $proj->id, 'userid' => $newsupervisor]);
        // Future appointments with the previous lead are cancelled (calendar entries removed).
        foreach ($DB->get_records_select('local_researchcentral_appt', "projid = ? AND supervisorid = ? AND status = 'booked' AND starttime > ?",
                [$proj->id, $old, time()]) as $ap) {
            $DB->set_field('local_researchcentral_appt', 'status', 'cancelled', ['id' => $ap->id]);
            cal::drop_events($ap);
        }
        $proj->supervisorid = $newsupervisor;
        self::log($proj->id, 'reassign', fullname(\core_user::get_user($old)) . ' → ' . fullname(\core_user::get_user($newsupervisor)));
        $a = (object)['title' => format_string($proj->title), 'student' => fullname(\core_user::get_user($proj->userid)),
            'supervisor' => fullname(\core_user::get_user($newsupervisor))];
        foreach (array_unique(array_merge([(int)$proj->userid, $old], self::supervisor_ids($proj))) as $uid) {
            self::notify($uid, get_string('msg_reassigned_subject', 'local_researchcentral', $a),
                get_string('msg_reassigned_body', 'local_researchcentral', $a), self::project_url($proj), 'workflow');
        }
        review::reevaluate($proj);
    }

    /** Add a co-supervisor (postgraduate only). */
    public static function add_cosupervisor(\stdClass $proj, int $userid): void {
        global $DB;
        self::require_manage_project($proj);
        if (!self::is_postgrad_prog((int)$proj->progid)) {
            throw new \moodle_exception('cosup_postgrad', 'local_researchcentral');
        }
        if ($userid === (int)$proj->userid || in_array($userid, self::supervisor_ids($proj), true)) {
            throw new \moodle_exception('err_samesupervisor', 'local_researchcentral');
        }
        if (count(self::supervisor_ids($proj)) - 1 >= (int)self::cfg('cosup_max', 3)) {
            throw new \moodle_exception('cosup_max', 'local_researchcentral');
        }
        $DB->insert_record('local_researchcentral_cosup', (object)['projid' => $proj->id, 'userid' => $userid, 'timecreated' => time()]);
        self::forget_roles();
        self::log($proj->id, 'cosup', '+ ' . fullname(\core_user::get_user($userid)));
        $a = (object)['title' => format_string($proj->title), 'student' => fullname(\core_user::get_user($proj->userid)),
            'supervisor' => fullname(\core_user::get_user($userid))];
        foreach (array_unique(array_merge([(int)$proj->userid], self::supervisor_ids($proj))) as $uid) {
            self::notify($uid, get_string('msg_cosup_subject', 'local_researchcentral', $a),
                get_string('msg_cosup_body', 'local_researchcentral', $a), self::project_url($proj), 'workflow');
        }
    }

    public static function remove_cosupervisor(\stdClass $proj, int $userid): void {
        global $DB;
        self::require_manage_project($proj);
        $DB->delete_records('local_researchcentral_cosup', ['projid' => $proj->id, 'userid' => $userid]);
        self::log($proj->id, 'cosup', '- ' . fullname(\core_user::get_user($userid)));
        review::reevaluate($proj);
    }

    /** Make a co-supervisor the lead; the previous lead becomes a co-supervisor. */
    public static function make_lead(\stdClass $proj, int $userid): void {
        global $DB;
        self::require_manage_project($proj);
        if (!in_array($userid, self::supervisor_ids($proj), true) || $userid === (int)$proj->supervisorid) {
            return;
        }
        $old = (int)$proj->supervisorid;
        $DB->delete_records('local_researchcentral_cosup', ['projid' => $proj->id, 'userid' => $userid]);
        $DB->insert_record('local_researchcentral_cosup', (object)['projid' => $proj->id, 'userid' => $old, 'timecreated' => time()]);
        $DB->set_field('local_researchcentral_proj', 'supervisorid', $userid, ['id' => $proj->id]);
        self::forget_roles();
        self::log($proj->id, 'cosup', get_string('lead_supervisor', 'local_researchcentral') . ': ' . fullname(\core_user::get_user($userid)));
    }

    /** @return int[] [thesis-wide count, this-stage count] of extension requests that are open or granted */
    public static function ext_counts(\stdClass $proj, int $pmsid): array {
        global $DB;
        $sql = "SELECT COUNT(1) FROM {local_researchcentral_ext} e JOIN {local_researchcentral_pms} m ON m.id = e.pmsid
                 WHERE m.projid = :p AND e.status IN ('pending','endorsed','approved')";
        $all = (int)$DB->count_records_sql($sql, ['p' => $proj->id]);
        $stage = (int)$DB->count_records_select('local_researchcentral_ext', "pmsid = ? AND status IN ('pending','endorsed','approved')", [$pmsid]);
        return [$all, $stage];
    }

    /** Null when the student may request an extension, otherwise a language string key explaining why not. */
    public static function ext_blocked(\stdClass $proj, \stdClass $m): ?string {
        global $DB;
        if ($DB->record_exists_select('local_researchcentral_ext', "pmsid = ? AND status IN ('pending','endorsed')", [$m->id])) {
            return 'ext_open';
        }
        [$all, $stage] = self::ext_counts($proj, (int)$m->id);
        $maxp = (int)self::cfg('maxext_project', 2);
        $maxs = (int)self::cfg('maxext_stage', 1);
        if ($maxp > 0 && $all >= $maxp) {
            return 'ext_limit_project';
        }
        if ($maxs > 0 && $stage >= $maxs) {
            return 'ext_limit_stage';
        }
        return null;
    }

    public static function request_extension(\stdClass $proj, \stdClass $m, int $newdate, string $reason): void {
        global $DB, $USER;
        if ($why = self::ext_blocked($proj, $m)) {
            throw new \moodle_exception($why, 'local_researchcentral');
        }
        $DB->insert_record('local_researchcentral_ext', (object)['pmsid' => $m->id, 'userid' => $USER->id, 'reason' => $reason,
            'requestdate' => $newdate, 'status' => 'pending', 'decidedby' => 0, 'timecreated' => time()]);
        $stage = $DB->get_field('local_researchcentral_stage', 'name', ['id' => $m->stageid]);
        $a = (object)['stage' => format_string($stage), 'student' => fullname($USER),
            'date' => userdate($newdate, get_string('strftimedatefullshort', 'langconfig')), 'reason' => $reason];
        self::log($proj->id, 'extension', $a->stage . ' → ' . $a->date);
        foreach (self::supervisor_ids($proj) as $sid) {
            self::notify($sid, get_string('msg_extreq_subject', 'local_researchcentral', $a),
                get_string('msg_extreq_body', 'local_researchcentral', $a), self::project_url($proj), 'workflow');
        }
    }

    /** User ids of coordinators who can confirm decisions for a project (school coordinators and global coordinators). */
    public static function coordinator_ids(\stdClass $proj): array {
        global $DB;
        $sid = (int)$DB->get_field('local_researchcentral_prog', 'schoolid', ['id' => $proj->progid]);
        $ids = $sid ? $DB->get_fieldset_select('local_researchcentral_scoord', 'userid', 'schoolid = ?', [$sid]) : [];
        foreach (get_users_by_capability(\context_system::instance(), 'local/researchcentral:coordinate', 'u.id') as $u) {
            $ids[] = $u->id;
        }
        foreach (get_admins() as $u) {
            $ids[] = $u->id;
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** First eye: a supervisor endorses or rejects. With second-eye review on, endorsement waits for a coordinator. */
    public static function ext_endorse(\stdClass $proj, \stdClass $ext, bool $endorse, string $note): void {
        global $DB, $USER;
        if ($ext->status !== 'pending' || !self::is_project_supervisor($proj)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'extension');
        }
        $second = (bool)self::cfg('ext_secondeye', 1);
        $tx = $DB->start_delegated_transaction();
        if ($DB->get_field('local_researchcentral_ext', 'status', ['id' => $ext->id]) !== 'pending') {
            $tx->allow_commit();   // Already decided by someone else.
            return;
        }
        $upd = (object)['id' => $ext->id, 'recommendedby' => $USER->id, 'recommendedtime' => time(), 'recommendnote' => $note];
        if (!$endorse) {
            $upd->status = 'declined';
            $upd->decidedby = $USER->id;
        } else if ($second) {
            $upd->status = 'endorsed';
        } else {
            $upd->status = 'approved';
            $upd->decidedby = $USER->id;
        }
        $DB->update_record('local_researchcentral_ext', $upd);
        $m = $DB->get_record('local_researchcentral_pms', ['id' => $ext->pmsid]);
        $stage = format_string($DB->get_field('local_researchcentral_stage', 'name', ['id' => $m->stageid]));
        self::log($proj->id, 'extension_decision', $stage . ': ' . get_string('ext_step_' . $upd->status, 'local_researchcentral'));
        if ($upd->status === 'approved') {
            $DB->set_field('local_researchcentral_pms', 'duedate', $ext->requestdate, ['id' => $ext->pmsid]);
        }
        $tx->allow_commit();
        if ($upd->status === 'endorsed') {
            $a = (object)['stage' => $stage, 'student' => fullname(\core_user::get_user($proj->userid)), 'by' => fullname($USER),
                'date' => userdate($ext->requestdate, get_string('strftimedatefullshort', 'langconfig')), 'note' => $note];
            foreach (self::coordinator_ids($proj) as $cid) {
                if ($cid !== (int)$USER->id && self::can_manage_project($proj, $cid)) {
                    self::notify($cid, get_string('msg_extconfirm_subject', 'local_researchcentral', $a),
                        get_string('msg_extconfirm_body', 'local_researchcentral', $a), self::project_url($proj), 'workflow');
                }
            }
        }
        self::ext_tell_student($proj, $ext, $upd->status, $stage);
    }

    /** Second eye: a coordinator other than the endorsing supervisor confirms or rejects. */
    public static function ext_confirm(\stdClass $proj, \stdClass $ext, bool $approve, string $note): void {
        global $DB, $USER;
        if ($ext->status !== 'endorsed' || !self::can_manage_project($proj)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'extension');
        }
        if ((int)$ext->recommendedby === (int)$USER->id || (int)$ext->userid === (int)$USER->id) {
            throw new \moodle_exception('ext_secondeye_same', 'local_researchcentral');
        }
        $status = $approve ? 'approved' : 'declined';
        $tx = $DB->start_delegated_transaction();
        if ($DB->get_field('local_researchcentral_ext', 'status', ['id' => $ext->id]) !== 'endorsed') {
            $tx->allow_commit();
            return;
        }
        $DB->update_record('local_researchcentral_ext', (object)['id' => $ext->id, 'status' => $status, 'decidedby' => $USER->id,
            'confirmedby' => $USER->id, 'confirmedtime' => time(), 'confirmnote' => $note]);
        if ($approve) {
            $DB->set_field('local_researchcentral_pms', 'duedate', $ext->requestdate, ['id' => $ext->pmsid]);
        }
        $tx->allow_commit();
        $m = $DB->get_record('local_researchcentral_pms', ['id' => $ext->pmsid]);
        $stage = format_string($DB->get_field('local_researchcentral_stage', 'name', ['id' => $m->stageid]));
        self::log($proj->id, 'extension_decision', $stage . ': ' . get_string('ext_step_' . $status, 'local_researchcentral'));
        self::ext_tell_student($proj, $ext, $status, $stage);
        if ((int)$ext->recommendedby && (int)$ext->recommendedby !== (int)$USER->id) {
            self::notify((int)$ext->recommendedby, get_string('msg_extdec_subject', 'local_researchcentral',
                (object)['stage' => $stage, 'result' => get_string('ext_' . $status, 'local_researchcentral')]),
                $note, self::project_url($proj), 'workflow');
        }
    }

    private static function ext_tell_student(\stdClass $proj, \stdClass $ext, string $status, string $stage): void {
        if (!in_array($status, ['approved', 'declined'], true)) {
            return;
        }
        $a = (object)['stage' => $stage, 'result' => get_string('ext_' . $status, 'local_researchcentral'),
            'date' => userdate($ext->requestdate, get_string('strftimedatefullshort', 'langconfig'))];
        self::notify((int)$ext->userid, get_string('msg_extdec_subject', 'local_researchcentral', $a),
            get_string('msg_extdec_body', 'local_researchcentral', $a), self::project_url($proj), 'workflow');
    }

    /** Extension requests waiting for the second eye, within the current user's coordinator scope. */
    public static function extensions_to_confirm(): array {
        global $DB, $USER;
        $managed = self::managed_schools();
        $where = "e.status = 'endorsed' AND e.recommendedby <> :me AND e.userid <> :me2";
        $params = ['me' => $USER->id, 'me2' => $USER->id];
        if ($managed !== null) {
            [$in, $p] = $DB->get_in_or_equal($managed ?: [-1], SQL_PARAMS_NAMED, 'ms');
            $where .= " AND g.schoolid $in";
            $params += $p;
        }
        $sql = "SELECT e.id, e.requestdate, e.reason, e.recommendnote, e.recommendedby, p.id AS projid, p.userid, p.title, s.name AS stagename, g.name AS progname
                  FROM {local_researchcentral_ext} e
                  JOIN {local_researchcentral_pms} m ON m.id = e.pmsid
                  JOIN {local_researchcentral_stage} s ON s.id = m.stageid
                  JOIN {local_researchcentral_proj} p ON p.id = m.projid
                  JOIN {local_researchcentral_prog} g ON g.id = p.progid
                 WHERE $where ORDER BY e.recommendedtime";
        return $DB->get_records_sql($sql, $params);
    }

    /** Weighted overall mark (percent) from marked stages; null when nothing is marked yet. */
    public static function overall(array $ms): ?array {
        $sum = 0.0;
        $wsum = 0.0;
        $marked = 0;
        $graded = 0;
        foreach ($ms as $m) {
            if ((int)$m->maxgrade <= 0) {
                continue;
            }
            $graded++;
            if ($m->grade === null) {
                continue;
            }
            $w = (int)$m->weight > 0 ? (int)$m->weight : 1;
            $sum += $w * ((float)$m->grade / (int)$m->maxgrade * 100);
            $wsum += $w;
            $marked++;
        }
        return $wsum > 0 ? ['percent' => $sum / $wsum, 'marked' => $marked, 'graded' => $graded] : null;
    }

    public static function notify(int $userid, string $subject, string $body, \moodle_url $url, string $provider): void {
        $to = \core_user::get_user($userid);
        if (!$to || $to->deleted) {
            return;
        }
        $m = new \core\message\message();
        $m->component = 'local_researchcentral';
        $m->name = $provider;
        $m->userfrom = \core_user::get_noreply_user();
        $m->userto = $to;
        $m->subject = $subject;
        $m->fullmessage = $body;
        $m->fullmessageformat = FORMAT_PLAIN;
        $m->fullmessagehtml = '';
        $m->smallmessage = $subject;
        $m->notification = 1;
        $m->contexturl = $url->out(false);
        $m->contexturlname = get_string('pluginname', 'local_researchcentral');
        message_send($m);
    }

    /** Stage template for a programme level, from the template definitions. */
    public static function seed_stages(int $progid, string $level): void {
        global $DB;
        $i = 0;
        foreach (template::definition($level) as [$key, $name, $days, , $kind]) {
            $DB->insert_record('local_researchcentral_stage', (object)[
                'courseid' => 0, 'progid' => $progid, 'name' => $name, 'cmid' => 0, 'level' => 'all',
                'sortorder' => ++$i, 'defaultdays' => $days, 'needsupload' => $kind === 'assign' ? 1 : 0, 'maxgrade' => $kind === 'assign' ? 100 : 0, 'weight' => 0,
                'timecreated' => time(),
            ]);
        }
    }
}
