<?php
// Ethics committee: queue of applications, and one application with its decision form.
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\ethics;
use local_researchcentral\local\portal;
use local_researchcentral\local\research;

$id = optional_param('id', 0, PARAM_INT);
\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/researchcentral/ethics.php', $id ? ['id' => $id] : []));
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('eth_title', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));
$L = fn(string $k, $a = null) => get_string($k, 'local_researchcentral', $a);
$dts = get_string('strftimedatetimeshort', 'langconfig');

$e = $id ? $DB->get_record('local_researchcentral_eth', ['id' => $id], '*', MUST_EXIST) : null;
$proj = $e ? $DB->get_record('local_researchcentral_proj', ['id' => $e->projid], '*', MUST_EXIST) : null;
if ($proj) {
    portal::require_view($proj);
    $candecide = ethics::can_decide($proj);
    if (optional_param('do', '', PARAM_ALPHA) === 'decide' && data_submitted()) {
        require_sesskey();
        $exp = optional_param('expiry', '', PARAM_RAW_TRIMMED);
        $ok = ethics::decide($proj, $id, optional_param('decision', '', PARAM_ALPHA), optional_param('note', '', PARAM_TEXT), $exp !== '' ? (strtotime($exp) ?: 0) : 0);
        redirect($PAGE->url, $ok ? $L('eth_decided') : '', null, \core\output\notification::NOTIFY_SUCCESS);
    }
} else if (!ethics::is_committee() && !portal::is_coordinator()) {
    throw new moodle_exception('nopermissions', 'error', '', $L('eth_title'));
}

