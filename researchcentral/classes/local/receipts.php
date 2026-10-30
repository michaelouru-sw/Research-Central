<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read and download receipts. kind: msg (discussion message seen), submission (student file downloaded),
 * feedback (supervisor feedback file downloaded by the student or a colleague).
 */
class receipts {
    /** Record one person's view/download of one item. Returns true when it is the first time. */
    public static function mark(string $kind, int $itemid, int $projid, int $userid): bool {
        global $DB;
        $now = time();
        $r = $DB->get_record('local_researchcentral_receipt', ['kind' => $kind, 'itemid' => $itemid, 'userid' => $userid]);
        if ($r) {
            $DB->update_record('local_researchcentral_receipt', (object)['id' => $r->id, 'lasttime' => $now, 'hits' => (int)$r->hits + 1]);
            return false;
        }
        try {
            $DB->insert_record('local_researchcentral_receipt', (object)['projid' => $projid, 'userid' => $userid, 'kind' => $kind,
                'itemid' => $itemid, 'firsttime' => $now, 'lasttime' => $now, 'hits' => 1]);
        } catch (\dml_exception $e) {
            return false; // Concurrent insert of the same receipt: harmless.
        }
        return true;
    }

    /** Mark many messages as seen by a user (skips the user's own and already-seen ones). */
    public static function mark_messages(array $msgs, int $projid, int $userid): void {
        global $DB;
        $ids = [];
        foreach ($msgs as $m) {
            if ((int)$m->userid !== $userid) {
                $ids[] = (int)$m->id;
            }
        }
        if (!$ids) {
            return;
        }
        [$in, $p] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $seen = $DB->get_fieldset_select('local_researchcentral_receipt', 'itemid', "kind = 'msg' AND userid = :u AND itemid $in", ['u' => $userid] + $p);
        foreach (array_diff($ids, $seen) as $id) {
            self::mark('msg', $id, $projid, $userid);
        }
    }

    /** Receipts for items: [itemid => [userid => record]]. */
    public static function for_items(string $kind, array $itemids): array {
        global $DB;
        $out = [];
        if (!$itemids) {
            return $out;
        }
        [$in, $p] = $DB->get_in_or_equal(array_map('intval', $itemids), SQL_PARAMS_NAMED);
        foreach ($DB->get_records_select('local_researchcentral_receipt', "kind = :k AND itemid $in", ['k' => $kind] + $p) as $r) {
            $out[(int)$r->itemid][(int)$r->userid] = $r;
        }
        return $out;
    }

    /** Unread discussion messages per project for a user: [projid => count]. */
    public static function unread(int $userid, array $projids): array {
        global $DB;
        if (!$projids) {
            return [];
        }
        $since = (int)get_config('local_researchcentral', 'receipts_since');
        [$in, $p] = $DB->get_in_or_equal(array_map('intval', $projids), SQL_PARAMS_NAMED);
        $sql = "SELECT m.projid, COUNT(m.id) AS n
                  FROM {local_researchcentral_msg} m
             LEFT JOIN {local_researchcentral_receipt} r ON r.kind = 'msg' AND r.itemid = m.id AND r.userid = :u
                 WHERE m.projid $in AND m.userid <> :u2 AND m.timecreated >= :since AND r.id IS NULL
              GROUP BY m.projid";
        $out = [];
        foreach ($DB->get_records_sql($sql, ['u' => $userid, 'u2' => $userid, 'since' => $since] + $p) as $r) {
            $out[(int)$r->projid] = (int)$r->n;
        }
        return $out;
    }

    /** Intended readers of a message: the student and the supervision team, minus the author. */
    public static function recipients(\stdClass $proj, int $authorid): array {
        $all = array_merge([(int)$proj->userid], portal::supervisor_ids($proj));
        return array_values(array_unique(array_filter($all, fn($u) => (int)$u !== $authorid)));
    }

    /**
     * Message status line with ticks. $seen = [userid => receipt]. State: sent | partial | all.
     * @param callable $name userid => display name
     */
    public static function message_status(\stdClass $proj, \stdClass $msg, array $seen, callable $name, bool $own): string {
        $dt = fn(int $t) => userdate($t, get_string('strftimedatetimeshort', 'langconfig'));
        $rcpt = self::recipients($proj, (int)$msg->userid);
        $got = array_values(array_filter($rcpt, fn($u) => isset($seen[$u])));
        $state = !$got ? 'sent' : (count($got) < count($rcpt) ? 'partial' : 'all');
        $parts = [];
        foreach ($rcpt as $u) {
            $parts[] = isset($seen[$u])
                ? get_string('rcpt_seen_by', 'local_researchcentral', (object)['name' => $name($u), 'when' => $dt((int)$seen[$u]->firsttime)])
                : get_string('rcpt_not_yet', 'local_researchcentral', $name($u));
        }
        $title = s(implode("\n", $parts));
        if (!$own) {
            return '';
        }
        $ticks = \html_writer::span($state === 'sent' ? '✓' : '✓✓', 'tt-tick tt-tick-' . $state, ['title' => $title, 'aria-label' => $title, 'role' => 'img']);
        $label = get_string('rcpt_' . $state, 'local_researchcentral', (object)['a' => count($got), 'b' => count($rcpt)]);
        $detail = '';
        if ($got) {
            $last = max(array_map(fn($u) => (int)$seen[$u]->firsttime, $got));
            $detail = ' · ' . ($state === 'all' ? get_string('rcpt_all_at', 'local_researchcentral', $dt($last))
                : implode(', ', array_map(fn($u) => s($name($u)), $got)));
        }
        return \html_writer::span($ticks . ' ' . s($label) . $detail, 'tt-rcpt-line');
    }

    /** Download flags for a submission version: who on the team has downloaded it. */
    public static function download_flags(\stdClass $proj, array $team, array $rec, callable $name): string {
        $dt = fn(int $t) => userdate($t, get_string('strftimedatetimeshort', 'langconfig'));
        $chips = [];
        foreach ($team as $i => $sid) {
            $role = get_string($i === 0 ? 'lead_supervisor' : 'co_supervisor', 'local_researchcentral');
            if (isset($rec[$sid])) {
                $r = $rec[$sid];
                $chips[] = \html_writer::span('⬇ ' . s($name($sid)) . ' · ' . $dt((int)$r->firsttime) . ((int)$r->hits > 1 ? ' ×' . (int)$r->hits : ''),
                    'tt-flag tt-flag-yes', ['title' => s($role) . ' · ' . get_string('rcpt_last', 'local_researchcentral', $dt((int)$r->lasttime))]);
            } else {
                $chips[] = \html_writer::span('○ ' . s($name($sid)) . ' · ' . get_string('rcpt_not_downloaded', 'local_researchcentral'),
                    'tt-flag tt-flag-no', ['title' => s($role)]);
            }
        }
        return \html_writer::div(implode(' ', $chips), 'tt-flags');
    }

    /** Opened flag for a feedback file by the student. */
    public static function opened_flag(\stdClass $proj, array $rec, callable $name): string {
        $dt = fn(int $t) => userdate($t, get_string('strftimedatetimeshort', 'langconfig'));
        $uid = (int)$proj->userid;
        if (isset($rec[$uid])) {
            return \html_writer::span('✓ ' . get_string('rcpt_student_opened', 'local_researchcentral', $dt((int)$rec[$uid]->firsttime)), 'tt-flag tt-flag-yes');
        }
        return \html_writer::span('○ ' . get_string('rcpt_student_notopened', 'local_researchcentral'), 'tt-flag tt-flag-no');
    }
}
