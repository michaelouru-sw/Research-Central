<?php
// Journal-style "manuscript details" for one stage: submissions, reviewer panel, reviews and decision letters per round.
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\portal;
use local_researchcentral\local\review;
use local_researchcentral\local\receipts;
use local_researchcentral\output\ui;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$context = context_system::instance();
$pmsid = required_param('id', PARAM_INT);
$m = $DB->get_record_sql("SELECT m.*, s.name AS stagename, s.needsupload, s.maxgrade, s.weight
                            FROM {local_researchcentral_pms} m JOIN {local_researchcentral_stage} s ON s.id = m.stageid WHERE m.id = ?", [$pmsid]);
if (!$m) {
    throw new moodle_exception('invalidrecord', 'error');
}
$proj = $DB->get_record('local_researchcentral_proj', ['id' => $m->projid], '*', MUST_EXIST);
portal::require_view($proj);
$isstudent = (int)$proj->userid === (int)$USER->id;
$iscoord = portal::can_manage_project($proj);
$isstaff = portal::is_project_supervisor($proj) || $iscoord;

$PAGE->set_context($context);
if ($isstaff && $m->needsupload && optional_param('do', '', PARAM_ALPHA) === 'limit' && data_submitted()) {
    require_sesskey();
    \local_researchcentral\local\uploads::set_project_limit($proj, optional_param('mb', 0, PARAM_INT));
    redirect(new moodle_url('/local/researchcentral/stage.php', ['id' => $pmsid]), get_string('limit_saved', 'local_researchcentral'), null, \core\output\notification::NOTIFY_SUCCESS);
}
$simform = null;
if ($isstaff && $m->needsupload) {
    $lastver = $DB->get_records('local_researchcentral_ver', ['pmsid' => $pmsid], 'version DESC', '*', 0, 1);
    $lastver = $lastver ? reset($lastver) : null;
    if ($lastver) {
        $simform = new \local_researchcentral\form\sim_form(new moodle_url('/local/researchcentral/stage.php', ['id' => $pmsid]), ['pms' => $pmsid, 'verid' => $lastver->id]);
        if ($d = $simform->get_data()) {
            $pct = trim((string)$d->simpct) === '' ? -1 : (int)$d->simpct;
            $DB->update_record('local_researchcentral_ver', (object)['id' => $lastver->id, 'simpct' => $pct, 'simnote' => trim($d->simnote)]);
            portal::log((int)$proj->id, 'similarity', $pct < 0 ? '-' : $pct . '%');
            redirect(new moodle_url('/local/researchcentral/stage.php', ['id' => $pmsid]), get_string('saved', 'local_researchcentral'));
        }
        if (!$simform->is_submitted()) {
            $simform->set_data((object)['simpct' => $lastver->simpct >= 0 ? $lastver->simpct : '', 'simnote' => $lastver->simnote]);
        }
    }
}
$PAGE->set_url(new moodle_url('/local/researchcentral/stage.php', ['id' => $pmsid]));
$PAGE->set_pagelayout('report');
$PAGE->set_title(format_string($m->stagename));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

$fs = get_file_storage();
$student = core_user::get_user($proj->userid);
$team = portal::supervisor_ids($proj);
$vers = $DB->get_records('local_researchcentral_ver', ['pmsid' => $pmsid], 'version');
$annopen = \local_researchcentral\local\annotate::open_counts([$pmsid], $isstaff);
$byver = [];
foreach ($vers as $v) {
    $byver[(int)$v->version] = $v;
}
$reports = [];
foreach ($DB->get_records('local_researchcentral_report', ['projid' => $proj->id]) as $r) {
    $reports[$r->verid] = $r;
}
$reviews = $DB->get_records('local_researchcentral_review', ['pmsid' => $pmsid], 'round, timecreated, id');
$decisions = [];
foreach ($DB->get_records('local_researchcentral_decision', ['pmsid' => $pmsid], 'round') as $d) {
    $decisions[(int)$d->round] = $d;
}
$current = review::round($pmsid);
$rounds = max($current, $reviews ? max(array_map(fn($r) => (int)$r->round, $reviews)) : 1, $vers ? max(array_keys($byver)) : 1);
$people = [];
$person = function (int $uid) use (&$people): string {
    if (!isset($people[$uid])) {
        $u = core_user::get_user($uid);
        $people[$uid] = $u ? fullname($u) : '-';
    }
    return s($people[$uid]);
};
$rawname = fn(int $u): string => html_entity_decode(strip_tags($person($u)), ENT_QUOTES);
$dlsub = receipts::for_items('submission', array_map(fn($v) => (int)$v->id, $vers));
$dlfb = receipts::for_items('feedback', array_map(fn($r) => (int)$r->id, $reviews));
$fileLinks = function (string $area, int $itemid) use ($fs, $context): string {
    $out = '';
    foreach ($fs->get_area_files($context->id, 'local_researchcentral', $area, $itemid, 'filename', false) as $f) {
        $u = moodle_url::make_pluginfile_url($context->id, 'local_researchcentral', $area, $itemid, '/', $f->get_filename(), true);
        $out .= html_writer::div('📎 ' . html_writer::link($u, s($f->get_filename())) .
            ' ' . html_writer::span(display_size($f->get_filesize()), 'tt-sub'), 'tt-file');
    }
    return $out;
};
$recpill = fn(string $rec) => ui::pill($rec === 'approve' ? 'approved' : ($rec === 'minor' ? 'submitted' : 'revision'),
    get_string('rec_' . $rec, 'local_researchcentral'));

echo $OUTPUT->header();
echo ui::project_crumbs($proj, [[format_string($m->stagename), null]]);

// Header.
$reviewed = count(review::active($pmsid, $current));
$state = portal::is_overdue($m) ? 'overdue' : $m->status;
$sla = (int)portal::cfg('review_sla_days', 7);
$waiting = ($m->status === portal::SUBMITTED && $m->timesubmitted) ? (time() - (int)$m->timesubmitted) / DAYSECS : null;
echo html_writer::div(
    html_writer::div(format_string($m->stagename), 'tt-title') .
    html_writer::div(s($student ? fullname($student) : '') . ' · ' . format_string($proj->title ?: get_string('untitled', 'local_researchcentral')), 'tt-sub mb-2') .
    html_writer::div(ui::pill($state, review::status_label($m, $reviewed, count($team))) . ' ' .
        html_writer::span(get_string('round_n', 'local_researchcentral', $current), 'tt-sub') .
        ($waiting !== null ? ' ' . html_writer::span(get_string('waiting_days', 'local_researchcentral', format_float($waiting, 1)),
            'tt-chk ' . ($sla && $waiting > $sla ? 'tt-chk-fail' : 'tt-chk-pass')) : ''), 'mb-2') .
    html_writer::div(get_string('due', 'local_researchcentral') . ': ' . ($m->duedate ? userdate($m->duedate, get_string('strftimedatefullshort', 'langconfig')) : '-') .
        (($m->maxgrade && $m->grade !== null) ? ' · ' . get_string('marks', 'local_researchcentral') . ': ' . format_float($m->grade, 2, true, true) . ' / ' . (int)$m->maxgrade : ''), 'tt-sub'),
    'tt-card');

// Action buttons.
$btns = '';
$purl = new moodle_url('/local/researchcentral/project.php', ['id' => $proj->id, 'pms' => $pmsid]);
if (review::can_review($proj, $m)) {
    $mine = review::active($pmsid, $current)[(int)$USER->id] ?? null;
    $btns .= html_writer::link(new moodle_url($purl, ['action' => 'decide']), get_string($mine ? 'review_edit' : 'review_write', 'local_researchcentral'), ['class' => 'btn btn-primary mr-2']);
}
if ($iscoord && review::open_for_review($m)) {
    $btns .= html_writer::link(new moodle_url($purl, ['action' => 'override']), get_string('review_override', 'local_researchcentral'), ['class' => 'btn btn-outline-secondary mr-2']);
}
if ($isstudent && $m->needsupload && in_array($m->status, [portal::PENDING, portal::REVISION], true)) {
    $btns .= html_writer::link(new moodle_url('/local/researchcentral/submit.php', ['pms' => $pmsid]),
        get_string($m->status === portal::REVISION ? 'upload_revision' : 'upload', 'local_researchcentral'), ['class' => 'btn btn-primary mr-2']);
}
echo $btns ? html_writer::div($btns, 'mb-3') : '';
if ($isstaff && $m->needsupload) {
    $stg = $DB->get_record('local_researchcentral_stage', ['id' => $m->stageid], 'id, maxmb');
    $eff = \local_researchcentral\local\uploads::limit_mb($proj, $stg);
    $src = \local_researchcentral\local\uploads::limit_source($proj, $stg);
    echo html_writer::tag('details', html_writer::tag('summary', get_string('limit_title', 'local_researchcentral') . ': ' . $eff . ' MB', ['class' => 'btn btn-sm btn-outline-secondary']) .
        html_writer::div(get_string('limit_current', 'local_researchcentral', (object)['mb' => $eff, 'src' => get_string('limit_src_' . $src, 'local_researchcentral')]), 'tt-sub my-2') .
        html_writer::tag('form', html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'limit']) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $pmsid]) .
            html_writer::empty_tag('input', ['type' => 'number', 'name' => 'mb', 'min' => 0, 'max' => \local_researchcentral\local\uploads::ceiling_mb(), 'value' => (int)$proj->maxmb ?: '', 'class' => 'form-control w-auto d-inline-block mr-2', 'aria-label' => get_string('limit_set', 'local_researchcentral'), 'placeholder' => get_string('limit_set', 'local_researchcentral')]) .
            html_writer::tag('button', get_string('limit_save', 'local_researchcentral'), ['type' => 'submit', 'class' => 'btn btn-sm btn-primary mr-2']) .
            html_writer::div(get_string('limit_help', 'local_researchcentral', \local_researchcentral\local\uploads::ceiling_mb()), 'tt-sub mt-1'),
            ['method' => 'post', 'action' => (new moodle_url('/local/researchcentral/stage.php'))->out(false)]), ['class' => 'mb-3']);
}