$cancommittee = ethics::is_committee() || portal::is_coordinator();
if (!$e && $cancommittee && data_submitted()) {
    require_sesskey();
    $do = optional_param('do', '', PARAM_ALPHA);
    if ($do === 'tplupload') {
        $n = ethics::save_uploads('tplfile', 'ethics_template', 0, 10);
        redirect($PAGE->url, $n ? $L('eth_tpl_added') : $L('eth_files_none'), null, $n ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
    } else if ($do === 'tpldelete') {
        ethics::delete_file('ethics_template', 0, optional_param('fname', '', PARAM_FILE));
        redirect($PAGE->url, $L('saved'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();
echo \local_researchcentral\output\ui::crumbs([[get_string('pluginname', 'local_researchcentral'), new moodle_url('/local/researchcentral/portal.php', ['view' => 'coordinator'])], [$L('eth_title'), null]]);
echo $OUTPUT->heading($L('eth_title'), 3);
if (!$e) {
    // Templates the committee makes available to students.
    $tplrows = '';
    foreach (ethics::files('ethics_template', 0) as $f) {
        $del = html_writer::tag('form', html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'tpldelete']) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'fname', 'value' => $f->get_filename()]) .
            html_writer::tag('button', $L('delete'), ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-danger ml-2']), ['method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'd-inline']);
        $tplrows .= html_writer::div(html_writer::link(ethics::file_url($f), '⬇ ' . s($f->get_filename()) . ' (' . display_size($f->get_filesize()) . ')') . $del, 'mb-1');
    }
    echo html_writer::div(html_writer::div($L('eth_templates'), 'tt-label') . html_writer::div($L('eth_tpl_help'), 'tt-sub mb-2') . ($tplrows ?: html_writer::div($L('eth_tpl_none'), 'tt-sub mb-2')) .
        html_writer::tag('form', html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'tplupload']) .
            html_writer::empty_tag('input', ['type' => 'file', 'name' => 'tplfile[]', 'accept' => '.docx', 'multiple' => 'multiple', 'required' => 'required', 'class' => 'mr-2', 'aria-label' => $L('eth_tpl_upload')]) .
            html_writer::tag('button', $L('eth_tpl_upload'), ['type' => 'submit', 'class' => 'btn btn-sm btn-primary']),
            ['method' => 'post', 'enctype' => 'multipart/form-data', 'action' => $PAGE->url->out(false)]), 'tt-card mb-3');
    $rows = ethics::queue();
    if (!$rows) {
        echo html_writer::div($L('eth_none'), 'tt-empty');
    } else {
        $t = new html_table();
        $t->attributes['class'] = 'tt-table';
        $t->head = [$L('eth_ref'), $L('student'), $L('eth_app_title'), $L('eth_risk'), $L('status'), $L('eth_submitted'), ''];
        foreach ($rows as $r) {
            $u = core_user::get_user($r->userid);
            $t->data[] = [s($r->ref), $u ? s(fullname($u)) : '-', s($r->title), $L('eth_risk_' . $r->risk), html_writer::span($L('eth_st_' . $r->status), 'tt-chk ' . ($r->status === 'submitted' ? 'tt-chk-warn' : ($r->status === 'rejected' ? 'tt-chk-no' : 'tt-chk-pass'))),
                userdate($r->timesubmitted, $dts), html_writer::link(new moodle_url('/local/researchcentral/ethics.php', ['id' => $r->id]), $L('open'), ['class' => 'btn btn-sm btn-outline-primary'])];
        }
        echo html_writer::div(html_writer::table($t), 'tt-tablewrap');
    }
} else {
    $u = core_user::get_user($proj->userid);
    echo html_writer::div(html_writer::div($L('eth_ref') . ': ' . s($e->ref), 'tt-label') . html_writer::div(s($e->title), 'tt-title') .
        html_writer::div(($u ? s(fullname($u)) : '-') . ' · ' . format_string($proj->title), 'tt-sub') .
        html_writer::div($L('eth_risk') . ': ' . $L('eth_risk_' . $e->risk) . ' · ' . $L('eth_submitted') . ': ' . userdate($e->timesubmitted, $dts), 'tt-sub mb-2') .
        html_writer::div(nl2br(s($e->summary)), 'mb-2') .
        (($af = ethics::file_links('ethics_app', (int)$e->id)) !== '' ? html_writer::div(html_writer::div($L('eth_attachments'), 'tt-label') . $af, 'mb-2') : '') .
        html_writer::span($L('eth_st_' . $e->status), 'tt-chk ' . ($e->status === 'submitted' ? 'tt-chk-warn' : ($e->status === 'rejected' ? 'tt-chk-no' : 'tt-chk-pass'))) .
        ($e->decisionnote !== '' && $e->decisionnote !== null ? html_writer::div(html_writer::div($L('eth_conditions'), 'tt-label') . nl2br(s($e->decisionnote)), 'mt-2') : ''), 'tt-card mb-3');
    if ($candecide && $e->status === 'submitted') {
        $opts = ['approved' => $L('eth_st_approved'), 'conditional' => $L('eth_st_conditional'), 'rejected' => $L('eth_st_rejected')];
        echo html_writer::tag('form', html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'decide']) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]) .
            html_writer::div(html_writer::select($opts, 'decision', 'approved', false, ['class' => 'custom-select w-auto d-inline-block mr-2', 'aria-label' => $L('eth_decision')]) .
                html_writer::tag('label', $L('permit_expiry') . ' ', ['class' => 'mr-1']) . html_writer::empty_tag('input', ['type' => 'date', 'name' => 'expiry', 'class' => 'form-control w-auto d-inline-block']), 'mb-2') .
            html_writer::tag('textarea', '', ['name' => 'note', 'rows' => 5, 'class' => 'form-control mb-2', 'placeholder' => $L('eth_note_ph'), 'aria-label' => $L('eth_note_ph')]) .
            html_writer::tag('button', $L('eth_decide'), ['type' => 'submit', 'class' => 'btn btn-primary']), ['method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'tt-card']);
    }
    echo html_writer::div(html_writer::link(research::url((int)$proj->id, 'ethics'), $L('ws_ethics'), ['class' => 'btn btn-outline-secondary mt-3']));
}
echo $OUTPUT->footer();
