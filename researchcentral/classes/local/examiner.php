<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/**
 * External examiners. They are not site users: each invitation is a scoped, expiring, revocable link that opens ONE stage of ONE
 * thesis. They can read and comment on its submissions and send a report. Their comments are stored with userid = -examid and are
 * staff-only until a supervisor shares them with the student.
 */
class examiner {
    /** Whether the last invitation e-mail was accepted for delivery. */
    public static bool $mailed = false;

    public static function url(\stdClass $ex, array $extra = []): \moodle_url {
        return new \moodle_url('/local/researchcentral/exam.php', ['t' => $ex->token] + $extra);
    }

    public static function for_project(int $projid): array {
        global $DB;
        return $DB->get_records('local_researchcentral_exam', ['projid' => $projid], 'timeinvited DESC, id DESC');
    }

    /** The invitation behind a link, or null when it is unknown, revoked or expired. */
    public static function by_token(string $token): ?\stdClass {
        global $DB;
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $ex = $DB->get_record('local_researchcentral_exam', ['token' => $token]);
        if (!$ex || $ex->status === 'revoked' || ((int)$ex->expiry && (int)$ex->expiry < time())) {
            return null;
        }
        return $ex;
    }

    public static function touch(\stdClass $ex): void {
        global $DB;
        $upd = (object)['id' => $ex->id, 'lastseen' => time(), 'hits' => (int)$ex->hits + 1];
        if (!(int)$ex->timefirstseen) {
            $upd->timefirstseen = time();
            if ($ex->status === 'invited') {
                $upd->status = 'viewed';
            }
        }
        $DB->update_record('local_researchcentral_exam', $upd);
    }

