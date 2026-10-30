<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Collaborative Q&A forums. Forums are derived from the data: nobody is added by hand.
 *
 * Types: site (everyone), staff (supervisors + coordinators), level, school, prog, sup (one supervisor + their students).
 */
class forums {
    const TYPES = ['sup', 'prog', 'school', 'level', 'site', 'staff'];

    /** @var array request cache userid => forum records */
    private static $cache = [];
    private static $rolecache = [];

    /** Forum record for (type, refid), created on first use. */
    public static function ensure(string $type, int $refid, string $name, ?\stdClass $known = null): \stdClass {
        global $DB;
        $f = $known ?: $DB->get_record('local_researchcentral_forum', ['type' => $type, 'refid' => $refid]);
        if (!$f) {
            $id = $DB->insert_record('local_researchcentral_forum', (object)['type' => $type, 'refid' => $refid,
                'name' => \core_text::substr($name, 0, 255), 'timecreated' => time()]);
            $f = $DB->get_record('local_researchcentral_forum', ['id' => $id]);
        } else if ($f->name !== \core_text::substr($name, 0, 255)) {
            $f->name = \core_text::substr($name, 0, 255);
            $DB->set_field('local_researchcentral_forum', 'name', $f->name, ['id' => $f->id]);
        }
        return $f;
    }

    /**
     * All forums the user may see, keyed by forum id, each with ->label (type label) and ->unread.
     * Access is computed from the data on every call, so it follows allocations and handovers automatically.
     */
    public static function visible(?int $userid = null): array {
        global $DB, $USER;
        $userid = $userid ?? (int)$USER->id;
        if (isset(self::$cache[$userid])) {
            return self::$cache[$userid];
        }
        $want = []; // key "type:refid" => name.
        $managed = portal::managed_schools($userid);
        $iscoord = $managed === null || !empty($managed);
        // One grouped query (distinct programme / supervisor combinations) instead of loading every project.
        $conds = ['p.userid = :u1', 'p.supervisorid = :u2',
            'EXISTS (SELECT 1 FROM {local_researchcentral_cosup} cs WHERE cs.projid = p.id AND cs.userid = :u3)'];
        $params = ['u1' => $userid, 'u2' => $userid, 'u3' => $userid, 'u4' => $userid, 'u5' => $userid, 'u6' => $userid];
        if ($managed === null) {
            $conds[] = '1 = 1';
        } else if ($managed) {
            [$min, $mp] = $DB->get_in_or_equal($managed, SQL_PARAMS_NAMED, 'mgd');
            $conds[] = "g.schoolid $min";
            $params += $mp;
        }
        $sql = "SELECT MIN(p.id) AS id, p.progid, p.supervisorid, g.name AS progname, g.schoolid, g.level, sc.name AS schoolname,
                       MAX(CASE WHEN p.userid = :u4 THEN 1 ELSE 0 END) AS isstu,
                       MAX(CASE WHEN p.supervisorid = :u5 OR EXISTS (SELECT 1 FROM {local_researchcentral_cosup} c2 WHERE c2.projid = p.id AND c2.userid = :u6) THEN 1 ELSE 0 END) AS issup
                  FROM {local_researchcentral_proj} p
                  JOIN {local_researchcentral_prog} g ON g.id = p.progid
             LEFT JOIN {local_researchcentral_school} sc ON sc.id = g.schoolid
                 WHERE " . implode(' OR ', $conds) . "
              GROUP BY p.progid, p.supervisorid, g.name, g.schoolid, g.level, sc.name";
        $rows = [];
        $issup = false;
        foreach ($DB->get_records_sql($sql, $params) as $r) {
            $rows[] = $r;
            $issup = $issup || (bool)$r->issup;
        }
        if (!$rows && !$iscoord) {
            return self::$cache[$userid] = [];
        }
        $names = [];
        foreach ($rows as $r) {
            $want['prog:' . $r->progid] = get_string('forum_prog', 'local_researchcentral', format_string($r->progname, true, ["context" => \context_system::instance()]));
            if ($r->schoolid) {
                $want['school:' . $r->schoolid] = get_string('forum_school', 'local_researchcentral', format_string($r->schoolname, true, ["context" => \context_system::instance()]));
            }
            $lvl = $r->level ?: 'masters';
            $want['level:' . self::levelid($lvl)] = get_string('forum_level', 'local_researchcentral', get_string('level_' . $lvl, 'local_researchcentral'));
            $names[$r->supervisorid] = true;
            $want['sup:' . $r->supervisorid] = '';
        }
        $users = $names ? $DB->get_records_list('user', 'id', array_keys($names), '', 'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename') : [];
        foreach ($want as $k => $v) {
            if (strpos($k, 'sup:') === 0) {
                $sid = (int)substr($k, 4);
                $want[$k] = get_string('forum_sup', 'local_researchcentral', isset($users[$sid]) ? fullname($users[$sid]) : $sid);
            }
        }
        $want['site:0'] = get_string('forum_site', 'local_researchcentral');
        if ($issup || $iscoord) {
            $want['staff:0'] = get_string('forum_staff', 'local_researchcentral');
        }
        $out = [];
        $known = [];
        foreach ($DB->get_records('local_researchcentral_forum') as $kf) {
            $known[$kf->type . ':' . $kf->refid] = $kf;
        }
        foreach ($want as $k => $name) {
            [$type, $ref] = explode(':', $k, 2);
            $f = self::ensure($type, (int)$ref, $name, $known[$k] ?? null);
            $f->label = get_string('forumtype_' . $type, 'local_researchcentral');
            $out[$f->id] = $f;
        }
        // Unread counts: threads with activity after the user's last visit (own posts excluded).
        if ($out) {
            [$in, $p] = $DB->get_in_or_equal(array_keys($out), SQL_PARAMS_NAMED);
            $sqlu = "SELECT t.forumid, COUNT(1) AS n
                       FROM {local_researchcentral_thread} t
                  LEFT JOIN {local_researchcentral_fread} r ON r.forumid = t.forumid AND r.userid = :uid
                      WHERE t.forumid $in AND t.lastpost > COALESCE(r.timeread, 0) AND t.userid <> :uid2
                   GROUP BY t.forumid";
            $unread = $DB->get_records_sql_menu($sqlu, $p + ['uid' => $userid, 'uid2' => $userid]);
            $cnt = $DB->get_records_sql_menu("SELECT forumid, COUNT(1) FROM {local_researchcentral_thread} WHERE forumid $in GROUP BY forumid", $p);
            foreach ($out as $f) {
                $f->unread = (int)($unread[$f->id] ?? 0);
                $f->threads = (int)($cnt[$f->id] ?? 0);
            }
        }
        uasort($out, function ($a, $b) {
            return [array_search($a->type, self::TYPES), $a->name] <=> [array_search($b->type, self::TYPES), $b->name];
        });
        return self::$cache[$userid] = $out;
    }

