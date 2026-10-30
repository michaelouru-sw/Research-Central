<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Analysis-ready datasets (tidy CSV): one row per student, per student-stage, or per audit event.
 * Always scoped to the downloader's schools; identifiers can be pseudonymised for research use.
 */
class dataset {
    const KINDS = ['students', 'stages', 'reviews', 'meetings', 'events'];

    /** @var bool */
    private $anon;
    /** @var \stdClass[] projects in scope */
    private $projects;

    public function __construct(array $filters, bool $anonymise) {
        $this->anon = $anonymise;
        $this->projects = portal::projects('coordinator', $filters);
    }

    public function count(): int {
        return count($this->projects);
    }

    private function pid(int $userid): string {
        global $CFG;
        return $this->anon ? 'U' . substr(sha1(($CFG->siteidentifier ?? '') . '|' . $userid), 0, 10) : (string)$userid;
    }

    private function name(?\stdClass $u): string {
        return $this->anon ? '' : ($u ? fullname($u) : '');
    }

    private static function d(?int $t): string {
        return $t ? gmdate('Y-m-d', $t) : '';
    }

    private static function dt(?int $t): string {
        return $t ? gmdate('Y-m-d H:i:s', $t) : '';
    }

    /** @return array{0: string[], 1: string[][]} header and rows */
    public function build(string $kind): array {
        return match ($kind) {
            'stages' => $this->stages(),
            'reviews' => $this->reviews(),
            'meetings' => $this->meetings(),
            'events' => $this->events(),
            default => $this->students(),
        };
    }

