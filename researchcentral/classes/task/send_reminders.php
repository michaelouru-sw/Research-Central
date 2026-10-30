<?php
namespace local_researchcentral\task;

defined('MOODLE_INTERNAL') || die();

use local_researchcentral\local\tracker;

/**
 * Daily: refresh statuses, then send "due soon" and "overdue" reminders to student and supervisor.
 */
class send_reminders extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_sendreminders', 'local_researchcentral');
    }

    public function execute(): void {
        global $DB;
        tracker::sync_all();
        $this->portal_reminders();

        $daysconf = get_config('local_researchcentral', 'reminderdays');
        $days = array_values(array_filter(array_map('intval', explode(',', $daysconf !== false && $daysconf !== '' ? $daysconf : '7,3,1'))));
        $repeat = max(1, (int)(get_config('local_researchcentral', 'overduerepeat') ?: 7)) * DAYSECS;
        $tosupervisor = get_config('local_researchcentral', 'notifysupervisor');
        $tosupervisor = ($tosupervisor === false) ? true : (bool)$tosupervisor;
        $now = time();

        $sql = "SELECT m.id, m.duedate, m.status, t.courseid, t.userid, t.supervisorid,
                       s.name AS stagename, c.fullname AS coursename
                  FROM {local_researchcentral_ms} m
                  JOIN {local_researchcentral_thesis} t ON t.id = m.thesisid
                  JOIN {local_researchcentral_stage} s ON s.id = m.stageid
                  JOIN {course} c ON c.id = t.courseid
                  JOIN {local_researchcentral_course} tc ON tc.courseid = t.courseid
                 WHERE m.status <> :done AND m.duedate > 0";
        foreach ($DB->get_recordset_sql($sql, ['done' => tracker::COMPLETE]) as $m) {
            $student = \core_user::get_user($m->userid);
            if (!$student || $student->deleted) {
                continue;
            }
            $a = (object)[
                'stage' => format_string($m->stagename),
                'course' => format_string($m->coursename),
                'student' => fullname($student),
                'due' => userdate($m->duedate, get_string('strftimedatefullshort', 'langconfig')),
                'url' => (new \moodle_url('/local/researchcentral/index.php', ['id' => $m->courseid]))->out(false),
            ];

            if ((int)$m->status === tracker::OVERDUE) {
                $last = (int)$DB->get_field_sql(
                    "SELECT MAX(timesent) FROM {local_researchcentral_log} WHERE msid = ? AND type = ?",
                    [$m->id, 'overdue']);
                if ($last && ($now - $last) < $repeat) {
                    continue;
                }
                $a->late = max(1, (int)floor(($now - $m->duedate) / DAYSECS));
                $this->notify($m, $student, $tosupervisor, 'overdue', $a);
                $this->log($m->id, 'overdue');
            } else {
                $left = (int)ceil(($m->duedate - $now) / DAYSECS);
                if (!in_array($left, $days, true)) {
                    continue;
                }
                $type = 'due' . $left;
                if ($DB->record_exists('local_researchcentral_log', ['msid' => $m->id, 'type' => $type])) {
                    continue;
                }
                $a->days = $left;
                $this->notify($m, $student, $tosupervisor, 'due', $a);
                $this->log($m->id, $type);
            }
        }
    }

    /**
     * Central tracker: due-soon and overdue reminders for stage submissions. One digest per student and one per
     * supervisor per run (not one message per stage), and no per-row queries, so it scales to thousands of projects.
     */
    protected function portal_reminders(): void {
        global $DB;
        $daysconf = get_config('local_researchcentral', 'reminderdays');
        $days = array_values(array_filter(array_map('intval', explode(',', $daysconf !== false && $daysconf !== '' ? $daysconf : '7,3,1'))));
        $repeat = max(1, (int)(get_config('local_researchcentral', 'overduerepeat') ?: 7)) * DAYSECS;
        $tosup = get_config('local_researchcentral', 'notifysupervisor');
        $tosup = ($tosup === false) ? true : (bool)$tosup;
        $now = time();
        $dfs = get_string('strftimedatefullshort', 'langconfig');

        // What was already sent: [msid][type] => last time.
        $sent = [];
        foreach ($DB->get_recordset_sql("SELECT msid, type, MAX(timesent) AS t FROM {local_researchcentral_log} WHERE type LIKE 'p\_%' GROUP BY msid, type") as $r) {
            $sent[(int)$r->msid][$r->type] = (int)$r->t;
        }
        $sql = "SELECT m.id, m.duedate, p.id AS projid, p.userid, p.supervisorid, s.name AS stagename
                  FROM {local_researchcentral_pms} m
                  JOIN {local_researchcentral_proj} p ON p.id = m.projid
                  JOIN {local_researchcentral_stage} s ON s.id = m.stageid
                 WHERE m.status IN ('pending', 'revision') AND m.duedate > 0 AND s.needsupload = 1";
        $perstudent = [];
        $persup = [];
        $tolog = [];
        foreach ($DB->get_recordset_sql($sql) as $m) {
            if ($m->duedate < $now) {
                $type = 'p_overdue';
                $last = $sent[(int)$m->id][$type] ?? 0;
                if ($last && ($now - $last) < $repeat) {
                    continue;
                }
                $late = max(1, (int)floor(($now - $m->duedate) / DAYSECS));
                $line = get_string('digest_line_overdue', 'local_researchcentral', (object)['stage' => format_string($m->stagename), 'due' => userdate($m->duedate, $dfs), 'late' => $late]);
            } else {
                $left = (int)ceil(($m->duedate - $now) / DAYSECS);
                $type = 'p_due' . $left;
                if (!in_array($left, $days, true) || isset($sent[(int)$m->id][$type])) {
                    continue;
                }
                $line = get_string('digest_line_due', 'local_researchcentral', (object)['stage' => format_string($m->stagename), 'due' => userdate($m->duedate, $dfs), 'days' => $left]);
            }
            $perstudent[(int)$m->userid][] = $line;
            $persup[(int)$m->supervisorid][(int)$m->userid][] = $line;
            $tolog[] = (object)['msid' => $m->id, 'type' => $type, 'timesent' => $now];
        }
        if (!$tolog) {
            return;
        }
        $ids = array_unique(array_merge(array_keys($perstudent), array_keys($persup)));
        $users = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($DB->get_records_list('user', 'id', $chunk) as $u) {
                $users[(int)$u->id] = $u;
            }
        }
        $url = (new \moodle_url('/local/researchcentral/portal.php'))->out(false);
        foreach ($perstudent as $uid => $lines) {
            if (empty($users[$uid]) || $users[$uid]->deleted) {
                continue;
            }
            $a = (object)['n' => count($lines)];
            $this->send($users[$uid], get_string('digest_student_subject', 'local_researchcentral', $a),
                get_string('digest_student_body', 'local_researchcentral', (object)['lines' => '- ' . implode("\n- ", $lines)]), $url, 0);
        }
        if ($tosup) {
            foreach ($persup as $sid => $students) {
                if (!$sid || empty($users[$sid]) || $users[$sid]->deleted) {
                    continue;
                }
                $out = [];
                $count = 0;
                foreach ($students as $stuid => $lines) {
                    $name = !empty($users[$stuid]) ? fullname($users[$stuid]) : '-';
                    foreach ($lines as $l) {
                        $out[] = $name . ': ' . $l;
                        $count++;
                    }
                }
                $this->send($users[$sid], get_string('digest_sup_subject', 'local_researchcentral', (object)['n' => $count, 'students' => count($students)]),
                    get_string('digest_sup_body', 'local_researchcentral', (object)['lines' => '- ' . implode("\n- ", $out)]), $url, 0);
            }
        }
        foreach (array_chunk($tolog, 2000) as $chunk) {
            $DB->insert_records('local_researchcentral_log', $chunk);
        }
    }

    /** Appointments: reminder to both parties the day before. */
    protected function appointment_reminders(): void {
        global $DB;
        $now = time();
        $sql = "SELECT * FROM {local_researchcentral_appt} WHERE status = 'booked' AND starttime > :now AND starttime < :soon";
        foreach ($DB->get_recordset_sql($sql, ['now' => $now, 'soon' => $now + DAYSECS]) as $a) {
            if ($DB->record_exists('local_researchcentral_log', ['msid' => $a->id, 'type' => 'a_1d'])) {
                continue;
            }
            $info = (object)['when' => userdate($a->starttime, get_string('strftimedatetimeshort', 'langconfig')),
                'who' => fullname(\core_user::get_user($a->studentid)), 'topic' => $a->topic, 'where' => $a->location ?: '-'];
            foreach ([$a->studentid, $a->supervisorid] as $uid) {
                \local_researchcentral\local\portal::notify($uid, get_string('msg_apptreminder_subject', 'local_researchcentral', $info),
                    get_string('msg_booked_body', 'local_researchcentral', $info),
                    new \moodle_url('/local/researchcentral/appointments.php'), 'booking');
            }
            $this->log($a->id, 'a_1d');
        }
    }

    protected function notify(\stdClass $m, \stdClass $student, bool $tosupervisor, string $kind, \stdClass $a): void {
        $subject = get_string("reminder_{$kind}_subject", 'local_researchcentral', $a);
        $body = get_string("reminder_{$kind}_body", 'local_researchcentral', $a);
        $this->send($student, $subject, $body, $a->url, (int)$m->courseid);
        if ($tosupervisor && $m->supervisorid) {
            $sup = \core_user::get_user($m->supervisorid);
            if ($sup && !$sup->deleted) {
                $this->send($sup, $subject . ' – ' . $a->student, $body, $a->url, (int)$m->courseid);
            }
        }
    }

    protected function send(\stdClass $to, string $subject, string $body, string $url, int $courseid): void {
        $message = new \core\message\message();
        $message->component = 'local_researchcentral';
        $message->name = 'reminder';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $to;
        $message->subject = $subject;
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = $url;
        $message->contexturlname = get_string('pluginname', 'local_researchcentral');
        $message->courseid = $courseid;
        message_send($message);
    }

    protected function log(int $msid, string $type): void {
        global $DB;
        $DB->insert_record('local_researchcentral_log', (object)['msid' => $msid, 'type' => $type, 'timesent' => time()]);
    }
}