    /** Stable numeric ref for a level key. */
    public static function levelid(string $level): int {
        return ['ug' => 1, 'masters' => 2, 'phd' => 3, 'diploma' => 4][$level] ?? 2;
    }

    public static function level_from_id(int $id): string {
        return [1 => 'ug', 2 => 'masters', 3 => 'phd', 4 => 'diploma'][$id] ?? 'masters';
    }

    public static function can_access(\stdClass $forum, ?int $userid = null): bool {
        global $USER;
        return isset(self::visible($userid ?? (int)$USER->id)[$forum->id]);
    }

    public static function require_access(\stdClass $forum): void {
        if (!self::can_access($forum)) {
            throw new \moodle_exception('nopermissions', 'error', '', get_string('forums', 'local_researchcentral'));
        }
    }

    /** Moderators: global coordinators everywhere; school coordinators in their school/programme; a supervisor in their own group. */
    public static function can_moderate(\stdClass $forum, ?int $userid = null): bool {
        global $DB, $USER;
        $userid = $userid ?? (int)$USER->id;
        if (!self::can_access($forum, $userid)) {
            return false;
        }
        if (portal::is_global_coordinator($userid)) {
            return true;
        }
        if ($forum->type === 'sup') {
            return (int)$forum->refid === $userid;
        }
        if ($forum->type === 'school') {
            return portal::can_manage_school((int)$forum->refid, $userid);
        }
        if ($forum->type === 'prog') {
            $sid = (int)$DB->get_field('local_researchcentral_prog', 'schoolid', ['id' => $forum->refid]);
            return portal::can_manage_school($sid, $userid);
        }
        return false;
    }

    /** 'coordinator' | 'supervisor' | 'student' - highest role label for display next to a name. */
    public static function role_of(int $userid): string {
        if (!isset(self::$rolecache[$userid])) {
            self::$rolecache[$userid] = portal::is_coordinator($userid) ? 'coordinator'
                : (portal::is_supervisor($userid) ? 'supervisor' : 'student');
        }
        return self::$rolecache[$userid];
    }

