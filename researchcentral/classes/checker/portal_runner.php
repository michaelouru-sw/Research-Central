<?php
namespace local_researchcentral\checker;

defined('MOODLE_INTERNAL') || die();

/** Runs the document checker on a version uploaded in the central tracker. */
class portal_runner {

    /** @return int|null report id; null when the stage has no rules or no readable file */
    public static function check_version(int $verid): ?int {
        global $DB;
        $ver = $DB->get_record('local_researchcentral_ver', ['id' => $verid]);
        if (!$ver) {
            return null;
        }
        $m = $DB->get_record('local_researchcentral_pms', ['id' => $ver->pmsid]);
        $proj = $m ? $DB->get_record('local_researchcentral_proj', ['id' => $m->projid]) : null;
        if (!$proj) {
            return null;
        }
        $rules = $DB->get_record('local_researchcentral_rules', ['stageid' => $m->stageid]);
        if (!$rules) {
            return null;
        }
        $files = get_file_storage()->get_area_files(\context_system::instance()->id, 'local_researchcentral',
            'submission', $verid, 'timemodified DESC', false);
        $file = $files ? reset($files) : null;
        if (!$file) {
            return null;
        }
        $report = (object)[
            'thesisid' => 0, 'projid' => $proj->id, 'verid' => $verid, 'stageid' => $m->stageid,
            'userid' => $proj->userid, 'filename' => $file->get_filename(), 'filehash' => $file->get_contenthash(),
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
            $doc = $ext === 'docx' ? docx_reader::read($tmp)
                : pdf_reader::read($tmp, (string)get_config('local_researchcentral', 'pdftotextpath'));
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

        $stage = $DB->get_record('local_researchcentral_stage', ['id' => $m->stageid]);
        $a = (object)['stage' => format_string($stage->name), 'student' => fullname(\core_user::get_user($proj->userid)),
            'overall' => get_string('overall_' . $report->status, 'local_researchcentral'),
            'url' => (new \moodle_url('/local/researchcentral/preport.php', ['id' => $report->id]))->out(false)];
        foreach (array_merge([$proj->userid], \local_researchcentral\local\portal::supervisor_ids($proj)) as $uid) {
            \local_researchcentral\local\portal::notify($uid, get_string('checkreport_subject', 'local_researchcentral', $a),
                get_string('checkreport_body', 'local_researchcentral', $a), new \moodle_url($a->url), 'checkreport');
        }
        return (int)$report->id;
    }
}
