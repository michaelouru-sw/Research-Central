<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\portal;
use local_researchcentral\local\review;
use local_researchcentral\output\ui;
use local_researchcentral\form\decision_form;
use local_researchcentral\form\review_form;
use local_researchcentral\form\message_form;
use local_researchcentral\form\extension_form;
use local_researchcentral\form\extdecision_form;
use local_researchcentral\form\reassign_form;
use local_researchcentral\form\cosup_form;

$id = required_param('id', PARAM_INT);
$pmsid = optional_param('pms', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$proj = $DB->get_record('local_researchcentral_proj', ['id' => $id], '*', MUST_EXIST);
portal::require_view($proj);
$context = context_system::instance();
$isstudent = (int)$proj->userid === (int)$USER->id;
$issupervisor = portal::is_project_supervisor($proj);
$iscoord = portal::can_manage_project($proj);

$url = new moodle_url('/local/researchcentral/project.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('pluginname', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

$prog = $DB->get_record('local_researchcentral_prog', ['id' => $proj->progid], '*', MUST_EXIST);
$student = core_user::get_user($proj->userid);
$team = portal::supervisor_ids($proj);
$namecache = [];
$nameof = function (int $u) use (&$namecache): string {
    if (!isset($namecache[$u])) {
        $x = core_user::get_user($u);
        $namecache[$u] = $x ? fullname($x) : '-';
    }
    return $namecache[$u];
};
$supervisor = core_user::get_user($proj->supervisorid);
$ms = portal::milestones([$id])[$id] ?? [];
$byid = [];
foreach ($ms as $m) {
    $byid[$m->id] = $m;
}
$postgrad = in_array($prog->level, portal::POSTGRAD, true);

$form = null;
$formtitle = '';
$redir = fn(string $msg, string $type = \core\output\notification::NOTIFY_SUCCESS) => redirect($url, $msg, null, $type);

// Discussion post (student, supervisor or coordinator).
$msgform = new message_form($url . '#discussion', ['id' => $id]);
if ($data = $msgform->get_data()) {
    portal::post_message($proj, trim($data->body));
    redirect(new moodle_url($url, [], 'discussion'));
}

// Coordinator: hand over the lead role, manage the supervision team.
$reform = $cosupform = null;
if ($iscoord) {
    $reform = new reassign_form($url, ['id' => $id, 'userid' => $proj->userid]);
    if ($data = $reform->get_data()) {
        portal::reassign($proj, (int)$data->supervisorid);
        $redir(get_string('reassigned_ok', 'local_researchcentral'));
    }
    if ($postgrad) {
        $cosupform = new cosup_form($url, ['id' => $id]);
        if ($data = $cosupform->get_data()) {
            try {
                portal::add_cosupervisor($proj, (int)$data->cosupid);
                $redir(get_string('cosup_added', 'local_researchcentral'));
            } catch (moodle_exception $e) {
                $redir($e->getMessage(), \core\output\notification::NOTIFY_ERROR);
            }
        }
    }
    if (in_array($action, ['removecosup', 'makelead'], true)) {
        require_sesskey();
        $uid = required_param('user', PARAM_INT);
        if ($action === 'removecosup') {
            portal::remove_cosupervisor($proj, $uid);
        } else {
            portal::make_lead($proj, $uid);
        }
        $redir(get_string('team_updated', 'local_researchcentral'));
    }
}

// Extension decisions: supervisor endorses (first eye), coordinator confirms (second eye).
if ($action === 'extdecide') {
    $ext = $DB->get_record('local_researchcentral_ext', ['id' => required_param('ext', PARAM_INT)], '*', MUST_EXIST);
    $step = optional_param('step', 'endorse', PARAM_ALPHA) === 'confirm' ? 'confirm' : 'endorse';
    if (!isset($byid[$ext->pmsid])) {
        throw new moodle_exception('invalidrecord', 'error');
    }
    $allowed = $step === 'endorse' ? ($ext->status === 'pending' && $issupervisor)
        : ($ext->status === 'endorsed' && $iscoord && (int)$ext->recommendedby !== (int)$USER->id && (int)$ext->userid !== (int)$USER->id);
    if (!$allowed) {
        throw new moodle_exception($ext->status === 'endorsed' && $iscoord ? 'ext_secondeye_same' : 'nopermissions', $ext->status === 'endorsed' && $iscoord ? 'local_researchcentral' : 'error');
    }
    $summary = get_string('ext_summary', 'local_researchcentral', (object)['stage' => format_string($byid[$ext->pmsid]->stagename),
        'date' => userdate($ext->requestdate, get_string('strftimedatefullshort', 'langconfig')), 'reason' => $ext->reason]) .
        ($ext->recommendnote ? ' ' . get_string('ext_endorsed_note', 'local_researchcentral', s($ext->recommendnote)) : '');
    $form = new extdecision_form($url, ['id' => $id, 'ext' => $ext->id, 'step' => $step, 'summary' => s($summary)]);
    $formtitle = get_string($step === 'endorse' ? 'ext_step_endorse' : 'ext_step_confirm', 'local_researchcentral');
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        try {
            if ($step === 'endorse') {
                portal::ext_endorse($proj, $ext, (bool)$data->verdict, trim($data->note));
            } else {
                portal::ext_confirm($proj, $ext, (bool)$data->verdict, trim($data->note));
            }
            $redir(get_string('ext_decided', 'local_researchcentral'));
        } catch (moodle_exception $e) {
            $redir($e->getMessage(), \core\output\notification::NOTIFY_ERROR);
        }
    }
    $pmsid = 0;
}

if ($pmsid && !in_array($action, ['message', 'reassign', 'extdecide'], true)) {
    $m = $byid[$pmsid] ?? null;
    if (!$m) {
        throw new moodle_exception('invalidrecord', 'error');
    }
    if ($action === 'submit') {
        redirect(new moodle_url('/local/researchcentral/submit.php', ['pms' => $pmsid]));
    } else if ($action === 'decide') {
        // A supervisor's review. The stage is decided once every supervisor of the project has reviewed and they agree.
        if (!review::can_review($proj, $m)) {
            throw new moodle_exception('nopermissions', 'error', '', 'review');
        }
        $round = review::round((int)$m->id);
        $mine = review::active((int)$m->id, $round)[(int)$USER->id] ?? null;
        $form = new review_form($url, ['id' => $id, 'pms' => $pmsid, 'maxgrade' => (int)$m->maxgrade]);
        if ($form->is_cancelled()) {
            redirect(new moodle_url('/local/researchcentral/stage.php', ['id' => $pmsid]));
        }
        if ($data = $form->get_data()) {
            $g = isset($data->grade) && trim((string)$data->grade) !== '' ? (float)str_replace(',', '.', $data->grade) : null;
            $result = review::submit_review($proj, $m, $data->recommendation, $g, trim($data->comment), trim($data->confidential ?? ''), (int)$data->attachments);
            redirect(new moodle_url('/local/researchcentral/stage.php', ['id' => $pmsid]), get_string('review_result_' . $result, 'local_researchcentral'), null,
                $result === 'conflict' ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS);
        }
        if (!$form->is_submitted()) {
            $draftid = file_get_submitted_draft_itemid('attachments');
            file_prepare_draft_area($draftid, $context->id, 'local_researchcentral', 'feedback', $mine ? (int)$mine->id : null,
                ['subdirs' => 0, 'maxfiles' => 10]);
            $form->set_data((object)['attachments' => $draftid, 'recommendation' => $mine->recommendation ?? 'approve',
                'grade' => ($mine && $mine->grade !== null) ? format_float($mine->grade, 2, true, true) : '',
                'comment' => \local_researchcentral\local\annotate::queued_text((int)$m->id, (int)$USER->id, (string)($mine->comment ?? '')), 'confidential' => $mine->confidential ?? '']);
        }
        $formtitle = get_string('review_write', 'local_researchcentral');
    } else if ($action === 'override') {
        // Coordinator breaks a deadlock or decides in the absence of a reviewer; the letter records it.
        if (!$iscoord || !review::open_for_review($m)) {
            throw new moodle_exception('nopermissions', 'error', '', 'override');
        }
        $form = new decision_form($url, ['id' => $id, 'pms' => $pmsid, 'maxgrade' => (int)$m->maxgrade]);
        if ($form->is_cancelled()) {
            redirect(new moodle_url('/local/researchcentral/stage.php', ['id' => $pmsid]));
        }
        if ($data = $form->get_data()) {
            $g = isset($data->grade) && trim((string)$data->grade) !== '' ? (float)str_replace(',', '.', $data->grade) : null;
            portal::decide($proj, $m, $data->decision, trim($data->note), $g);
            redirect(new moodle_url('/local/researchcentral/stage.php', ['id' => $pmsid]), get_string('decision_saved', 'local_researchcentral'));
        }
        $formtitle = get_string('review_override', 'local_researchcentral');
    } else if ($action === 'confirm') {
        require_sesskey();
        if (!review::can_review($proj, $m) || $m->needsupload || $m->maxgrade) {
            throw new moodle_exception('nopermissions', 'error', '', 'confirm');
        }
        $result = review::submit_review($proj, $m, 'approve', null, '', '', 0);
        redirect($url, get_string('review_result_' . $result, 'local_researchcentral'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else if ($action === 'extend') {
        if (!$isstudent || portal::ext_blocked($proj, $m) || !in_array($m->status, [portal::PENDING, portal::REVISION], true)) {
            throw new moodle_exception('nopermissions', 'error', '', 'extend');
        }
        $form = new extension_form($url, ['id' => $id, 'pms' => $pmsid]);
        if ($form->is_cancelled()) {
            redirect($url);
        }
        if ($data = $form->get_data()) {
            portal::request_extension($proj, $m, (int)$data->requestdate, trim($data->reason));
            $redir(get_string('ext_sent', 'local_researchcentral'));
        }
    }
}

// Versions, reviews, extensions.
$vers = [];
$reports = [];
$exts = [];
$panelcount = [];
if ($ms) {
    [$in, $params] = $DB->get_in_or_equal(array_keys($byid), SQL_PARAMS_NAMED);
    foreach ($DB->get_records_select('local_researchcentral_ver', "pmsid $in", $params, 'version DESC') as $v) {
        $vers[$v->pmsid][] = $v;
    }
    foreach ($DB->get_records('local_researchcentral_report', ['projid' => $id], 'id') as $r) {
        $reports[$r->verid] = $r;
    }
    foreach ($DB->get_records_select('local_researchcentral_ext', "pmsid $in", $params, 'id DESC') as $e) {
        $exts[$e->pmsid][] = $e;
    }
}
$fs = get_file_storage();

echo $OUTPUT->header();
$ttws = html_writer::link(\local_researchcentral\local\research::url((int)$id), get_string('ws_title', 'local_researchcentral'), ['class' => 'btn btn-outline-primary']);
echo ui::actionbar([$ttws]);
echo html_writer::link(new moodle_url('/local/researchcentral/portal.php'), '← ' . get_string('back', 'local_researchcentral'), ['class' => 'tt-back']);

// Header card.
$done = count(array_filter($ms, fn($m) => $m->status === portal::APPROVED));
$p = (object)['done' => $done, 'total' => count($ms), 'overdue' => 0, 'review' => 0, 'revision' => 0];
foreach ($ms as $m) {
    $p->overdue += portal::is_overdue($m) ? 1 : 0;
    $p->review += $m->status === portal::SUBMITTED ? 1 : 0;
    $p->revision += $m->status === portal::REVISION ? 1 : 0;
}
$overall = portal::overall($ms);
$supnames = '';
foreach ($team as $i => $uid) {
    $u = core_user::get_user($uid);
    $supnames .= html_writer::div(($u ? s(fullname($u)) : '-') . ' ' .
        html_writer::span(get_string($i === 0 ? 'lead_supervisor' : 'co_supervisor', 'local_researchcentral'), 'tt-chk ' . ($i === 0 ? 'tt-chk-pass' : 'tt-chk-warn')) .
        ($u ? html_writer::div(s($u->email), 'tt-sub') : ''), 'tt-strong');
}
$meta = html_writer::div(
    html_writer::div(get_string('student', 'local_researchcentral'), 'tt-label') . html_writer::div(ui::user_line($student), 'tt-strong') .
    html_writer::div($student ? s($student->email) : '', 'tt-sub'), 'tt-meta') .
    html_writer::div(
    html_writer::div(get_string($postgrad ? 'supervision_team' : 'supervisor', 'local_researchcentral'), 'tt-label') . $supnames, 'tt-meta') .
    html_writer::div(
    html_writer::div(get_string('programme', 'local_researchcentral'), 'tt-label') . html_writer::div(format_string($prog->name), 'tt-strong') .
    html_writer::div(s(portal::school_name($prog)) . ' · ' . get_string('level_' . ($prog->level ?: 'masters'), 'local_researchcentral'), 'tt-sub'), 'tt-meta') .
    html_writer::div(
    html_writer::div(get_string('startdate', 'local_researchcentral'), 'tt-label') .
    html_writer::div(userdate($proj->startdate, get_string('strftimedatefullshort', 'langconfig')), 'tt-strong'), 'tt-meta') .
    ($overall ? html_writer::div(
    html_writer::div(get_string('overall_mark', 'local_researchcentral'), 'tt-label') .
    html_writer::div(format_float($overall['percent'], 1) . '%', 'tt-strong') .
    html_writer::div(get_string('marked_of', 'local_researchcentral', (object)['a' => $overall['marked'], 'b' => $overall['graded']]), 'tt-sub'), 'tt-meta') : '');
echo html_writer::div(
    html_writer::div(format_string($proj->title ?: get_string('untitled', 'local_researchcentral')), 'tt-title') .
    html_writer::div(ui::pill(portal::state($p)) . ' ' . ui::bar($done, count($ms)), 'mb-3') . $meta, 'tt-card');
echo ui::pipeline($ms);

if ($form) {
    echo html_writer::div(html_writer::tag('h4', $formtitle ?: ($pmsid ? format_string($byid[$pmsid]->stagename) : '')), 'mt-3');
    if ($pmsid) {
        echo html_writer::div(format_string($byid[$pmsid]->stagename), 'tt-sub mb-2');
    }
    if (in_array($action, ['decide', 'override'], true) && !empty($vers[$pmsid])) {
        $latest = $vers[$pmsid][0];
        foreach ($fs->get_area_files($context->id, 'local_researchcentral', 'submission', $latest->id, 'filename', false) as $f) {
            $u = moodle_url::make_pluginfile_url($context->id, 'local_researchcentral', 'submission', $latest->id, '/', $f->get_filename(), true);
            echo html_writer::div(get_string('review_file', 'local_researchcentral') . ' ' . html_writer::link($u, s($f->get_filename())), 'mb-2');
        }
        echo html_writer::div(html_writer::link(new moodle_url('/local/researchcentral/stage.php', ['id' => $pmsid]),
            get_string('stage_history', 'local_researchcentral')), 'mb-3');
    }
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

// Stage table.
$t = new html_table();
$t->attributes['class'] = 'tt-table';
$t->head = [get_string('stage', 'local_researchcentral'), get_string('due', 'local_researchcentral'),
    get_string('status', 'local_researchcentral'), get_string('marks', 'local_researchcentral'),
    get_string('submissions', 'local_researchcentral'), ''];
$exttxt = function (stdClass $e) use ($url, $issupervisor, $iscoord, $USER): string {
    $when = userdate($e->requestdate, get_string('strftimedatefullshort', 'langconfig'));
    $line = html_writer::span(get_string('ext_stage_' . $e->status, 'local_researchcentral'), 'tt-chk ' . ($e->status === 'approved' ? 'tt-chk-pass' : ($e->status === 'declined' ? 'tt-chk-fail' : 'tt-chk-warn'))) .
        ' ' . $when . ': ' . s($e->reason);
    if ($e->recommendnote || $e->recommendedby) {
        $by = $e->recommendedby ? fullname(core_user::get_user($e->recommendedby)) : '';
        $line .= html_writer::div(get_string('ext_first_eye', 'local_researchcentral', s($by)) . ($e->recommendnote ? ': ' . s($e->recommendnote) : ''), 'tt-sub');
    }
    if ($e->confirmedby) {
        $line .= html_writer::div(get_string('ext_second_eye', 'local_researchcentral', s(fullname(core_user::get_user($e->confirmedby)))) .
            ($e->confirmnote ? ': ' . s($e->confirmnote) : ''), 'tt-sub');
    }
    $btn = '';
    if ($e->status === 'pending' && $issupervisor) {
        $btn = html_writer::link(new moodle_url($url, ['action' => 'extdecide', 'ext' => $e->id, 'step' => 'endorse']),
            get_string('ext_review_request', 'local_researchcentral'), ['class' => 'btn btn-sm btn-primary mt-1']);
    } else if ($e->status === 'endorsed' && $iscoord) {
        $btn = ((int)$e->recommendedby === (int)$USER->id)
            ? html_writer::div(get_string('ext_secondeye_wait', 'local_researchcentral'), 'tt-sub')
            : html_writer::link(new moodle_url($url, ['action' => 'extdecide', 'ext' => $e->id, 'step' => 'confirm']),
                get_string('ext_confirm_request', 'local_researchcentral'), ['class' => 'btn btn-sm btn-primary mt-1']);
    }
    return html_writer::div($line . $btn, 'tt-feedback');
};
foreach ($ms as $m) {
    $state = portal::is_overdue($m) ? 'overdue' : $m->status;
    $latest = $vers[$m->id][0] ?? null;
    $list = '';
    if ($latest) {
        $files = $fs->get_area_files($context->id, 'local_researchcentral', 'submission', $latest->id, 'filename', false);
        $f = $files ? reset($files) : null;
        $link = $f ? html_writer::link(moodle_url::make_pluginfile_url($context->id, 'local_researchcentral', 'submission',
            $latest->id, '/', $f->get_filename(), true), s($f->get_filename())) : '-';
        $rep = '';
        if (!empty($reports[$latest->id])) {
            $r = $reports[$latest->id];
            $rep = ' ' . html_writer::link(new moodle_url('/local/researchcentral/preport.php', ['id' => $r->id]),
                get_string('check_badge', 'local_researchcentral', get_string('overall_' . $r->status, 'local_researchcentral')),
                ['class' => 'tt-chk tt-chk-' . $r->status]);
        }
        $late = ($m->duedate && $latest->timecreated > $m->duedate) ? ' ' . html_writer::span(get_string('late', 'local_researchcentral'), 'tt-chk tt-chk-fail') : '';
        $flags = '';
        {
            $dl = \local_researchcentral\local\receipts::for_items('submission', [(int)$latest->id])[(int)$latest->id] ?? [];
            $flags = \local_researchcentral\local\receipts::download_flags($proj, $team, $dl, $nameof);
        }
        $list .= html_writer::div('v' . (int)$latest->version . ' · ' . $link . ' · ' . userdate($latest->timecreated, get_string('strftimedatetimeshort', 'langconfig')) . $late . $rep . $flags, 'tt-ver');
    }
    $nrounds = $DB->count_records('local_researchcentral_decision', ['pmsid' => $m->id]);
    if ($latest || $nrounds || $m->status === portal::SUBMITTED) {
        $list .= html_writer::div(html_writer::link(new moodle_url('/local/researchcentral/stage.php', ['id' => $m->id]),
            get_string('stage_history', 'local_researchcentral') . ($nrounds ? ' (' . get_string('rounds_n', 'local_researchcentral', $nrounds) . ')' : '')), 'tt-sub mt-1');
    }
    foreach ($exts[$m->id] ?? [] as $i => $e) {
        if ($i < 2 || in_array($e->status, ['pending', 'endorsed'], true)) {
            $list .= $exttxt($e);
        }
    }
    $reviewed = review::open_for_review($m) ? count(review::active((int)$m->id)) : 0;
    $btns = '';
    if ($isstudent && in_array($m->status, [portal::PENDING, portal::REVISION], true)) {
        if (!($why = portal::ext_blocked($proj, $m))) {
            $btns .= html_writer::link(new moodle_url($url, ['pms' => $m->id, 'action' => 'extend']),
                get_string('ext_request', 'local_researchcentral'), ['class' => 'btn btn-sm btn-outline-secondary mr-1']);
        } else if ($why !== 'ext_open') {
            $list .= html_writer::div(get_string($why, 'local_researchcentral'), 'tt-sub');
        }
    }
    if ($isstudent && $m->needsupload && in_array($m->status, [portal::PENDING, portal::REVISION], true)) {
        $btns .= html_writer::link(new moodle_url('/local/researchcentral/submit.php', ['pms' => $m->id]),
            $m->status === portal::REVISION ? get_string('upload_revision', 'local_researchcentral') : get_string('upload', 'local_researchcentral'),
            ['class' => 'btn btn-sm btn-primary']);
    }
    if (review::can_review($proj, $m)) {
        $mine = isset(review::active((int)$m->id)[(int)$USER->id]);
        if (!$m->needsupload && !$m->maxgrade && !$mine) {
            $btns .= html_writer::link(new moodle_url($url, ['pms' => $m->id, 'action' => 'confirm', 'sesskey' => sesskey()]),
                get_string('confirm_stage', 'local_researchcentral'), ['class' => 'btn btn-sm btn-outline-primary mr-1']);
        }
        $btns .= html_writer::link(new moodle_url($url, ['pms' => $m->id, 'action' => 'decide']),
            get_string($mine ? 'review_edit' : 'review', 'local_researchcentral'), ['class' => 'btn btn-sm btn-primary']);
    }
    $t->data[] = [
        html_writer::div(format_string($m->stagename), 'tt-strong') . (!$m->needsupload ? html_writer::div(get_string('stage_manual', 'local_researchcentral'), 'tt-sub') : ''),
        $m->duedate ? userdate($m->duedate, get_string('strftimedatefullshort', 'langconfig')) : '-',
        ui::pill($state, $state === 'overdue' ? null : review::status_label($m, $reviewed, count($team))),
        ($m->maxgrade && $m->grade !== null) ? html_writer::span(format_float($m->grade, 2, true, true) . ' / ' . (int)$m->maxgrade, 'tt-strong')
            : ($m->maxgrade ? html_writer::span('- / ' . (int)$m->maxgrade, 'tt-sub') : '-'),
        $list ?: '-',
        $btns,
    ];
}
echo html_writer::div(html_writer::div(html_writer::table($t), 'tt-tablewrap'), 'mt-3');

// Discussion.
echo html_writer::tag('h4', get_string('discussion', 'local_researchcentral'), ['id' => 'discussion', 'class' => 'mt-4']);
$thread = $DB->get_records('local_researchcentral_msg', ['projid' => $id], 'timecreated DESC, id DESC', '*', 0, 50);
$uids = array_unique(array_merge([$proj->userid], $team, array_column($thread, 'userid')));
$people = $DB->get_records_list('user', 'id', $uids);
$preseen = \local_researchcentral\local\receipts::for_items('msg', array_column($thread, 'id'));
$unreadbefore = array_filter($thread, fn($x) => (int)$x->userid !== (int)$USER->id && !isset($preseen[$x->id][(int)$USER->id]));
$newids = array_column($unreadbefore, 'id');
\local_researchcentral\local\receipts::mark_messages($thread, (int)$proj->id, (int)$USER->id);
$seenmap = \local_researchcentral\local\receipts::for_items('msg', array_column($thread, 'id'));
if (!$thread) {
    echo html_writer::div(get_string('no_messages', 'local_researchcentral'), 'tt-empty');
}
if ($newids) {
    echo html_writer::div(get_string('rcpt_new_messages', 'local_researchcentral', count($newids)), 'tt-banner');
}
echo html_writer::start_div('tt-thread');
foreach (array_reverse($thread) as $msg) {
    $mine = (int)$msg->userid === (int)$USER->id;
    $isnew = in_array($msg->id, $newids);
    echo html_writer::div(
        html_writer::div(s($nameof((int)$msg->userid)) . ' · ' .
            userdate($msg->timecreated, get_string('strftimedatetimeshort', 'langconfig')) .
            ($isnew ? ' ' . html_writer::span(get_string('rcpt_new', 'local_researchcentral'), 'tt-newbadge') : ''), 'tt-sub') .
        html_writer::div(nl2br(s($msg->body))) .
        \local_researchcentral\local\receipts::message_status($proj, $msg, $seenmap[$msg->id] ?? [], $nameof, $mine),
        'tt-msg' . ($mine ? ' tt-msg-me' : '') . ($isnew ? ' tt-msg-new' : ''));
}
echo html_writer::end_div();
$msgform->display();

// Coordinator tools.
if ($reform) {
    echo html_writer::tag('h4', get_string('team_manage', 'local_researchcentral'), ['class' => 'mt-4']);
    $tt = new html_table();
    $tt->attributes['class'] = 'tt-table';
    foreach ($team as $i => $uid) {
        $u = core_user::get_user($uid);
        $acts = '';
        if ($i > 0) {
            $acts .= html_writer::link(new moodle_url($url, ['action' => 'makelead', 'user' => $uid, 'sesskey' => sesskey()]),
                get_string('make_lead', 'local_researchcentral'), ['class' => 'btn btn-sm btn-outline-primary mr-1']);
            $acts .= html_writer::link(new moodle_url($url, ['action' => 'removecosup', 'user' => $uid, 'sesskey' => sesskey()]),
                get_string('remove'), ['class' => 'btn btn-sm btn-outline-danger']);
        }
        $tt->data[] = [$u ? s(fullname($u)) : '-', $u ? s($u->email) : '',
            get_string($i === 0 ? 'lead_supervisor' : 'co_supervisor', 'local_researchcentral'), $acts];
    }
    echo html_writer::div(html_writer::table($tt), 'tt-tablewrap');
    if ($cosupform) {
        echo html_writer::tag('h6', get_string('cosup_add', 'local_researchcentral'));
        $cosupform->display();
    } else if (!$postgrad) {
        echo html_writer::div(get_string('cosup_postgrad', 'local_researchcentral'), 'tt-sub mb-2');
    }
    echo html_writer::tag('h6', get_string('reassign_title', 'local_researchcentral'));
    $reform->display();
}

// Audit trail.
echo html_writer::tag('h4', get_string('activity', 'local_researchcentral'), ['class' => 'mt-4']);
$acts = $DB->get_records('local_researchcentral_act', ['projid' => $id], 'timecreated DESC, id DESC', '*', 0, 40);
$auids = array_unique(array_column($acts, 'userid'));
$apeople = $auids ? $DB->get_records_list('user', 'id', $auids) : [];
$t = new html_table();
$t->attributes['class'] = 'tt-table';
foreach ($acts as $a) {
    $t->data[] = [userdate($a->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
        s(isset($apeople[$a->userid]) ? fullname($apeople[$a->userid]) : '-'),
        (get_string_manager()->string_exists('act_' . $a->action, 'local_researchcentral')
            ? get_string('act_' . $a->action, 'local_researchcentral') : s($a->action)), s($a->detail)];
}
echo $acts ? html_writer::div(html_writer::table($t), 'tt-tablewrap') : html_writer::div(get_string('no_projects', 'local_researchcentral'), 'tt-empty');
echo $OUTPUT->footer();