    private function students(): array {
        global $DB;
        $now = time();
        $head = ['snapshot_utc', 'project_id', 'student_id', 'student_username', 'student_idnumber', 'student_name', 'student_email',
            'school', 'programme', 'level', 'thesis_title', 'supervisor_id', 'supervisor_name', 'start_date', 'project_state',
            'stages_total', 'stages_approved', 'stages_submitted', 'stages_revision', 'stages_overdue', 'progress_pct',
            'current_stage', 'current_stage_due', 'days_to_next_due', 'overall_mark_pct', 'stages_marked',
            'submissions_total', 'resubmitted_stages', 'late_submissions', 'avg_review_turnaround_days',
            'extensions_requested', 'extensions_approved', 'appointments_total', 'appointments_completed',
            'messages_by_student', 'messages_by_supervisor', 'forum_threads', 'forum_replies', 'last_activity_utc', 'days_since_activity', 'days_since_start',
            'supervisors_total', 'cosupervisors', 'review_rounds', 'avg_first_response_days', 'avg_decision_days', 'meetings_verified', 'meetings_unverified_past',
            'risk_score', 'risk_level', 'risk_signals', 'checkins_total', 'last_checkin_progress', 'publications_total', 'publications_published', 'permits_expired', 'viva_outcome'];
        if (!$this->projects) {
            return [$head, []];
        }
        $ids = array_keys($this->projects);
        [$in, $p] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'pj');
        $vers = [];
        foreach ($DB->get_records_sql("SELECT v.id, m.projid, m.id AS pmsid, v.timecreated, m.duedate
                                         FROM {local_researchcentral_ver} v JOIN {local_researchcentral_pms} m ON m.id = v.pmsid
                                        WHERE m.projid $in", $p) as $v) {
            $vers[$v->projid][] = $v;
        }
        $ext = [];
        foreach ($DB->get_records_sql("SELECT e.id, m.projid, e.status FROM {local_researchcentral_ext} e
                                         JOIN {local_researchcentral_pms} m ON m.id = e.pmsid WHERE m.projid $in", $p) as $e) {
            $ext[$e->projid][] = $e->status;
        }
        $appt = [];
        foreach ($DB->get_records_sql("SELECT id, projid, status FROM {local_researchcentral_appt} WHERE projid $in AND status IN ('booked','done')", $p) as $a) {
            $appt[$a->projid][] = $a->status;
        }
        $msgs = [];
        foreach ($DB->get_records_sql("SELECT id, projid, userid FROM {local_researchcentral_msg} WHERE projid $in", $p) as $m) {
            $msgs[$m->projid][] = (int)$m->userid;
        }
        $last = $DB->get_records_sql_menu("SELECT projid, MAX(timecreated) FROM {local_researchcentral_act} WHERE projid $in GROUP BY projid", $p);
        $stuids = array_unique(array_map(fn($x) => (int)$x->userid, $this->projects));
        [$uin, $up] = $DB->get_in_or_equal($stuids, SQL_PARAMS_NAMED, 'su');
        $threads = $DB->get_records_sql_menu("SELECT userid, COUNT(1) FROM {local_researchcentral_thread} WHERE userid $uin GROUP BY userid", $up);
        $replies = $DB->get_records_sql_menu("SELECT userid, COUNT(1) FROM {local_researchcentral_post} WHERE userid $uin GROUP BY userid", $up);

        $decs = [];
        foreach ($DB->get_records_select('local_researchcentral_decision', "projid $in", $p) as $d) {
            $decs[$d->projid][] = $d;
        }
        $verified = $unverified = [];
        foreach ($DB->get_records_select('local_researchcentral_appt', "projid $in AND status IN ('booked','done') AND endtime < :now", $p + ['now' => $now]) as $a) {
            if (appt::verification($a) === 'verified') {
                $verified[$a->projid] = ($verified[$a->projid] ?? 0) + 1;
            } else {
                $unverified[$a->projid] = ($unverified[$a->projid] ?? 0) + 1;
            }
        }
        $riskmap = risk::scores($this->projects, false);
        $cnt = function (string $table, string $extra = '') use ($DB, $in, $p): array {
            $out = [];
            foreach ($DB->get_records_sql("SELECT projid, COUNT(id) AS n FROM {{$table}} WHERE projid $in $extra GROUP BY projid", $p) as $r) {
                $out[(int)$r->projid] = (int)$r->n;
            }
            return $out;
        };
        $nci = $cnt('local_researchcentral_checkin');
        $npub = $cnt('local_researchcentral_pub');
        $npubd = $cnt('local_researchcentral_pub', "AND status IN ('accepted','published')");
        $nperx = $cnt('local_researchcentral_permit', "AND (status = 'expired' OR (status = 'approved' AND expiry > 0 AND expiry < " . $now . "))");
        $lastci = [];
        foreach ($DB->get_records_sql("SELECT c.projid, c.progress FROM {local_researchcentral_checkin} c WHERE c.projid $in AND c.timecreated =
                (SELECT MAX(c2.timecreated) FROM {local_researchcentral_checkin} c2 WHERE c2.projid = c.projid)", $p) as $r) {
            $lastci[(int)$r->projid] = (int)$r->progress;
        }
        $vivaout = [];
        foreach ($DB->get_records_select('local_researchcentral_viva', "projid $in", $p, 'id') as $r) {
            $vivaout[(int)$r->projid] = (string)$r->outcome;
        }
        $rows = [];
        foreach ($this->projects as $pr) {
            $ms = $pr->ms;
            $submitted = $revision = $marked = 0;
            $turn = [];
            foreach ($ms as $m) {
                $submitted += $m->status === 'submitted' ? 1 : 0;
                $revision += $m->status === 'revision' ? 1 : 0;
                if ($m->grade !== null && $m->maxgrade) {
                    $marked++;
                }
                if ($m->timesubmitted && $m->timedecided && $m->timedecided > $m->timesubmitted) {
                    $turn[] = ($m->timedecided - $m->timesubmitted) / DAYSECS;
                }
            }
            $pv = $vers[$pr->id] ?? [];
            $perstage = [];
            $late = 0;
            foreach ($pv as $v) {
                $perstage[$v->pmsid] = ($perstage[$v->pmsid] ?? 0) + 1;
                $late += ($v->duedate && $v->timecreated > $v->duedate) ? 1 : 0;
            }
            $resub = count(array_filter($perstage, fn($n) => $n > 1));
            $ov = portal::overall($ms);
            $e = $ext[$pr->id] ?? [];
            $a = $appt[$pr->id] ?? [];
            $mm = $msgs[$pr->id] ?? [];
            $lastact = max((int)($last[$pr->id] ?? 0), (int)$pr->timecreated);
            $due = $pr->current->duedate ?? 0;
            $rows[] = [gmdate('Y-m-d H:i:s', $now), $pr->id, $this->pid((int)$pr->userid),
                $this->anon ? '' : ($pr->student->username ?? ''), $this->anon ? '' : ($pr->student->idnumber ?? ''),
                $this->name($pr->student), $this->anon ? '' : ($pr->student->email ?? ''),
                $pr->school, $pr->progname, $pr->level, $this->anon ? '' : $pr->title, $this->pid((int)$pr->supervisorid), $this->name($pr->supervisor),
                self::d((int)$pr->startdate), $pr->state, $pr->total, $pr->done, $submitted, $revision, $pr->overdue,
                $pr->total ? round(100 * $pr->done / $pr->total, 1) : '',
                $pr->current->stagename ?? '', self::d((int)$due), $due ? (int)floor(($due - $now) / DAYSECS) : '',
                $ov ? round($ov['percent'], 1) : '', $marked, count($pv), $resub, $late,
                $turn ? round(array_sum($turn) / count($turn), 1) : '',
                count($e), count(array_filter($e, fn($s) => $s === 'approved')), count($a), count(array_filter($a, fn($s) => $s === 'done')),
                count(array_filter($mm, fn($u) => $u === (int)$pr->userid)), count(array_filter($mm, fn($u) => $u === (int)$pr->supervisorid)),
                (int)($threads[$pr->userid] ?? 0), (int)($replies[$pr->userid] ?? 0), self::dt($lastact),
                (int)floor(($now - $lastact) / DAYSECS), (int)floor(($now - (int)$pr->startdate) / DAYSECS),
                1 + count($pr->cosupervisors), $this->anon ? '' : implode('; ', array_map('fullname', $pr->cosupervisors)), count($decs[$pr->id] ?? []),
                $this->avg(array_map(fn($d) => max(0, $d->firstresponse - $d->timesubmitted) / DAYSECS, array_filter($decs[$pr->id] ?? [], fn($d) => $d->timesubmitted > 0))),
                $this->avg(array_map(fn($d) => max(0, $d->timecreated - $d->timesubmitted) / DAYSECS, array_filter($decs[$pr->id] ?? [], fn($d) => $d->timesubmitted > 0))),
                (int)($verified[$pr->id] ?? 0), (int)($unverified[$pr->id] ?? 0),
                $riskmap[$pr->id]['score'] ?? 0, $riskmap[$pr->id]['level'] ?? '', implode(';', $riskmap[$pr->id]['signals'] ?? []),
                $nci[$pr->id] ?? 0, $lastci[$pr->id] ?? '', $npub[$pr->id] ?? 0, $npubd[$pr->id] ?? 0, $nperx[$pr->id] ?? 0, $vivaout[$pr->id] ?? ''];
        }
        return [$head, $rows];
    }

    private function avg(array $v): string {
        return $v ? (string)round(array_sum($v) / count($v), 1) : '';
    }

    private function reviews(): array {
        global $DB;
        $head = ['review_id', 'project_id', 'student_id', 'school', 'programme', 'level', 'stage', 'round', 'reviewer_id', 'reviewer_role',
            'recommendation', 'grade', 'review_status', 'comment_to_student', 'confidential_comment', 'attachments', 'review_utc',
            'submission_utc', 'days_to_review', 'decision', 'decision_utc'];
        if (!$this->projects) {
            return [$head, []];
        }
        [$in, $p] = $DB->get_in_or_equal(array_keys($this->projects), SQL_PARAMS_NAMED, 'pj');
        $sql = "SELECT r.*, s.name AS stagename, m.timesubmitted AS msub FROM {local_researchcentral_review} r
                  JOIN {local_researchcentral_pms} m ON m.id = r.pmsid JOIN {local_researchcentral_stage} s ON s.id = m.stageid
                 WHERE r.projid $in ORDER BY r.timecreated, r.id";
        $decs = [];
        foreach ($DB->get_records_select('local_researchcentral_decision', "projid $in", $p) as $d) {
            $decs[$d->pmsid][$d->round] = $d;
        }
        $vsub = $DB->get_records_sql_menu("SELECT id, timecreated FROM {local_researchcentral_ver} WHERE pmsid IN (SELECT id FROM {local_researchcentral_pms} WHERE projid $in)", $p);
        $fs = get_file_storage();
        $ctx = \context_system::instance()->id;
        $rows = [];
        foreach ($DB->get_recordset_sql($sql, $p) as $r) {
            $pr = $this->projects[$r->projid];
            $d = $decs[$r->pmsid][$r->round] ?? null;
            $sub = (int)($vsub[$r->verid] ?? $r->msub);
            $files = count($fs->get_area_files($ctx, 'local_researchcentral', 'feedback', $r->id, 'id', false));
            $rows[] = [$r->id, $pr->id, $this->pid((int)$pr->userid), $pr->school, $pr->progname, $pr->level, $r->stagename, $r->round,
                $this->pid((int)$r->userid), (int)$r->userid === (int)$pr->supervisorid ? 'lead' : 'co', $r->recommendation, $r->grade ?? '', $r->status,
                $this->anon ? '' : $r->comment, $this->anon ? '' : $r->confidential, $files, self::dt((int)$r->timecreated), self::dt($sub),
                $sub ? round(max(0, $r->timecreated - $sub) / DAYSECS, 1) : '', $d->decision ?? '', self::dt((int)($d->timecreated ?? 0))];
        }
        return [$head, $rows];
    }

    private function meetings(): array {
        global $DB;
        $head = ['appointment_id', 'project_id', 'student_id', 'supervisor_id', 'school', 'programme', 'start_utc', 'end_utc', 'duration_min',
            'platform', 'online', 'status', 'allocated_by_supervisor', 'student_confirmed', 'supervisor_confirmed', 'verification',
            'student_joined_link', 'supervisor_joined_link', 'minutes_recorded', 'agenda'];
        if (!$this->projects) {
            return [$head, []];
        }
        [$in, $p] = $DB->get_in_or_equal(array_keys($this->projects), SQL_PARAMS_NAMED, 'pj');
        $rows = [];
        foreach ($DB->get_records_select('local_researchcentral_appt', "projid $in AND status <> 'open'", $p, 'starttime') as $a) {
            $pr = $this->projects[$a->projid];
            $rows[] = [$a->id, $pr->id, $this->pid((int)$a->studentid), $this->pid((int)$a->supervisorid), $pr->school, $pr->progname,
                self::dt((int)$a->starttime), self::dt((int)$a->endtime), (int)(($a->endtime - $a->starttime) / 60), $a->platform ?: 'inperson',
                appt::is_online($a) ? 1 : 0, $a->status, (int)$a->allocated, (int)$a->studentconf, (int)$a->supconf, appt::verification($a),
                $a->studentjoin ? 1 : 0, $a->supjoin ? 1 : 0, trim((string)$a->minutes) !== '' ? 1 : 0, $this->anon ? '' : $a->agenda];
        }
        return [$head, $rows];
    }

    private function stages(): array {
        global $DB;
        $now = time();
        $head = ['snapshot_utc', 'project_id', 'student_id', 'school', 'programme', 'level', 'supervisor_id', 'stage_order', 'stage', 'stage_weight',
            'status', 'is_overdue', 'due_date', 'submitted_utc', 'decided_utc', 'review_turnaround_days', 'days_late_first_submission',
            'versions', 'grade', 'max_grade', 'grade_pct', 'extensions_requested', 'extensions_approved', 'review_rounds', 'last_first_response_days', 'last_decision_days'];
        if (!$this->projects) {
            return [$head, []];
        }
        [$in, $p] = $DB->get_in_or_equal(array_keys($this->projects), SQL_PARAMS_NAMED, 'pj');
        $first = $DB->get_records_sql_menu("SELECT v.pmsid, MIN(v.timecreated) FROM {local_researchcentral_ver} v JOIN {local_researchcentral_pms} m ON m.id = v.pmsid
                                             WHERE m.projid $in GROUP BY v.pmsid", $p);
        $nver = $DB->get_records_sql_menu("SELECT v.pmsid, COUNT(1) FROM {local_researchcentral_ver} v JOIN {local_researchcentral_pms} m ON m.id = v.pmsid
                                            WHERE m.projid $in GROUP BY v.pmsid", $p);
        $ex = [];
        foreach ($DB->get_records_sql("SELECT e.id, e.pmsid, e.status FROM {local_researchcentral_ext} e JOIN {local_researchcentral_pms} m ON m.id = e.pmsid
                                        WHERE m.projid $in", $p) as $e) {
            $ex[$e->pmsid][] = $e->status;
        }
        $lastdec = [];
        $ndec = [];
        foreach ($DB->get_records_select('local_researchcentral_decision', "projid $in", $p, 'round') as $d) {
            $lastdec[$d->pmsid] = $d;
            $ndec[$d->pmsid] = ($ndec[$d->pmsid] ?? 0) + 1;
        }
        $rows = [];
        foreach ($this->projects as $pr) {
            foreach ($pr->ms as $i => $m) {
                $e = $ex[$m->id] ?? [];
                $ld = $lastdec[$m->id] ?? null;
                $fv = (int)($first[$m->id] ?? 0);
                $rows[] = [gmdate('Y-m-d H:i:s', $now), $pr->id, $this->pid((int)$pr->userid), $pr->school, $pr->progname, $pr->level,
                    $this->pid((int)$pr->supervisorid), $i + 1, $m->stagename, $m->weight,
                    $m->status, portal::is_overdue($m) ? 1 : 0, self::d((int)$m->duedate), self::dt((int)$m->timesubmitted), self::dt((int)$m->timedecided),
                    ($m->timesubmitted && $m->timedecided > $m->timesubmitted) ? round(($m->timedecided - $m->timesubmitted) / DAYSECS, 1) : '',
                    ($fv && $m->duedate) ? max(0, (int)ceil(($fv - $m->duedate) / DAYSECS)) : '',
                    (int)($nver[$m->id] ?? 0), $m->grade ?? '', $m->maxgrade ?: '',
                    ($m->grade !== null && $m->maxgrade) ? round(100 * $m->grade / $m->maxgrade, 1) : '', count($e), count(array_filter($e, fn($s) => $s === 'approved')),
                    (int)($ndec[$m->id] ?? 0), ($ld && $ld->timesubmitted) ? round(max(0, $ld->firstresponse - $ld->timesubmitted) / DAYSECS, 1) : '',
                    ($ld && $ld->timesubmitted) ? round(max(0, $ld->timecreated - $ld->timesubmitted) / DAYSECS, 1) : ''];
            }
        }
        return [$head, $rows];
    }

    private function events(): array {
        global $DB;
        $head = ['event_utc', 'project_id', 'student_id', 'school', 'programme', 'actor_id', 'actor_role', 'action', 'detail'];
        if (!$this->projects) {
            return [$head, []];
        }
        [$in, $p] = $DB->get_in_or_equal(array_keys($this->projects), SQL_PARAMS_NAMED, 'pj');
        $rows = [];
        foreach ($DB->get_recordset_sql("SELECT * FROM {local_researchcentral_act} WHERE projid $in ORDER BY timecreated, id", $p) as $a) {
            $pr = $this->projects[$a->projid];
            $role = (int)$a->userid === (int)$pr->userid ? 'student' : (portal::is_project_supervisor($pr, (int)$a->userid) ? 'supervisor' : 'coordinator');
            $rows[] = [self::dt((int)$a->timecreated), $pr->id, $this->pid((int)$pr->userid), $pr->school, $pr->progname,
                $this->pid((int)$a->userid), $role, $a->action, $this->anon ? '' : $a->detail];
        }
        return [$head, $rows];
    }

    /** Data dictionary rows: [file, column, description]. */
    public static function dictionary(): array {
        $d = [];
        $add = function (string $file, array $cols) use (&$d) {
            foreach ($cols as $c => $desc) {
                $d[] = [$file, $c, $desc];
            }
        };
        $add('students', [
            'snapshot_utc' => 'When the file was generated (state is as of this moment)',
            'project_id' => 'Project key', 'student_id' => 'User id (or hashed id when anonymised)',
            'project_state' => 'completed | review | revision | overdue | active',
            'progress_pct' => 'Approved stages / total stages x 100', 'days_to_next_due' => 'Negative = days overdue on the current stage',
            'overall_mark_pct' => 'Weighted mean of marked stages (weights from stage setup, equal if unset)',
            'resubmitted_stages' => 'Stages with more than one uploaded version', 'late_submissions' => 'Versions uploaded after the stage due date',
            'avg_review_turnaround_days' => 'Mean days from submission to supervisor decision',
            'appointments_completed' => 'Supervisor-marked completed meetings', 'forum_threads/forum_replies' => 'Student contribution to forums',
            'days_since_activity' => 'Days since the last logged event on the project (risk signal)']);
        $add('stages', ['stage_order' => 'Position in the programme pipeline', 'is_overdue' => '1 if not approved and past due',
            'days_late_first_submission' => 'Days between due date and first upload (0 = on time)', 'grade_pct' => 'Grade / max grade x 100']);
        $add('students', ['supervisors_total' => 'Lead plus co-supervisors', 'avg_first_response_days' => 'Mean days from submission to the first supervisor review',
            'avg_decision_days' => 'Mean days from submission to the stage decision', 'meetings_verified' => 'Past meetings confirmed by both student and supervisor',
            'meetings_unverified_past' => 'Past meetings not confirmed by both',
            'risk_score' => 'Early-warning score (weighted signals; 5+ high, 3-4 medium)', 'risk_signals' => 'Semicolon list of active risk signals',
            'last_checkin_progress' => 'Latest self-rated progress 1 (blocked) to 5 (ahead)', 'publications_published' => 'Accepted or published outputs',
            'permits_expired' => 'Ethics or research permits past expiry', 'viva_outcome' => 'Recorded viva outcome']);
        $add('reviews', ['round' => 'Review round (1 = first submission)', 'reviewer_role' => 'lead | co', 'recommendation' => 'approve | minor | major',
            'review_status' => 'active, or superseded when the reviewer revised their review (kept for audit)', 'days_to_review' => 'Days from submission to this review']);
        $add('meetings', ['verification' => 'verified | awaiting | disputed | missed | upcoming', 'student_joined_link' => '1 if the student opened the join link',
            'allocated_by_supervisor' => '1 if the supervisor reserved the slot for the student']);
        $add('events', ['action' => 'submit | decide | message | reassign | extension... (audit trail)', 'actor_role' => 'Derived from the project']);
        return $d;
    }
}
