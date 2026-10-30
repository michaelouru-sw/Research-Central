<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Journal-style review workflow. Every supervisor of a project (lead and co-supervisors) reviews each submission round.
 * The stage is decided only when all of them agree; every review and decision is kept for quality assurance.
 */
class review {
    const REC_APPROVE = 'approve';
    const REC_MINOR = 'minor';
    const REC_MAJOR = 'major';

    public static function recommendations(): array {
        return [
            self::REC_APPROVE => get_string('rec_approve', 'local_researchcentral'),
            self::REC_MINOR => get_string('rec_minor', 'local_researchcentral'),
            self::REC_MAJOR => get_string('rec_major', 'local_researchcentral'),
        ];
    }

    /** Review round currently open for a stage: one more than the number of decisions made so far. */
    public static function round(int $pmsid): int {
        global $DB;
        return 1 + $DB->count_records('local_researchcentral_decision', ['pmsid' => $pmsid]);
    }

    public static function latest_version(int $pmsid): ?\stdClass {
        global $DB;
        $r = $DB->get_records('local_researchcentral_ver', ['pmsid' => $pmsid], 'version DESC', '*', 0, 1);
        return $r ? reset($r) : null;
    }

    /** Active reviews of the open round, keyed by reviewer id. */
    public static function active(int $pmsid, ?int $round = null): array {
        global $DB;
        $round = $round ?? self::round($pmsid);
        $out = [];
        foreach ($DB->get_records('local_researchcentral_review', ['pmsid' => $pmsid, 'round' => $round, 'status' => 'active'], 'timecreated, id') as $r) {
            $out[(int)$r->userid] = $r;
        }
        return $out;
    }

    /** May this user record a review on this stage right now? */
    public static function can_review(\stdClass $proj, \stdClass $m, ?int $userid = null): bool {
        global $USER;
        $userid = $userid ?? (int)$USER->id;
        return portal::is_project_supervisor($proj, $userid) && self::open_for_review($m);
    }

    /** A stage accepts reviews while a submission awaits review, or (for stages without an upload) until approved. */
    public static function open_for_review(\stdClass $m): bool {
        return $m->status === portal::SUBMITTED || (!$m->needsupload && $m->status !== portal::APPROVED);
    }

