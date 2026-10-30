<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Targeted communication: coordinators write to students and supervisors in their schools;
 * the site administrator can also write to coordinators and to everyone with a thesis role.
 */
class comms {
    /** Recipients are sent immediately up to this many; larger sends are queued for cron. */
    const SYNC_LIMIT = 40;

    /** @return string[] audience key => label that the current user may address */
    public static function audiences(): array {
        $a = ['students' => get_string('aud_students', 'local_researchcentral'), 'supervisors' => get_string('aud_supervisors', 'local_researchcentral')];
        if (is_siteadmin()) {
            $a['coordinators'] = get_string('aud_coordinators', 'local_researchcentral');
            $a['everyone'] = get_string('aud_everyone', 'local_researchcentral');
        }
        return $a;
    }

    public static function require_allowed(): void {
        portal::require_coordinator();
    }

    /**
     * Resolve recipients for an audience and filters. Always evaluated server-side within the sender's scope.
     * Filters: schoolid, level, progid, supervisorid, state, stalled (days without activity), q (name/email).
     * @return \stdClass[] keyed by user id, each user record plus ->info (merge-field values, ->ctx short description)
     */
    public static function resolve(string $audience, array $f): array {
        global $DB;
        if (!isset(self::audiences()[$audience])) {
            throw new \moodle_exception('nopermissions', 'error', '', 'audience');
        }
        $out = [];
        $stalled = (int)($f['stalled'] ?? 0);
        $pf = array_intersect_key($f, array_flip(['schoolid', 'level', 'progid', 'supervisorid', 'state']));
        if (in_array($audience, ['students', 'supervisors', 'everyone'], true)) {
            $projects = portal::projects('coordinator', $pf);
            $last = [];
            if ($projects && $stalled > 0) {
                [$in, $p] = $DB->get_in_or_equal(array_keys($projects), SQL_PARAMS_NAMED);
                $last = $DB->get_records_sql_menu("SELECT projid, MAX(timecreated) FROM {local_researchcentral_act} WHERE projid $in GROUP BY projid", $p);
            }
            $cut = time() - $stalled * DAYSECS;
            foreach ($projects as $p) {
                if ($stalled > 0 && max((int)($last[$p->id] ?? 0), (int)$p->timecreated) > $cut) {
                    continue;
                }
                if (in_array($audience, ['students', 'everyone'], true) && $p->student) {
                    $u = $out[$p->userid] ?? clone $p->student;
                    $u->info = $u->info ?? self::blank($u);
                    $u->info['programme'] = format_string($p->progname);
                    $u->info['school'] = (string)$p->school;
                    $u->info['supervisor'] = $p->supervisor ? fullname($p->supervisor) : '';
                    $u->info['stage'] = $p->current ? format_string($p->current->stagename) : '';
                    $u->info['duedate'] = ($p->current && $p->current->duedate) ? userdate($p->current->duedate, get_string('strftimedatefullshort', 'langconfig')) : '';
                    $u->ctx = ($u->ctx ?? '') ?: format_string($p->progname) . ' · ' . get_string('state_' . $p->state, 'local_researchcentral');
                    $out[$p->userid] = $u;
                }
                if (in_array($audience, ['supervisors', 'everyone'], true)) {
                    foreach (array_merge($p->supervisor ? [$p->supervisor] : [], $p->cosupervisors) as $sup) {
                        $u = $out[$sup->id] ?? clone $sup;
                        $u->info = $u->info ?? self::blank($u);
                        $u->nstu = ($u->nstu ?? 0) + 1;
                        $u->nover = ($u->nover ?? 0) + ($p->state === 'overdue' ? 1 : 0);
                        $u->nrev = ($u->nrev ?? 0) + ($p->state === 'review' ? 1 : 0);
                        $u->info['students'] = (string)$u->nstu;
                        $u->info['overdue'] = (string)$u->nover;
                        $u->info['awaiting'] = (string)$u->nrev;
                        $u->ctx = get_string('aud_supctx', 'local_researchcentral', (object)['n' => $u->nstu, 'o' => $u->nover, 'r' => $u->nrev]);
                        $out[$sup->id] = $u;
                    }
                }
            }
        }
        if (in_array($audience, ['coordinators', 'everyone'], true)) {
            $sql = "SELECT c.id AS cid, c.schoolid, s.name AS schoolname, u.*
                      FROM {local_researchcentral_scoord} c JOIN {user} u ON u.id = c.userid
                 LEFT JOIN {local_researchcentral_school} s ON s.id = c.schoolid
                     WHERE u.deleted = 0 AND u.suspended = 0" . (!empty($f['schoolid']) ? ' AND c.schoolid = :sid' : '');
            foreach ($DB->get_records_sql($sql, !empty($f['schoolid']) ? ['sid' => (int)$f['schoolid']] : []) as $r) {
                $u = $out[$r->id] ?? $r;
                $u->info = $u->info ?? self::blank($u);
                $u->info['school'] = $u->info['school'] ?: (string)$r->schoolname;
                $u->ctx = trim(($u->ctx ?? '') . ' ' . get_string('role_coordinator', 'local_researchcentral') . ': ' . $r->schoolname);
                $out[$r->id] = $u;
            }
        }
        // Remove suspended or deleted accounts and apply the name/email search.
        $q = \core_text::strtolower(trim((string)($f['q'] ?? '')));
        foreach ($out as $id => $u) {
            if (!empty($u->deleted) || !empty($u->suspended)) {
                unset($out[$id]);
            } else if ($q !== '' && strpos(\core_text::strtolower(fullname($u) . ' ' . $u->email), $q) === false) {
                unset($out[$id]);
            }
        }
        uasort($out, fn($a, $b) => strcasecmp($a->lastname . $a->firstname, $b->lastname . $b->firstname));
        return $out;
    }