// Reviewer panel for the open round.
if (review::open_for_review($m)) {
    $t = new html_table();
    $t->attributes['class'] = 'tt-table';
    $t->head = [get_string('reviewer', 'local_researchcentral'), get_string('reviewer_role', 'local_researchcentral'),
        get_string('rec_label', 'local_researchcentral'), get_string('marks', 'local_researchcentral'), get_string('when', 'local_researchcentral')];
    foreach (review::panel($proj, $pmsid) as $uid => $row) {
        $r = $row['review'];
        $t->data[] = [$person($uid), get_string($row['role'] === 'lead' ? 'lead_supervisor' : 'co_supervisor', 'local_researchcentral'),
            $r ? $recpill($r->recommendation) : ui::pill('pending', get_string('review_pending', 'local_researchcentral')),
            ($r && $r->grade !== null) ? format_float($r->grade, 2, true, true) : '-',
            $r ? userdate($r->timecreated, get_string('strftimedatetimeshort', 'langconfig')) : '-'];
    }
    echo html_writer::tag('h5', get_string('reviewer_panel', 'local_researchcentral'));
    if ($isstaff) {
        echo html_writer::div(get_string('review_rule', 'local_researchcentral'), 'tt-sub mb-2');
        echo html_writer::div(html_writer::table($t), 'tt-tablewrap');
        $recs = array_unique(array_map(fn($r) => $r->recommendation, review::active($pmsid, $current)));
        if ($reviewed === count($team) && count($recs) > 1 && in_array('approve', $recs, true)) {
            echo html_writer::div(get_string('review_conflict', 'local_researchcentral'), 'tt-banner', ['style' => 'background:#fff1cc;border-color:#f0d58c;color:#8a5a00']);
        }
    } else {
        echo html_writer::div(get_string('review_progress_student', 'local_researchcentral', (object)['a' => $reviewed, 'b' => count($team)]), 'tt-sub mb-3');
    }
}

