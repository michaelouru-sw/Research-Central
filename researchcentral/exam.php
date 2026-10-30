<?php
// External examiner portal: no Moodle account. Access is by a private, expiring link scoped to one stage of one thesis.
define('NO_MOODLE_COOKIES', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_researchcentral\local\annotate;
use local_researchcentral\local\examiner;

$token = optional_param('t', '', PARAM_ALPHANUM);
$verid = optional_param('ver', 0, PARAM_INT);
$dl = optional_param('dl', 0, PARAM_INT);
$do = optional_param('do', '', PARAM_ALPHA);

@header('Referrer-Policy: no-referrer');
@header('X-Robots-Tag: noindex, nofollow');
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/researchcentral/exam.php', ['t' => $token]));
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('exam_title', 'local_researchcentral'));
$L = fn(string $k, $a = null) => get_string($k, 'local_researchcentral', $a);

$ex = examiner::by_token($token);
if (!$ex) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification($L('exam_invalid'), 'error');
    echo $OUTPUT->footer();
    exit;
}
$proj = $DB->get_record('local_researchcentral_proj', ['id' => $ex->projid], '*', MUST_EXIST);
$m = $DB->get_record('local_researchcentral_pms', ['id' => $ex->pmsid], '*', MUST_EXIST);
$stage = $DB->get_record('local_researchcentral_stage', ['id' => $m->stageid]);
$student = core_user::get_user($proj->userid);
$vers = examiner::versions($ex);
$locked = $ex->status === 'submitted';
examiner::touch($ex);
$dts = get_string('strftimedatetimeshort', 'langconfig');

// Download one submission file (only this stage's versions).
if ($dl) {
    if (!isset($vers[$dl])) {
        throw new moodle_exception('nopermissions', 'error');
    }
    $files = get_file_storage()->get_area_files(context_system::instance()->id, 'local_researchcentral', 'submission', $dl, 'filename', false);
    if ($files) {
        send_stored_file(reset($files), 0, 0, true);
    }
    throw new moodle_exception('filenotfound', 'error');
}

$self = new moodle_url('/local/researchcentral/exam.php', ['t' => $token] + ($verid ? ['ver' => $verid] : []));
$tokenfield = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 't', 'value' => $token]);