    private static function blank(\stdClass $u): array {
        return ['firstname' => $u->firstname, 'lastname' => $u->lastname, 'fullname' => fullname($u), 'programme' => '', 'school' => '',
            'supervisor' => '', 'stage' => '', 'duedate' => '', 'students' => '', 'overdue' => '', 'awaiting' => ''];
    }

    /** Replace {placeholders} using the recipient's merge values. */
    public static function merge(string $text, array $info): string {
        $map = [];
        foreach ($info as $k => $v) {
            $map['{' . $k . '}'] = (string)$v;
        }
        return strtr($text, $map);
    }

    public static function merge_fields(): array {
        return ['{firstname}', '{lastname}', '{fullname}', '{programme}', '{school}', '{supervisor}', '{stage}', '{duedate}', '{students}', '{overdue}', '{awaiting}'];
    }

    /** Human-readable description of what was selected, for the history log. */
    public static function describe(string $audience, array $f, int $n): string {
        global $DB;
        $bits = [self::audiences()[$audience] ?? $audience];
        if (!empty($f['schoolid'])) {
            $bits[] = (string)$DB->get_field('local_researchcentral_school', 'name', ['id' => (int)$f['schoolid']]);
        }
        if (!empty($f['level'])) {
            $bits[] = get_string('level_' . $f['level'], 'local_researchcentral');
        }
        if (!empty($f['progid'])) {
            $bits[] = (string)$DB->get_field('local_researchcentral_prog', 'name', ['id' => (int)$f['progid']]);
        }
        if (!empty($f['supervisorid']) && ($u = $DB->get_record('user', ['id' => (int)$f['supervisorid']]))) {
            $bits[] = get_string('supervisor', 'local_researchcentral') . ': ' . fullname($u);
        }
        if (!empty($f['state'])) {
            $bits[] = get_string('state_' . $f['state'], 'local_researchcentral');
        }
        if (!empty($f['stalled'])) {
            $bits[] = get_string('stalled_n', 'local_researchcentral', (int)$f['stalled']);
        }
        $bits[] = get_string('aud_count', 'local_researchcentral', $n);
        return \core_text::substr(implode(' · ', $bits), 0, 255);
    }

    /**
     * Record the broadcast and send it (now if small, otherwise queued).
     * @param \stdClass[] $recipients users keyed by id (already scope-checked)
     * @return \stdClass the broadcast record
     */
    public static function send(string $subject, string $body, string $channel, string $audiencedesc, array $recipients, bool $copyme): \stdClass {
        global $DB, $USER;
        $bid = $DB->insert_record('local_researchcentral_bcast', (object)['userid' => $USER->id, 'subject' => \core_text::substr($subject, 0, 255),
            'body' => $body, 'audience' => $audiencedesc, 'channel' => $channel, 'total' => count($recipients), 'sent' => 0,
            'status' => 'queued', 'timecreated' => time()]);
        foreach ($recipients as $u) {
            $DB->insert_record('local_researchcentral_bcastu', (object)['bcastid' => $bid, 'userid' => $u->id, 'status' => 'pending', 'timesent' => 0]);
        }
        $b = $DB->get_record('local_researchcentral_bcast', ['id' => $bid]);
        if ($copyme) {
            self::deliver($b, $USER, self::merge_for_sender($USER));
        }
        if (count($recipients) <= self::SYNC_LIMIT) {
            self::process($bid);
        } else {
            $task = new \local_researchcentral\task\send_broadcast();
            $task->set_custom_data(['bcastid' => $bid]);
            \core\task\manager::queue_adhoc_task($task);
        }
        return $DB->get_record('local_researchcentral_bcast', ['id' => $bid]);
    }

