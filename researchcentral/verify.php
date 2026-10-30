<?php
// Public verification of a generated document: no login. Shows only that it is genuine, to whom and when.
require_once(__DIR__ . '/../../config.php');

$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', optional_param('c', '', PARAM_RAW_TRIMMED)));
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/researchcentral/verify.php', ['c' => $code]));
$PAGE->set_pagelayout('base');
$PAGE->set_title(get_string('verify_title', 'local_researchcentral'));
$PAGE->set_heading(get_string('verify_title', 'local_researchcentral'));
$doc = $code !== '' ? $DB->get_record('local_researchcentral_doc', ['code' => $code]) : null;
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('verify_title', 'local_researchcentral'), 3);
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url->out_omit_querystring(), 'class' => 'mb-3']) .
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'c', 'value' => $code, 'class' => 'form-control d-inline-block w-auto mr-2', 'maxlength' => 20, 'aria-label' => get_string('doc_code', 'local_researchcentral')]) .
    html_writer::tag('button', get_string('verify_go', 'local_researchcentral'), ['type' => 'submit', 'class' => 'btn btn-primary']) . html_writer::end_tag('form');
if ($code !== '') {
    if (!$doc || $doc->revoked) {
        echo $OUTPUT->notification(get_string($doc ? 'verify_revoked' : 'verify_none', 'local_researchcentral'), 'error');
    } else {
        $proj = $DB->get_record('local_researchcentral_proj', ['id' => $doc->projid]);
        $u = $proj ? core_user::get_user($proj->userid) : null;
        $prog = $proj ? $DB->get_field('local_researchcentral_prog', 'name', ['id' => $proj->progid]) : '';
        $who = $doc->kind === 'supcert' ? core_user::get_user((int)preg_replace('/\D/', '', $doc->detail)) : $u;
        echo $OUTPUT->notification(get_string('verify_ok', 'local_researchcentral'), 'success');
        echo html_writer::div(
            html_writer::div(get_string('doc_' . $doc->kind, 'local_researchcentral'), 'h5') .
            html_writer::div(($who ? s(fullname($who)) : '-') . ($prog ? ' · ' . format_string($prog) : '')) .
            html_writer::div(get_string('doc_date', 'local_researchcentral') . ': ' . userdate($doc->timecreated, get_string('strftimedate', 'langconfig')), 'text-muted') .
            html_writer::div(get_string('doc_code', 'local_researchcentral') . ': ' . s($doc->code), 'text-muted'), 'p-3 border rounded');
    }
}
echo $OUTPUT->footer();
