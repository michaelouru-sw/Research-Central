<?php
// Staff: invite and manage external examiners for a thesis.
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\annotate;
use local_researchcentral\local\examiner;
use local_researchcentral\local\portal;
use local_researchcentral\local\research;

$id = required_param('id', PARAM_INT);
\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$proj = $DB->get_record('local_researchcentral_proj', ['id' => $id], '*', MUST_EXIST);
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/researchcentral/examiners.php', ['id' => $id]));
portal::require_view($proj);
if (!annotate::is_staff($proj)) {
    throw new moodle_exception('nopermissions', 'error', '', 'examiners');
}
$L = fn(string $k, $a = null) => get_string($k, 'local_researchcentral', $a);
$dts = get_string('strftimedatetimeshort', 'langconfig');
$do = optional_param('do', '', PARAM_ALPHA);
if ($do !== '' && data_submitted()) {
    require_sesskey();
    $eid = optional_param('eid', 0, PARAM_INT);
    if ($do === 'invite') {
        $new = examiner::invite($proj, optional_param('pms', 0, PARAM_INT), optional_param('name', '', PARAM_TEXT), optional_param('email', '', PARAM_EMAIL),
            optional_param('inst', '', PARAM_TEXT), optional_param('days', 30, PARAM_INT));
        redirect($PAGE->url, $new ? ($L(examiner::$mailed ? 'exam_invited' : 'exam_mail_failed')) : $L('exam_invite_bad'), null,
            ($new && examiner::$mailed) ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
    }
    $ex = $eid ? $DB->get_record('local_researchcentral_exam', ['id' => $eid, 'projid' => $id]) : null;
    if ($ex && $do === 'revoke') {
        examiner::revoke($proj, $eid);
    } else if ($ex && $do === 'extend') {
        examiner::extend($proj, $eid, 30);
    } else if ($ex && $do === 'resend') {
        $ok = examiner::send_invite($proj, $ex);
        redirect($PAGE->url, $ok ? $L('exam_resent') : $L('exam_mail_failed'), null, $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
    }
    redirect($PAGE->url);
}
$PAGE->set_pagelayout('report');
$PAGE->set_title($L('exam_manage'));
$PAGE->set_heading($L('pluginname'));
echo $OUTPUT->header();
echo html_writer::link(research::url($id, 'viva'), '← ' . $L('ws_viva'), ['class' => 'tt-back']);
echo $OUTPUT->heading($L('exam_manage'), 3);
echo html_writer::div($L('exam_manage_help'), 'tt-sub mb-3');
$stages = $DB->get_records_sql('SELECT m.id, s.name FROM {local_researchcentral_pms} m JOIN {local_researchcentral_stage} s ON s.id = m.stageid WHERE m.projid = ? ORDER BY s.sortorder, s.id', [$id]);
$opts = [];
foreach ($stages as $s) {
    $opts[$s->id] = format_string($s->name);
}
$f = fn(string $n, string $ph, string $type = 'text') => html_writer::empty_tag('input', ['type' => $type, 'name' => $n, 'class' => 'form-control d-inline-block w-auto mr-2 mb-2', 'placeholder' => $ph, 'aria-label' => $ph, 'required' => $n !== 'inst' ? 'required' : null]);
echo html_writer::tag('form', html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'invite']) .
    $f('name', $L('exam_name')) . $f('email', $L('exam_email'), 'email') . $f('inst', $L('exam_inst')) .
    html_writer::select($opts, 'pms', array_key_last($opts), false, ['class' => 'custom-select w-auto d-inline-block mr-2 mb-2', 'aria-label' => $L('stage')]) .
    html_writer::empty_tag('input', ['type' => 'number', 'name' => 'days', 'value' => 30, 'min' => 1, 'max' => 180, 'class' => 'form-control d-inline-block mr-2 mb-2', 'style' => 'width:90px', 'aria-label' => $L('exam_days')]) .
    html_writer::tag('button', $L('exam_invite'), ['type' => 'submit', 'class' => 'btn btn-primary mb-2']), ['method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'tt-card mb-3']);
$rows = examiner::for_project($id);
if (!$rows) {
    echo html_writer::div($L('exam_none'), 'tt-empty');
}
$btn = fn(int $eid, string $do, string $label, string $cls = 'btn-outline-secondary') => html_writer::tag('form', html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => $do]) . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'eid', 'value' => $eid]) .
    html_writer::tag('button', $label, ['type' => 'submit', 'class' => 'btn btn-sm mr-1 ' . $cls]), ['method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'd-inline']);
foreach ($rows as $ex) {
    $expired = (int)$ex->expiry && (int)$ex->expiry < time();
    $st = $ex->status === 'invited' && $expired ? 'expired' : $ex->status;
    $link = $ex->status === 'revoked' ? '' : html_writer::div(html_writer::tag('input', '', ['type' => 'text', 'readonly' => 'readonly', 'class' => 'form-control form-control-sm', 'value' => examiner::url($ex)->out(false),
        'onfocus' => 'this.select()', 'aria-label' => $L('exam_link')]), 'mt-2');
    $nc = $DB->count_records('local_researchcentral_ann', ['userid' => -(int)$ex->id, 'parentid' => 0]);
    echo html_writer::div(
        html_writer::div(html_writer::span(s($ex->name), 'tt-strong') . ' ' . html_writer::span($L('exam_st_' . $st), 'tt-chk ' . ($st === 'submitted' ? 'tt-chk-pass' : ($st === 'revoked' || $st === 'expired' ? 'tt-chk-no' : 'tt-chk-warn'))), 'mb-1') .
        html_writer::div(s($ex->email) . ($ex->institution !== '' ? ' · ' . s($ex->institution) : '') . ' · ' . format_string($stages[$ex->pmsid]->name ?? '-'), 'tt-sub') .
        html_writer::div($L('exam_invited_on', userdate($ex->timeinvited, $dts)) . ' · ' . $L('exam_expires', userdate($ex->expiry, $dts)) .
            ($ex->timefirstseen ? ' · ' . $L('exam_opened', (object)['when' => userdate($ex->timefirstseen, $dts), 'n' => $ex->hits]) : ' · ' . $L('exam_notopened')), 'tt-sub') .
        ($nc ? html_writer::div($L('exam_comments_n', $nc), 'tt-sub') : '') .
        ($ex->status === 'submitted' ? html_writer::div(html_writer::div($L('viva_out_' . $ex->recommendation), 'tt-strong') . nl2br(s($ex->report)) .
            (trim((string)$ex->confidential) !== '' ? html_writer::div('🔒 ' . $L('exam_confidential') . ': ' . nl2br(s($ex->confidential)), 'mt-2 tt-sub') : ''), 'mt-2 p-2 border rounded') : '') .
        $link . html_writer::div(($ex->status !== 'revoked' ? $btn((int)$ex->id, 'resend', $L('exam_resend')) . $btn((int)$ex->id, 'extend', $L('exam_extend')) . $btn((int)$ex->id, 'revoke', $L('exam_revoke'), 'btn-outline-danger') : ''), 'mt-2'),
        'tt-card mb-3');
}
echo $OUTPUT->footer();
