<?php
namespace local_researchcentral\checker;

defined('MOODLE_INTERNAL') || die();

/**
 * Moodle glue: finds the student's latest Assignment file, runs the engine, stores and announces the report.
 */
class runner {

    /**
     * Check the student's latest document for the stage linked to this activity.
     *
     * @return int|null report id, or null when there are no rules or no submitted file
     */
    public static function check_for(int $courseid, int $userid, int $cmid, bool $notify): ?int {
        global $DB;

        $thesis = $DB->get_record('local_researchcentral_thesis', ['courseid' => $courseid, 'userid' => $userid]);
        if (!$thesis) {
            return null;
        }
        $stages = $DB->get_records_select('local_researchcentral_stage',
            "courseid = :c AND cmid = :cm AND (level = 'all' OR level = :l)",
            ['c' => $courseid, 'cm' => $cmid, 'l' => $thesis->level], 'sortorder, id', '*', 0, 1);
        $stage = $stages ? reset($stages) : null;
        if (!$stage) {
            return null;
        }
        $rules = $DB->get_record('local_researchcentral_rules', ['stageid' => $stage->id]);
        if (!$rules) {
            return null;
        }
        $file = self::find_file($cmid, $userid);
        if (!$file) {
            return null;
        }

        $report = (object)[
            'thesisid' => $thesis->id, 'stageid' => $stage->id, 'userid' => $userid,
            'filename' => $file->get_filename(), 'filehash' => $file->get_contenthash(),
            'status' => 'error', 'results' => '', 'timecreated' => time(),
        ];

        $maxmb = (int)(get_config('local_researchcentral', 'maxfilemb') ?: 30);
        $tmp = null;
        try {
            if ($file->get_filesize() > $maxmb * 1048576) {
                throw new \moodle_exception('filetoolarge', 'local_researchcentral');
            }
            $tmp = $file->copy_content_to_temp('researchcentral');
            if (!$tmp) {
                throw new \moodle_exception('filereadfailed', 'local_researchcentral');
            }
            $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
            if ($ext === 'docx') {
                $doc = docx_reader::read($tmp);
            } else {
                $doc = pdf_reader::read($tmp, (string)get_config('local_researchcentral', 'pdftotextpath'));
            }
            if (!$doc) {
                throw new \moodle_exception('filereadfailed', 'local_researchcentral');
            }
            $out = engine::run($doc, $rules);
            $report->status = $out['overall'];
            $report->results = json_encode(['results' => $out['results'], 'stats' => $out['stats']]);
        } catch (\Throwable $e) {
            $report->status = 'error';
            $report->results = json_encode(['error' => $e instanceof \moodle_exception
                ? get_string($e->errorcode, $e->module) : get_string('filereadfailed', 'local_researchcentral')]);
        } finally {
            if ($tmp && file_exists($tmp)) {
                @unlink($tmp);
            }
        }

        $report->id = $DB->insert_record('local_researchcentral_report', $report);
        if ($notify) {
            self::notify($thesis, $stage, $report, $courseid);
        }
        return (int)$report->id;
    }

    /** Newest .docx (else .pdf) in the student's latest submission for this assignment. */
    protected static function find_file(int $cmid, int $userid): ?\stored_file {
        global $DB;
        $cm = get_coursemodule_from_id('assign', $cmid);
        if (!$cm) {
            return null;
        }
        $sub = $DB->get_record('assign_submission',
            ['assignment' => $cm->instance, 'userid' => $userid, 'latest' => 1], '*', IGNORE_MULTIPLE);
        if (!$sub) {
            return null;
        }
        $ctx = \context_module::instance($cmid);
        $files = get_file_storage()->get_area_files($ctx->id, 'assignsubmission_file', 'submission_files',
            $sub->id, 'timemodified DESC', false);
        $pdf = null;
        foreach ($files as $f) {
            $ext = strtolower(pathinfo($f->get_filename(), PATHINFO_EXTENSION));
            if ($ext === 'docx') {
                return $f;
            }
            if ($ext === 'pdf' && !$pdf) {
                $pdf = $f;
            }
        }
        return $pdf;
    }

    protected static function notify(\stdClass $thesis, \stdClass $stage, \stdClass $report, int $courseid): void {
        $student = \core_user::get_user($thesis->userid);
        if (!$student || $student->deleted) {
            return;
        }
        $a = (object)[
            'stage' => format_string($stage->name),
            'student' => fullname($student),
            'overall' => get_string('overall_' . $report->status, 'local_researchcentral'),
            'url' => (new \moodle_url('/local/researchcentral/report.php', ['id' => $courseid, 'reportid' => $report->id]))->out(false),
        ];
        $recipients = [$student];
        $tosup = get_config('local_researchcentral', 'notifysupervisor');
        if (($tosup === false || $tosup) && $thesis->supervisorid) {
            $sup = \core_user::get_user($thesis->supervisorid);
            if ($sup && !$sup->deleted) {
                $recipients[] = $sup;
            }
        }
        foreach ($recipients as $to) {
            $m = new \core\message\message();
            $m->component = 'local_researchcentral';
            $m->name = 'checkreport';
            $m->userfrom = \core_user::get_noreply_user();
            $m->userto = $to;
            $m->subject = get_string('checkreport_subject', 'local_researchcentral', $a);
            $m->fullmessage = get_string('checkreport_body', 'local_researchcentral', $a);
            $m->fullmessageformat = FORMAT_PLAIN;
            $m->fullmessagehtml = '';
            $m->smallmessage = $m->subject;
            $m->notification = 1;
            $m->contexturl = $a->url;
            $m->contexturlname = get_string('viewreport', 'local_researchcentral');
            $m->courseid = $courseid;
            message_send($m);
        }
    }
}