    public static function invite(\stdClass $proj, int $pmsid, string $name, string $email, string $inst, int $days): int {
        global $DB, $USER;
        if (!annotate::is_staff($proj)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'examiner');
        }
        $DB->get_record('local_researchcentral_pms', ['id' => $pmsid, 'projid' => $proj->id], 'id', MUST_EXIST);
        $name = \core_text::substr(trim($name), 0, 150);
        $email = trim($email);
        if ($name === '' || !validate_email($email)) {
            return 0;
        }
        $days = max(1, min(180, $days));
        $now = time();
        $id = $DB->insert_record('local_researchcentral_exam', (object)['projid' => $proj->id, 'pmsid' => $pmsid, 'name' => $name, 'email' => $email,
            'institution' => \core_text::substr(trim($inst), 0, 150), 'token' => bin2hex(random_bytes(24)), 'expiry' => $now + $days * DAYSECS, 'status' => 'invited',
            'recommendation' => '', 'report' => '', 'confidential' => '', 'invitedby' => $USER->id, 'timeinvited' => $now, 'timefirstseen' => 0, 'lastseen' => 0,
            'timesubmitted' => 0, 'hits' => 0]);
        portal::log((int)$proj->id, 'examiner', $name);
        self::$mailed = self::send_invite($proj, $DB->get_record('local_researchcentral_exam', ['id' => $id]));
        return $id;
    }

    /** E-mail the link. Returns false when the mail could not be sent (the link is still shown to staff). */
    public static function send_invite(\stdClass $proj, \stdClass $ex): bool {
        global $DB, $USER;
        $stage = $DB->get_field_sql('SELECT s.name FROM {local_researchcentral_pms} m JOIN {local_researchcentral_stage} s ON s.id = m.stageid WHERE m.id = ?', [$ex->pmsid]);
        $a = (object)['name' => $ex->name, 'who' => fullname($USER), 'stage' => format_string((string)$stage), 'title' => format_string($proj->title),
            'url' => self::url($ex)->out(false), 'date' => userdate($ex->expiry, get_string('strftimedate', 'langconfig')), 'site' => format_string(get_site()->fullname)];
        $to = new \stdClass();
        $to->id = -99;
        $to->email = $ex->email;
        $to->firstname = $ex->name;
        $to->lastname = '';
        $to->firstnamephonetic = $to->lastnamephonetic = $to->middlename = $to->alternatename = '';
        $to->mailformat = 1;
        $to->maildisplay = 1;
        $to->emailstop = 0;
        $to->deleted = 0;
        $to->suspended = 0;
        $to->auth = 'manual';
        $to->username = 'external-examiner';
        $to->lang = current_language();
        $to->timezone = '99';
        return (bool)email_to_user($to, \core_user::get_noreply_user(), get_string('exam_mail_subject', 'local_researchcentral', $a),
            get_string('exam_mail_body', 'local_researchcentral', $a));
    }

    public static function revoke(\stdClass $proj, int $id): void {
        global $DB;
        if (annotate::is_staff($proj)) {
            $DB->set_field('local_researchcentral_exam', 'status', 'revoked', ['id' => $id, 'projid' => $proj->id]);
        }
    }

    public static function extend(\stdClass $proj, int $id, int $days): void {
        global $DB;
        if (annotate::is_staff($proj)) {
            $DB->set_field('local_researchcentral_exam', 'expiry', time() + max(1, min(180, $days)) * DAYSECS, ['id' => $id, 'projid' => $proj->id]);
        }
    }

    /** Display name for the author of a comment (negative ids are external examiners). */
    public static function author_name(int $uid): string {
        global $DB;
        static $memo = [];
        if (isset($memo[$uid])) {
            return $memo[$uid];
        }
        if ($uid < 0) {
            $n = $DB->get_field('local_researchcentral_exam', 'name', ['id' => -$uid]);
            return $memo[$uid] = ($n === false ? '-' : $n) . ' (' . get_string('viva_role_external', 'local_researchcentral') . ')';
        }
        $x = \core_user::get_user($uid);
        return $memo[$uid] = $x ? fullname($x) : '-';
    }

    public static function versions(\stdClass $ex): array {
        global $DB;
        return $DB->get_records('local_researchcentral_ver', ['pmsid' => $ex->pmsid], 'version');
    }

    public static function comments(\stdClass $ex, int $verid): array {
        global $DB;
        return $DB->get_records('local_researchcentral_ann', ['verid' => $verid, 'userid' => -(int)$ex->id, 'parentid' => 0], 'paraidx, id');
    }

    public static function add_comment(\stdClass $ex, \stdClass $ver, int $idx, string $hash, string $quote, string $body): int {
        global $DB;
        if ($ex->status === 'submitted' || (int)$ver->pmsid !== (int)$ex->pmsid || trim($body) === '') {
            return 0;
        }
        $now = time();
        $id = $DB->insert_record('local_researchcentral_ann', (object)['projid' => $ex->projid, 'pmsid' => $ex->pmsid, 'verid' => $ver->id, 'parentid' => 0,
            'paraidx' => $idx, 'parahash' => $hash, 'quote' => \core_text::substr(trim($quote), 0, 250), 'userid' => -(int)$ex->id, 'body' => trim($body),
            'private' => 1, 'status' => annotate::OPEN, 'timecreated' => $now, 'timemodified' => $now, 'queued' => 0, 'reviewid' => 0, 'itemno' => 0, 'examid' => $ex->id]);
        return $id;
    }

    public static function delete_comment(\stdClass $ex, int $cid): void {
        global $DB;
        if ($ex->status !== 'submitted') {
            $DB->delete_records('local_researchcentral_ann', ['id' => $cid, 'userid' => -(int)$ex->id]);
        }
    }

    /** Send the report: locks the invitation, lists the examiner on the viva panel and tells the team. */
    public static function submit_report(\stdClass $ex, string $rec, string $report, string $confidential): bool {
        global $DB;
        if ($ex->status === 'submitted' || !in_array($rec, research::VIVA_OUTCOMES, true) || trim($report) === '') {
            return false;
        }
        $now = time();
        $DB->update_record('local_researchcentral_exam', (object)['id' => $ex->id, 'status' => 'submitted', 'recommendation' => $rec, 'report' => trim($report),
            'confidential' => trim($confidential), 'timesubmitted' => $now]);
        $proj = $DB->get_record('local_researchcentral_proj', ['id' => $ex->projid]);
        $viva = $DB->get_records('local_researchcentral_viva', ['projid' => $ex->projid], 'id DESC', '*', 0, 1);
        if ($viva) {
            $viva = reset($viva);
            $DB->insert_record('local_researchcentral_vivapanel', (object)['vivaid' => $viva->id, 'userid' => 0, 'name' => $ex->name, 'role' => 'external',
                'recommendation' => $rec, 'report' => trim($report), 'timecreated' => $now]);
        }
        portal::log((int)$ex->projid, 'examiner', $ex->name . ': ' . get_string('exam_report_sent', 'local_researchcentral'));
        $a = (object)['name' => $ex->name, 'title' => format_string($proj->title)];
        foreach (array_unique(array_merge(portal::supervisor_ids($proj), array_filter(portal::coordinator_ids($proj), fn($c) => portal::can_manage_project($proj, $c)))) as $u) {
            portal::notify((int)$u, get_string('msg_exam_subject', 'local_researchcentral', $a), get_string('msg_exam_body', 'local_researchcentral', $a),
                new \moodle_url('/local/researchcentral/examiners.php', ['id' => $ex->projid]), 'workflow');
        }
        return true;
    }

    /** Staff: share an examiner's comment with the student (or take it back). */
    public static function set_shared(\stdClass $proj, \stdClass $c, bool $share): bool {
        global $DB;
        if ((int)$c->userid >= 0 || $c->parentid || !annotate::is_staff($proj)) {
            return false;
        }
        $DB->set_field('local_researchcentral_ann', 'private', $share ? 0 : 1, ['id' => $c->id]);
        return true;
    }
}
