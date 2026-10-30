<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\portal;

$reportid = required_param('id', PARAM_INT);
\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$report = $DB->get_record('local_researchcentral_report', ['id' => $reportid, 'thesisid' => 0], '*', MUST_EXIST);
$proj = $DB->get_record('local_researchcentral_proj', ['id' => $report->projid], '*', MUST_EXIST);
portal::require_view($proj);
$report->stagename = $DB->get_field('local_researchcentral_stage', 'name', ['id' => $report->stageid]);
$report->studentid = $proj->userid;

$PAGE->set_url('/local/researchcentral/preport.php', ['id' => $reportid]);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('docreport', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

$data = json_decode((string)$report->results, true) ?: [];
$student = core_user::get_user($report->studentid);
$badge = ['pass' => 'badge-success', 'warn' => 'badge-warning', 'fail' => 'badge-danger', 'error' => 'badge-secondary', 'skip' => 'badge-light'];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('docreport', 'local_researchcentral'));
echo html_writer::tag('p',
    s($report->filename) . ' · ' . format_string($report->stagename) . ' · ' . fullname($student) . ' · ' .
    userdate($report->timecreated) . ' ' .
    html_writer::span(get_string('overall_' . $report->status, 'local_researchcentral'), 'badge ' . $badge[$report->status]));

if ($report->status === 'error') {
    echo $OUTPUT->notification(s($data['error'] ?? get_string('filereadfailed', 'local_researchcentral')), 'error');
} else {
    $table = new html_table();
    $table->head = [get_string('check', 'local_researchcentral'), get_string('status', 'local_researchcentral'),
        get_string('details', 'local_researchcentral')];
    $table->attributes['class'] = 'table table-striped generaltable';
    foreach ($data['results'] ?? [] as $r) {
        $key = $r['key'];
        $params = (object)($r['params'] ?? []);
        if ($r['status'] === 'skip') {
            $msg = get_string('chk_skip_' . ($params->reason ?? 'noformat'), 'local_researchcentral');
        } else {
            $msg = get_string('chk_' . $key . '_' . ($r['status'] === 'pass' ? 'pass' : 'issue'), 'local_researchcentral', $params);
        }
        $table->data[] = [
            get_string('chk_' . $key, 'local_researchcentral'),
            html_writer::span(get_string('res_' . $r['status'], 'local_researchcentral'), 'badge ' . $badge[$r['status']]),
            s($msg),
        ];
    }
    echo html_writer::table($table);

    $st = $data['stats'] ?? [];
    $bits = [];
    foreach (['words' => 'stat_words', 'pages' => 'stat_pages', 'refs' => 'stat_refs', 'citations' => 'stat_citations'] as $k => $str) {
        if (isset($st[$k]) && $st[$k] !== null) {
            $bits[] = get_string($str, 'local_researchcentral') . ': ' . (int)$st[$k];
        }
    }
    if ($bits) {
        echo html_writer::tag('p', implode(' · ', $bits), ['class' => 'text-muted']);
    }
}
echo html_writer::tag('p', get_string('report_disclaimer', 'local_researchcentral'), ['class' => 'small text-muted']);
echo html_writer::link(new moodle_url('/local/researchcentral/project.php', ['id' => $proj->id]),
    get_string('back', 'local_researchcentral'));
echo $OUTPUT->footer();