// Actions (before output). The private link is the credential, so no session key is needed.
if ($do !== '' && data_submitted() && !$locked) {
    if ($do === 'comment' && isset($vers[$verid])) {
        $paras = annotate::paragraphs($verid);
        $idx = optional_param('para', -1, PARAM_INT);
        if ($paras !== null && isset($paras[$idx])) {
            examiner::add_comment($ex, $vers[$verid], $idx, annotate::hash($paras[$idx]), optional_param('quote', '', PARAM_TEXT), optional_param('body', '', PARAM_TEXT));
        }
        redirect(new moodle_url($self, [], 'p' . $idx));
    }
    if ($do === 'delcomment') {
        examiner::delete_comment($ex, optional_param('cid', 0, PARAM_INT));
        redirect($self);
    }
    if ($do === 'report') {
        $ok = examiner::submit_report($ex, optional_param('rec', '', PARAM_ALPHA), optional_param('report', '', PARAM_TEXT), optional_param('confidential', '', PARAM_TEXT));
        redirect($PAGE->url, $L($ok ? 'exam_report_thanks' : 'exam_report_incomplete'), null, $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading($L('exam_title'), 3);
echo html_writer::div(html_writer::div($L('exam_guide_h'), 'tt-label') . html_writer::tag('ol',
    html_writer::tag('li', $L('exam_guide_1')) . html_writer::tag('li', $L('exam_guide_2')) . html_writer::tag('li', $L('exam_guide_3')), ['class' => 'mb-0 pl-3']) .
    '', 'tt-card tt-info mb-3');
echo html_writer::tag('script', json_encode(['draft_restored' => $L('ux_saved_draft'), 'discard' => $L('ux_discard')], JSON_HEX_TAG | JSON_HEX_AMP), ['type' => 'application/json', 'id' => 'tt-strings']);
$PAGE->requires->js(new moodle_url('/local/researchcentral/js/tt.js'));
echo html_writer::div(html_writer::div(s($student ? fullname($student) : '-') . ' · ' . format_string($stage->name), 'tt-strong') .
    html_writer::div(format_string($proj->title), 'text-muted') .
    html_writer::div($L('exam_for', (object)['name' => s($ex->name), 'date' => userdate($ex->expiry, $dts)]), 'text-muted small'), 'p-3 border rounded mb-3');
if ($locked) {
    echo $OUTPUT->notification($L('exam_locked', userdate($ex->timesubmitted, $dts)), 'info');
}

if ($verid && isset($vers[$verid])) {
    $paras = annotate::paragraphs($verid);
    $mine = [];
    foreach (examiner::comments($ex, $verid) as $c) {
        $mine[(int)$c->paraidx][] = $c;
    }
    echo html_writer::link(new moodle_url('/local/researchcentral/exam.php', ['t' => $token]), '← ' . $L('exam_back'), ['class' => 'd-block mb-3']);
    echo $OUTPUT->heading($L('submission_v', $vers[$verid]->version), 4);
    if ($paras === null) {
        echo $OUTPUT->notification($L('read_unreadable'), 'warning');
    } else {
        foreach ($paras as $i => $text) {
            $out = html_writer::div(html_writer::span($i + 1, 'text-muted mr-2') . s($text), 'py-2');
            foreach ($mine[$i] ?? [] as $c) {
                $del = $locked ? '' : html_writer::tag('form', $tokenfield . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'delcomment']) .
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cid', 'value' => $c->id]) . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ver', 'value' => $verid]) .
                    html_writer::tag('button', $L('delete'), ['type' => 'submit', 'class' => 'btn btn-sm btn-link text-danger']), ['method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'd-inline']);
                $out .= html_writer::div(($c->quote !== '' ? html_writer::div('“' . s($c->quote) . '”', 'text-muted small') : '') . nl2br(s($c->body)) . ' ' . $del, 'ml-4 p-2 mb-2 border-left bg-light');
            }
            if (!$locked) {
                $out .= html_writer::tag('details', html_writer::tag('summary', '💬 ' . $L('ann_add'), ['class' => 'small text-primary', 'style' => 'cursor:pointer']) .
                    html_writer::tag('form', $tokenfield . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'comment']) .
                        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ver', 'value' => $verid]) . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'para', 'value' => $i]) .
                        html_writer::empty_tag('input', ['type' => 'text', 'name' => 'quote', 'maxlength' => 250, 'class' => 'form-control mb-1', 'placeholder' => $L('ann_quote_ph'), 'aria-label' => $L('ann_quote_ph')]) .
                        html_writer::tag('textarea', '', ['name' => 'body', 'rows' => 3, 'required' => 'required', 'class' => 'form-control mb-1', 'aria-label' => $L('ann_add')]) .
                        html_writer::tag('button', $L('ann_send'), ['type' => 'submit', 'class' => 'btn btn-sm btn-primary']), ['method' => 'post', 'action' => $PAGE->url->out(false)]), ['class' => 'ml-4']);
            }
            echo html_writer::div($out, 'border-bottom', ['id' => 'p' . $i]);
        }
    }
} else {
    echo html_writer::tag('h5', $L('exam_documents'));
    foreach ($vers as $v) {
        $n = $DB->count_records('local_researchcentral_ann', ['verid' => $v->id, 'userid' => -(int)$ex->id, 'parentid' => 0]);
        echo html_writer::div(html_writer::span($L('submission_v', $v->version), 'tt-strong mr-2') . userdate($v->timecreated, $dts) . ' ' .
            html_writer::link(new moodle_url($self, ['ver' => $v->id]), $L('exam_read') . ($n ? ' (' . $n . ')' : ''), ['class' => 'btn btn-sm btn-primary ml-2']) . ' ' .
            html_writer::link(new moodle_url($self, ['dl' => $v->id]), '⬇ ' . $L('exam_download'), ['class' => 'btn btn-sm btn-outline-secondary']), 'p-2 border rounded mb-2');
    }
    if (!$vers) {
        echo html_writer::div($L('exam_nodocs'), 'text-muted');
    }
    echo html_writer::tag('h5', $L('exam_report'), ['class' => 'mt-4']);
    if ($locked) {
        echo html_writer::div(html_writer::div($L('viva_out_' . $ex->recommendation), 'tt-strong') . nl2br(s($ex->report)), 'p-3 border rounded');
    } else {
        $opts = [];
        foreach (\local_researchcentral\local\research::VIVA_OUTCOMES as $o) {
            $opts[$o] = $L('viva_out_' . $o);
        }
        echo html_writer::tag('form', $tokenfield . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'report']) .
            html_writer::tag('label', $L('viva_recommendation'), ['class' => 'font-weight-bold']) . html_writer::div(html_writer::select($opts, 'rec', '', ['' => '-'], ['class' => 'custom-select w-auto', 'required' => 'required']), 'mb-2') .
            html_writer::tag('label', $L('viva_report'), ['class' => 'font-weight-bold']) . html_writer::tag('textarea', '', ['name' => 'report', 'rows' => 10, 'required' => 'required', 'class' => 'form-control mb-2']) .
            html_writer::tag('label', $L('exam_confidential'), ['class' => 'font-weight-bold']) . html_writer::tag('textarea', '', ['name' => 'confidential', 'rows' => 3, 'class' => 'form-control mb-2']) .
            html_writer::div($L('exam_report_warn'), 'text-muted small mb-2') .
            html_writer::tag('button', $L('exam_send'), ['type' => 'submit', 'class' => 'btn btn-primary']), ['method' => 'post', 'action' => $PAGE->url->out(false)]);
    }
}
echo $OUTPUT->footer();