    /** Member user ids of a forum (for notifications). */
    public static function members(\stdClass $forum): array {
        global $DB;
        $ids = [];
        switch ($forum->type) {
            case 'sup':
                $ids = $DB->get_fieldset_select('local_researchcentral_proj', 'userid', 'supervisorid = ?', [$forum->refid]);
                $ids[] = (int)$forum->refid;
                $ids = array_merge($ids, $DB->get_fieldset_sql("SELECT c.userid FROM {local_researchcentral_cosup} c
                                                                  JOIN {local_researchcentral_proj} p ON p.id = c.projid WHERE p.supervisorid = ?", [$forum->refid]));
                break;
            case 'prog':
                $ids = $DB->get_fieldset_sql("SELECT userid FROM {local_researchcentral_proj} WHERE progid = ?
                                              UNION SELECT supervisorid FROM {local_researchcentral_proj} WHERE progid = ?
                                              UNION SELECT c.userid FROM {local_researchcentral_cosup} c JOIN {local_researchcentral_proj} p ON p.id = c.projid
                                                     WHERE p.progid = ?", [$forum->refid, $forum->refid, $forum->refid]);
                break;
            case 'school':
                $ids = $DB->get_fieldset_sql("SELECT p.userid FROM {local_researchcentral_proj} p JOIN {local_researchcentral_prog} g ON g.id = p.progid
                                               WHERE g.schoolid = ? UNION SELECT p.supervisorid FROM {local_researchcentral_proj} p
                                               JOIN {local_researchcentral_prog} g ON g.id = p.progid WHERE g.schoolid = ?", [$forum->refid, $forum->refid]);
                break;
            case 'level':
                $lv = self::level_from_id((int)$forum->refid);
                $ids = $DB->get_fieldset_sql("SELECT p.userid FROM {local_researchcentral_proj} p JOIN {local_researchcentral_prog} g ON g.id = p.progid
                                               WHERE g.level = ? UNION SELECT p.supervisorid FROM {local_researchcentral_proj} p
                                               JOIN {local_researchcentral_prog} g ON g.id = p.progid WHERE g.level = ?", [$lv, $lv]);
                break;
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** Staff members (supervisors/coordinators) of a forum: the people likely to answer. */
    public static function staff_members(\stdClass $forum): array {
        global $DB;
        $ids = [];
        switch ($forum->type) {
            case 'sup':
                return self::members($forum);
            case 'prog':
                $ids = $DB->get_fieldset_select('local_researchcentral_proj', 'DISTINCT supervisorid', 'progid = ?', [$forum->refid]);
                $sid = (int)$DB->get_field('local_researchcentral_prog', 'schoolid', ['id' => $forum->refid]);
                break;
            case 'school':
                $sid = (int)$forum->refid;
                $ids = $DB->get_fieldset_sql("SELECT DISTINCT p.supervisorid FROM {local_researchcentral_proj} p JOIN {local_researchcentral_prog} g
                                               ON g.id = p.progid WHERE g.schoolid = ?", [$sid]);
                break;
            default:
                $sid = 0;
                $ids = $DB->get_fieldset_sql("SELECT DISTINCT supervisorid FROM {local_researchcentral_proj}");
        }
        if (!empty($sid)) {
            $ids = array_merge($ids, $DB->get_fieldset_select('local_researchcentral_scoord', 'userid', 'schoolid = ?', [$sid]));
        }
        return array_slice(array_values(array_unique(array_map('intval', $ids))), 0, 300);
    }

    public static function mark_read(\stdClass $forum): void {
        global $DB, $USER;
        $r = $DB->get_record('local_researchcentral_fread', ['forumid' => $forum->id, 'userid' => $USER->id]);
        if ($r) {
            $DB->set_field('local_researchcentral_fread', 'timeread', time(), ['id' => $r->id]);
        } else {
            $DB->insert_record('local_researchcentral_fread', (object)['forumid' => $forum->id, 'userid' => $USER->id, 'timeread' => time()]);
        }
    }

    public static function total_unread(?int $userid = null): int {
        return array_sum(array_map(fn($f) => $f->unread, self::visible($userid)));
    }

    public static function add_thread(\stdClass $forum, string $subject, string $message): int {
        global $DB, $USER;
        $now = time();
        $id = $DB->insert_record('local_researchcentral_thread', (object)['forumid' => $forum->id, 'userid' => $USER->id,
            'subject' => \core_text::substr($subject, 0, 255), 'message' => $message, 'pinned' => 0, 'locked' => 0,
            'answerpostid' => 0, 'lastpost' => $now, 'timecreated' => $now]);
        $url = new \moodle_url('/local/researchcentral/thread.php', ['id' => $id]);
        $to = ($forum->type === 'sup') ? self::members($forum) : self::staff_members($forum);
        foreach (array_unique($to) as $uid) {
            if ($uid !== (int)$USER->id) {
                portal::notify($uid, get_string('forum_newthread_subject', 'local_researchcentral', (object)['forum' => $forum->name, 'subject' => $subject]),
                    fullname($USER) . ":\n\n" . $message, $url, 'forum');
            }
        }
        return $id;
    }

    public static function add_post(\stdClass $thread, \stdClass $forum, string $message): int {
        global $DB, $USER;
        $now = time();
        $id = $DB->insert_record('local_researchcentral_post', (object)['threadid' => $thread->id, 'userid' => $USER->id,
            'message' => $message, 'timecreated' => $now]);
        $DB->set_field('local_researchcentral_thread', 'lastpost', $now, ['id' => $thread->id]);
        // Notify the asker and everyone who already replied.
        $subs = $DB->get_fieldset_select('local_researchcentral_post', 'DISTINCT userid', 'threadid = ?', [$thread->id]);
        $subs[] = $thread->userid;
        $url = new \moodle_url('/local/researchcentral/thread.php', ['id' => $thread->id], 'p' . $id);
        foreach (array_unique(array_map('intval', $subs)) as $uid) {
            if ($uid !== (int)$USER->id) {
                portal::notify($uid, get_string('forum_reply_subject', 'local_researchcentral', $thread->subject),
                    fullname($USER) . ":\n\n" . $message, $url, 'forum');
            }
        }
        return $id;
    }

    /** Render user-written text safely (plain text with line breaks and auto-links). */
    public static function format(string $text): string {
        return format_text($text, FORMAT_PLAIN);
    }
}