    /**
     * Record (or replace) this reviewer's review for the open round, then check for agreement.
     * Replaced reviews are kept with status "superseded".
     * @return string waiting | conflict | approve | revision
     */
    public static function submit_review(\stdClass $proj, \stdClass $m, string $rec, ?float $grade, string $comment,
            string $confidential, int $draftitemid): string {
        global $DB, $USER;
        if (!self::can_review($proj, $m)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'review');
        }
        $round = self::round((int)$m->id);
        $ver = self::latest_version((int)$m->id);
        $DB->set_field_select('local_researchcentral_review', 'status', 'superseded',
            "pmsid = ? AND round = ? AND userid = ? AND status = 'active'", [$m->id, $round, $USER->id]);
        $rid = $DB->insert_record('local_researchcentral_review', (object)[
            'projid' => $proj->id, 'pmsid' => $m->id, 'verid' => $ver ? $ver->id : 0, 'round' => $round, 'userid' => $USER->id,
            'recommendation' => $rec, 'grade' => $rec === self::REC_APPROVE ? $grade : null, 'comment' => $comment,
            'confidential' => $confidential, 'status' => 'active', 'timecreated' => time()]);
        $maxmb = (int)portal::cfg('maxfilemb', 30);
        if ($draftitemid > 0) {
            file_save_draft_area_files($draftitemid, \context_system::instance()->id, 'local_researchcentral', 'feedback', $rid,
                ['subdirs' => 0, 'maxfiles' => 10, 'maxbytes' => $maxmb * 1048576]);
        }
        annotate::link_queued((int)$rid, (int)$m->id, (int)$USER->id, $comment);
        if (!(int)$m->firstresponse) {
            $DB->set_field('local_researchcentral_pms', 'firstresponse', time(), ['id' => $m->id]);
            $m->firstresponse = time();
        }
        $stage = format_string($DB->get_field('local_researchcentral_stage', 'name', ['id' => $m->stageid]));
        portal::log($proj->id, 'review', $stage . ': ' . get_string('rec_' . $rec, 'local_researchcentral'));
        // Tell the other supervisors where things stand.
        $a = (object)['stage' => $stage, 'who' => fullname($USER), 'rec' => get_string('rec_' . $rec, 'local_researchcentral'),
            'title' => format_string($proj->title)];
        foreach (portal::supervisor_ids($proj) as $sid) {
            if ($sid !== (int)$USER->id) {
                portal::notify($sid, get_string('msg_reviewed_subject', 'local_researchcentral', $a),
                    get_string('msg_reviewed_body', 'local_researchcentral', $a), portal::project_url($proj), 'workflow');
            }
        }
        return self::evaluate($proj, $m);
    }

    /** Check whether the supervisors agree; decide the stage when they do. */
    public static function evaluate(\stdClass $proj, \stdClass $m): string {
        global $DB;
        $m = $DB->get_record_sql("SELECT m.*, s.name AS stagename, s.needsupload, s.maxgrade, s.weight FROM {local_researchcentral_pms} m
                                    JOIN {local_researchcentral_stage} s ON s.id = m.stageid WHERE m.id = ?", [$m->id]);
        if (!$m || !self::open_for_review($m)) {
            return 'waiting';
        }
        $reviews = self::active((int)$m->id);
        $team = portal::supervisor_ids($proj);
        foreach ($team as $sid) {
            if (empty($reviews[$sid])) {
                return 'waiting';
            }
        }
        $recs = array_unique(array_map(fn($sid) => $reviews[$sid]->recommendation, $team));
        if ($recs === [self::REC_APPROVE]) {
            self::finalize($proj, $m, 'approve', 'unanimous');
            return 'approve';
        }
        if (!in_array(self::REC_APPROVE, $recs, true)) {
            self::finalize($proj, $m, 'revision', 'unanimous');
            return 'revision';
        }
        // Mixed: some approve, some want revisions. Ask the lead and the coordinators to bring the team together.
        $a = (object)['stage' => format_string($m->stagename), 'title' => format_string($proj->title)];
        $already = $DB->record_exists_select('local_researchcentral_act', "projid = ? AND action = 'conflict' AND timecreated > ?",
            [$proj->id, (int)$m->timesubmitted]);
        if (!$already) {
            portal::log($proj->id, 'conflict', $a->stage, (int)$team[0]);
            foreach (array_unique(array_merge($team, array_filter(portal::coordinator_ids($proj), fn($c) => portal::can_manage_project($proj, $c)))) as $uid) {
                portal::notify((int)$uid, get_string('msg_conflict_subject', 'local_researchcentral', $a),
                    get_string('msg_conflict_body', 'local_researchcentral', $a), portal::project_url($proj), 'workflow');
            }
        }
        return 'conflict';
    }

    /** After the team changes, decide any submission that is now fully reviewed. */
    public static function reevaluate(\stdClass $proj): void {
        global $DB;
        foreach ($DB->get_records('local_researchcentral_pms', ['projid' => $proj->id, 'status' => portal::SUBMITTED]) as $m) {
            if (self::active((int)$m->id)) {
                self::evaluate($proj, $m);
            }
        }
    }

    /**
     * Record the decision for the open round: stage status, mark, decision letter, notifications.
     * $mode: unanimous | coordinator.
     */
    public static function finalize(\stdClass $proj, \stdClass $m, string $decision, string $mode, string $note = '', ?float $grade = null): void {
        global $DB, $USER;
        $round = self::round((int)$m->id);
        $reviews = self::active((int)$m->id, $round);
        $stage = $DB->get_record('local_researchcentral_stage', ['id' => $m->stageid]);
        $pms = $DB->get_record('local_researchcentral_pms', ['id' => $m->id]);
        $ver = self::latest_version((int)$m->id);
        if ($decision === 'approve' && (int)$stage->maxgrade > 0 && $grade === null) {
            $marks = array_values(array_filter(array_map(fn($r) => $r->grade, $reviews), fn($g) => $g !== null));
            $grade = $marks ? round(array_sum($marks) / count($marks), 2) : null;
        }
        if ($decision !== 'approve') {
            $grade = null;
        }
        $letter = self::letter($proj, $stage, $decision, $reviews, $note, $mode, $grade);
        $status = $decision === 'approve' ? portal::APPROVED : portal::REVISION;
        $now = time();
        $tx = $DB->start_delegated_transaction();
        if ($DB->record_exists('local_researchcentral_decision', ['pmsid' => $m->id, 'round' => $round])) {
            $tx->allow_commit();   // Another request already decided this round.
            return;
        }
        $upd = (object)['id' => $m->id, 'status' => $status, 'timedecided' => $now, 'decisionnote' => $letter, 'firstresponse' => 0];
        if ($grade !== null) {
            $upd->grade = $grade;
        }
        $DB->update_record('local_researchcentral_pms', $upd);
        $DB->insert_record('local_researchcentral_decision', (object)[
            'projid' => $proj->id, 'pmsid' => $m->id, 'verid' => $ver ? $ver->id : 0, 'round' => $round, 'decision' => $decision,
            'grade' => $grade, 'letter' => $letter, 'decidedby' => $mode === 'coordinator' ? (int)$USER->id : 0, 'mode' => $mode,
            'timesubmitted' => (int)$pms->timesubmitted, 'firstresponse' => (int)$pms->firstresponse ?: $now, 'timecreated' => $now]);
        $tx->allow_commit();
        $a = (object)['stage' => format_string($stage->name), 'title' => format_string($proj->title),
            'decision' => get_string('decision_' . $decision, 'local_researchcentral'), 'note' => $letter,
            'mark' => $grade === null ? '-' : format_float($grade, 2, true, true) . ' / ' . (int)$stage->maxgrade];
        portal::log($proj->id, 'decision', $a->stage . ': ' . $a->decision . ($grade === null ? '' : ' (' . $a->mark . ')'));
        foreach (array_unique(array_merge([(int)$proj->userid], portal::supervisor_ids($proj))) as $uid) {
            portal::notify((int)$uid, get_string('msg_decision_subject', 'local_researchcentral', $a),
                get_string('msg_decision_body', 'local_researchcentral', $a), portal::project_url($proj), 'workflow');
        }
    }

    /** Decision letter in journal style: outcome first, then each supervisor's comments to the author. */
    public static function letter(\stdClass $proj, \stdClass $stage, string $decision, array $reviews, string $note, string $mode, ?float $grade): string {
        global $DB;
        $student = \core_user::get_user($proj->userid);
        $lines = [get_string('letter_dear', 'local_researchcentral', $student ? $student->firstname : ''), ''];
        $lines[] = get_string('letter_' . $decision, 'local_researchcentral', format_string($stage->name));
        if ($grade !== null) {
            $lines[] = get_string('letter_mark', 'local_researchcentral', format_float($grade, 2, true, true) . ' / ' . (int)$stage->maxgrade);
        }
        if ($mode === 'coordinator' && trim($note) !== '') {
            $lines[] = '';
            $lines[] = get_string('letter_coordinator', 'local_researchcentral');
            $lines[] = trim($note);
        }
        $lead = (int)$proj->supervisorid;
        $n = 0;
        foreach ($reviews as $uid => $r) {
            $n++;
            $u = \core_user::get_user($uid);
            $lines[] = '';
            $lines[] = get_string('letter_reviewer', 'local_researchcentral', (object)['n' => $n, 'name' => $u ? fullname($u) : '-',
                'role' => get_string($uid === $lead ? 'lead_supervisor' : 'co_supervisor', 'local_researchcentral'),
                'rec' => get_string('rec_' . $r->recommendation, 'local_researchcentral')]);
            if (trim((string)$r->comment) !== '') {
                $lines[] = trim($r->comment);
            }
        }
        return implode("\n", $lines);
    }

    /** Reviewer panel for a stage: [userid => ['role' => lead|co, 'review' => record|null]] in team order. */
    public static function panel(\stdClass $proj, int $pmsid): array {
        $reviews = self::active($pmsid);
        $out = [];
        foreach (portal::supervisor_ids($proj) as $i => $sid) {
            $out[$sid] = ['role' => $i === 0 ? 'lead' : 'co', 'review' => $reviews[$sid] ?? null];
        }
        return $out;
    }

    /** Journal-style label for a stage's status, including who we are waiting for. */
    public static function status_label(\stdClass $m, int $reviewed, int $team): string {
        if ($m->status === portal::SUBMITTED) {
            return get_string('js_underreview', 'local_researchcentral', (object)['a' => $reviewed, 'b' => $team]);
        }
        return get_string('js_' . $m->status, 'local_researchcentral');
    }

    /** Response-time figures (days) from decision records. */
    public static function response_stats(array $projids): array {
        global $DB;
        if (!$projids) {
            return [];
        }
        [$in, $p] = $DB->get_in_or_equal($projids, SQL_PARAMS_NAMED);
        $out = [];
        foreach ($DB->get_records_select('local_researchcentral_decision', "projid $in AND timesubmitted > 0", $p) as $d) {
            $out[] = ['proj' => (int)$d->projid, 'first' => max(0, ($d->firstresponse - $d->timesubmitted)) / DAYSECS,
                'decide' => max(0, ($d->timecreated - $d->timesubmitted)) / DAYSECS];
        }
        return $out;
    }
}