    private static function merge_for_sender(\stdClass $u): array {
        return self::blank($u);
    }

    /** Send every pending recipient of a broadcast. */
    public static function process(int $bid, int $limit = 0): void {
        global $DB;
        $b = $DB->get_record('local_researchcentral_bcast', ['id' => $bid]);
        if (!$b) {
            return;
        }
        $sender = \core_user::get_user($b->userid) ?: \core_user::get_noreply_user();
        $rows = $DB->get_records('local_researchcentral_bcastu', ['bcastid' => $bid, 'status' => 'pending'], 'id', '*', 0, $limit);
        foreach ($rows as $r) {
            $to = \core_user::get_user($r->userid);
            $ok = false;
            if ($to && !$to->deleted && !$to->suspended) {
                $info = self::recipient_info($to, (int)$b->userid);
                $ok = self::deliver($b, $to, $info, $sender);
            }
            $DB->update_record('local_researchcentral_bcastu', (object)['id' => $r->id, 'status' => $ok ? 'sent' : 'failed', 'timesent' => time()]);
        }
        $sent = $DB->count_records('local_researchcentral_bcastu', ['bcastid' => $bid, 'status' => 'sent']);
        $pending = $DB->count_records('local_researchcentral_bcastu', ['bcastid' => $bid, 'status' => 'pending']);
        $DB->update_record('local_researchcentral_bcast', (object)['id' => $bid, 'sent' => $sent, 'status' => $pending ? 'sending' : 'done']);
    }

    /** Merge values for a recipient, taken from their most relevant project. */
    private static function recipient_info(\stdClass $to, int $senderid): array {
        global $DB;
        $info = self::blank($to);
        $sql = "SELECT p.*, g.name AS progname, sc.name AS school
                  FROM {local_researchcentral_proj} p JOIN {local_researchcentral_prog} g ON g.id = p.progid
             LEFT JOIN {local_researchcentral_school} sc ON sc.id = g.schoolid
                 WHERE p.userid = :u OR p.supervisorid = :s
                    OR EXISTS (SELECT 1 FROM {local_researchcentral_cosup} c WHERE c.projid = p.id AND c.userid = :c) ORDER BY p.id";
        $rows = $DB->get_records_sql($sql, ['u' => $to->id, 's' => $to->id, 'c' => $to->id]);
        $first = reset($rows) ?: null;
        $now = time();
        if ($first) {
            $info['programme'] = format_string($first->progname);
            $info['school'] = (string)$first->school;
            if ((int)$first->userid === (int)$to->id) {
                if ($sup = \core_user::get_user($first->supervisorid)) {
                    $info['supervisor'] = fullname($sup);
                }
                $ms = portal::milestones([$first->id])[$first->id] ?? [];
                foreach ($ms as $m) {
                    if ($m->status !== portal::APPROVED) {
                        $info['stage'] = format_string($m->stagename);
                        $info['duedate'] = $m->duedate ? userdate($m->duedate, get_string('strftimedatefullshort', 'langconfig')) : '';
                        break;
                    }
                }
            }
        }
        $supprojs = array_filter($rows, fn($p) => (int)$p->userid !== (int)$to->id);
        if ($supprojs) {
            [$in, $p] = $DB->get_in_or_equal(array_keys($supprojs), SQL_PARAMS_NAMED);
            $p['now'] = $now;
            $info['students'] = (string)count($supprojs);
            $info['overdue'] = (string)$DB->count_records_sql("SELECT COUNT(DISTINCT projid) FROM {local_researchcentral_pms}
                WHERE projid $in AND status IN ('pending','revision') AND duedate > 0 AND duedate < :now", $p);
            $info['awaiting'] = (string)$DB->count_records_sql("SELECT COUNT(DISTINCT projid) FROM {local_researchcentral_pms}
                WHERE projid $in AND status = 'submitted'", $p);
        }
        return $info;
    }

    /** Deliver one message through the chosen channel(s). */
    private static function deliver(\stdClass $b, \stdClass $to, array $info, ?\stdClass $sender = null): bool {
        global $DB;
        $sender = $sender ?? (\core_user::get_user($b->userid) ?: \core_user::get_noreply_user());
        $subject = self::merge($b->subject, $info);
        $text = self::merge($b->body, $info);
        $html = nl2br(s($text));
        $ok = true;
        if ($b->channel === 'email') {
            $ok = email_to_user($to, \core_user::get_noreply_user(), $subject, $text, $html, '', '', true, $sender->email, fullname($sender));
        }
        if ($b->channel === 'notify') {
            portal::notify((int)$to->id, $subject, $text, new \moodle_url('/local/researchcentral/portal.php'), 'broadcast');
        }
        return (bool)$ok;
    }
}