// History, newest round first.
echo html_writer::div(html_writer::link(new moodle_url('/local/researchcentral/workspace.php', ['id' => $proj->id]), get_string('ws_title', 'local_researchcentral'), ['class' => 'btn btn-sm btn-outline-primary mr-2']) .
    (count($vers) > 1 ? html_writer::link(new moodle_url('/local/researchcentral/compare.php', ['pms' => $pmsid]), get_string('compare_versions', 'local_researchcentral'), ['class' => 'btn btn-sm btn-outline-primary mr-2']) : ''), 'mb-2');
if ($simform) {
    echo html_writer::tag('details', html_writer::tag('summary', get_string('sim_title', 'local_researchcentral')) . html_writer::div(get_string('sim_help', 'local_researchcentral', (int)portal::cfg('sim_max', 20)), 'tt-sub') . $simform->render(), ['class' => 'mb-3']);
}
echo html_writer::tag('h5', get_string('stage_history', 'local_researchcentral'), ['class' => 'mt-3']);
for ($r = $rounds; $r >= 1; $r--) {
    $html = '';
    $v = $byver[$r] ?? null;
    if ($v) {
        $late = ($m->duedate && $r === 1 && $v->timecreated > $m->duedate) ? ' ' . html_writer::span(get_string('late', 'local_researchcentral'), 'tt-chk tt-chk-fail') : '';
        $rep = '';
        if (!empty($reports[$v->id])) {
            $rp = $reports[$v->id];
            $rep = ' ' . html_writer::link(new moodle_url('/local/researchcentral/preport.php', ['id' => $rp->id]),
                get_string('check_badge', 'local_researchcentral', get_string('overall_' . $rp->status, 'local_researchcentral')),
                ['class' => 'tt-chk tt-chk-' . $rp->status]);
        }
        $html .= html_writer::div(html_writer::span(get_string('submission_v', 'local_researchcentral', $v->version), 'tt-label') . ' ' .
            userdate($v->timecreated, get_string('strftimedatetimeshort', 'langconfig')) . $late . $rep .
            $fileLinks('submission', (int)$v->id) . ' ' . html_writer::link(new moodle_url('/local/researchcentral/read.php', ['ver' => $v->id]), ($annopen[(int)$v->id] ?? 0) ? get_string('read_open_n', 'local_researchcentral', $annopen[(int)$v->id]) : get_string('read_open', 'local_researchcentral'), ['class' => 'btn btn-sm btn-outline-primary ml-1']) . receipts::download_flags($proj, $team, $dlsub[(int)$v->id] ?? [], $rawname) . ((int)$v->simpct >= 0 ? html_writer::div(html_writer::span(get_string('sim_label', 'local_researchcentral', (int)$v->simpct), 'tt-chk ' . ((int)$v->simpct > (int)portal::cfg('sim_max', 20) ? 'tt-chk-fail' : 'tt-chk-pass')) . ' ' . s((string)$v->simnote), 'mt-1') : '') . ($v->note ? html_writer::div(s($v->note), 'tt-sub') : ''), 'tt-jblock');
    }
    $dec = $decisions[$r] ?? null;
    $visible = $isstaff || ($isstudent && $dec);
    $rr = array_filter($reviews, fn($x) => (int)$x->round === $r);
    if ($visible && $rr) {
        foreach ($rr as $x) {
            $mineorstaff = $iscoord || (int)$x->userid === (int)$USER->id;
            $role = get_string((int)$x->userid === (int)$proj->supervisorid ? 'lead_supervisor' : 'co_supervisor', 'local_researchcentral');
            $html .= html_writer::div(
                html_writer::div(html_writer::span($person((int)$x->userid), 'tt-strong') . ' ' . html_writer::span($role, 'tt-sub') . ' ' . $recpill($x->recommendation) .
                    ($x->grade !== null ? ' ' . html_writer::span(format_float($x->grade, 2, true, true) . ' / ' . (int)$m->maxgrade, 'tt-chk tt-chk-pass') : '') .
                    ($x->status === 'superseded' ? ' ' . html_writer::span(get_string('review_superseded', 'local_researchcentral'), 'tt-chk tt-chk-warn') : '') .
                    ' ' . html_writer::span(userdate($x->timecreated, get_string('strftimedatetimeshort', 'langconfig')), 'tt-sub')) .
                ($x->comment ? html_writer::div(format_text($x->comment, FORMAT_PLAIN), 'tt-body mt-1') : '') .
                $fileLinks('feedback', (int)$x->id) .
                (($isstaff && $fileLinks('feedback', (int)$x->id) !== '') ? html_writer::div(receipts::opened_flag($proj, $dlfb[(int)$x->id] ?? [], $rawname), 'tt-flags') : '') .
                (($mineorstaff && trim((string)$x->confidential) !== '') ? html_writer::div(html_writer::span('🔒 ' . get_string('review_confidential', 'local_researchcentral') . ': ', 'tt-label') .
                    s($x->confidential), 'tt-feedback') : ''), 'tt-jblock tt-review-' . $x->recommendation);
        }
    } else if (!$visible && $rr) {
        $html .= html_writer::div(get_string('review_hidden_until_decision', 'local_researchcentral'), 'tt-sub tt-jblock');
    }
    if ($dec) {
        $times = '';
        if ($dec->timesubmitted) {
            $first = max(0, $dec->firstresponse - $dec->timesubmitted) / DAYSECS;
            $all = max(0, $dec->timecreated - $dec->timesubmitted) / DAYSECS;
            $times = html_writer::div(get_string('response_times', 'local_researchcentral', (object)['first' => format_float($first, 1), 'all' => format_float($all, 1)]) .
                ' ' . html_writer::span(get_string($sla && $all > $sla ? 'sla_missed' : 'sla_met', 'local_researchcentral', $sla), 'tt-chk ' . ($sla && $all > $sla ? 'tt-chk-fail' : 'tt-chk-pass')), 'tt-sub mt-1');
        }
        $html .= html_writer::div(html_writer::div(html_writer::span(get_string('decision_letter', 'local_researchcentral'), 'tt-label') . ' ' .
            ui::pill($dec->decision === 'approve' ? 'approved' : 'revision', get_string('js_' . ($dec->decision === 'approve' ? 'approved' : 'revision'), 'local_researchcentral')) . ' ' .
            html_writer::span(userdate($dec->timecreated, get_string('strftimedatetimeshort', 'langconfig')), 'tt-sub')) .
            ($isstaff || $isstudent ? html_writer::div(nl2br(s($dec->letter)), 'tt-body mt-1') : '') . $times, 'tt-jblock tt-letter');
    } else if ($r === $current && !$html) {
        $html = html_writer::div(get_string('round_empty', 'local_researchcentral'), 'tt-sub');
    }
    if ($html !== '') {
        echo html_writer::div(html_writer::tag('h6', get_string('round_n', 'local_researchcentral', $r) .
            ($r === $current ? ' · ' . get_string('round_current', 'local_researchcentral') : ''), ['class' => 'tt-dayhead']) . $html, 'tt-card');
    }
}
echo $OUTPUT->footer();
