<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\tracker;

$id = required_param('id', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$msid = optional_param('msid', 0, PARAM_INT);

$course = get_course($id);
\local_researchcentral\local\portal::gate();
require_login($course);
$context = context_course::instance($id);
\local_researchcentral\local\tracker::require_enabled($id);

// Role is detected automatically: capability and/or thesis assignment.
$roles = tracker::get_roles((int)$USER->id, $id);
$ismanager = $roles['manager'];
$issupervisor = $roles['supervisor'];
$isstudent = $roles['student'];
if (!$ismanager && !$issupervisor && !$isstudent) {
    throw new required_capability_exception($context, 'local/researchcentral:viewown', 'nopermissions', '');
}

$PAGE->set_url('/local/researchcentral/index.php', ['id' => $id, 'userid' => $userid]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('dashboard', 'local_researchcentral'));
$PAGE->set_heading($course->fullname);

// Supervisor confirms a manual milestone.
if ($action === 'complete' && $msid) {
    require_sesskey();
    $sql = "SELECT m.id, t.supervisorid, t.userid, s.cmid
              FROM {local_researchcentral_ms} m
              JOIN {local_researchcentral_thesis} t ON t.id = m.thesisid
              JOIN {local_researchcentral_stage} s ON s.id = m.stageid
             WHERE m.id = :id AND t.courseid = :c";
    $m = $DB->get_record_sql($sql, ['id' => $msid, 'c' => $id], MUST_EXIST);
    if (!$ismanager && !($issupervisor && (int)$m->supervisorid === (int)$USER->id)) {
        throw new required_capability_exception($context, 'local/researchcentral:supervise', 'nopermissions', '');
    }
    if (empty($m->cmid)) {
        $DB->update_record('local_researchcentral_ms', (object)[
            'id' => $m->id, 'status' => tracker::COMPLETE, 'timecompleted' => time(), 'timemodified' => time()]);
    }
    redirect(new moodle_url('/local/researchcentral/index.php', ['id' => $id, 'userid' => $m->userid]),
        get_string('marked', 'local_researchcentral'));
}

// Run a document check on the student's latest submission (student, their supervisor, or a coordinator).
if ($action === 'check' && $msid) {
    require_sesskey();
    $sql = "SELECT m.id, t.userid, t.supervisorid, s.cmid
              FROM {local_researchcentral_ms} m
              JOIN {local_researchcentral_thesis} t ON t.id = m.thesisid
              JOIN {local_researchcentral_stage} s ON s.id = m.stageid
             WHERE m.id = :id AND t.courseid = :c";
    $m = $DB->get_record_sql($sql, ['id' => $msid, 'c' => $id], MUST_EXIST);
    $can = ((int)$m->userid === (int)$USER->id) || $ismanager
        || ($issupervisor && (int)$m->supervisorid === (int)$USER->id);
    if (!$can) {
        throw new required_capability_exception($context, 'local/researchcentral:supervise', 'nopermissions', '');
    }
    $reportid = $m->cmid ? \local_researchcentral\checker\runner::check_for($id, (int)$m->userid, (int)$m->cmid, false) : null;
    if ($reportid) {
        redirect(new moodle_url('/local/researchcentral/report.php', ['id' => $id, 'reportid' => $reportid]));
    }
    redirect(new moodle_url('/local/researchcentral/index.php', ['id' => $id, 'userid' => $m->userid]),
        get_string('nosubmissionfound', 'local_researchcentral'), null, \core\output\notification::NOTIFY_WARNING);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_researchcentral'));
echo html_writer::tag('p', get_string('viewingas', 'local_researchcentral', implode(' / ', tracker::role_labels($roles))),
    ['class' => 'text-muted']);

// Toolbar.
$links = [];
if ($ismanager) {
    $links[] = html_writer::link(new moodle_url('/local/researchcentral/manage.php', ['id' => $id]),
        get_string('managetheses', 'local_researchcentral'), ['class' => 'btn btn-secondary mr-2']);
}
if ($isstudent && ($ismanager || $issupervisor) && $DB->record_exists('local_researchcentral_thesis', ['courseid' => $id, 'userid' => $USER->id])) {
    $links[] = html_writer::link(new moodle_url('/local/researchcentral/index.php', ['id' => $id, 'userid' => $USER->id]),
        get_string('mythesis', 'local_researchcentral'), ['class' => 'btn btn-secondary mr-2']);
}
if ($ismanager || has_capability('local/researchcentral:viewanalytics', $context)) {
    $links[] = html_writer::link(new moodle_url('/local/researchcentral/analytics.php', ['id' => $id]),
        get_string('analytics', 'local_researchcentral'), ['class' => 'btn btn-secondary mr-2']);
}
if ($links) {
    echo html_writer::div(implode('', $links), 'mb-3');
}

// Decide the view: detail for one student, or overview for staff.
$staff = $ismanager || $issupervisor;
if (!$userid && !$staff) {
    $userid = $USER->id;
}

if ($userid) {
    $thesis = $DB->get_record('local_researchcentral_thesis', ['courseid' => $id, 'userid' => $userid]);
    if (!$thesis) {
        echo $OUTPUT->notification(get_string('nothesis', 'local_researchcentral'), 'info');
        echo $OUTPUT->footer();
        exit;
    }
    $allowed = ((int)$userid === (int)$USER->id) || $ismanager || ($issupervisor && (int)$thesis->supervisorid === (int)$USER->id);
    if (!$allowed) {
        throw new required_capability_exception($context, 'local/researchcentral:supervise', 'nopermissions', '');
    }
    tracker::sync_for_user($id, (int)$userid);

    $student = core_user::get_user($userid);
    $sup = core_user::get_user($thesis->supervisorid);
    echo html_writer::tag('h4', s($thesis->title ?: get_string('pluginname', 'local_researchcentral')));
    echo html_writer::tag('p',
        get_string('student', 'local_researchcentral') . ': ' . fullname($student) . ' · ' .
        get_string('supervisor', 'local_researchcentral') . ': ' . ($sup ? fullname($sup) : '-') . ' · ' .
        get_string('level', 'local_researchcentral') . ': ' . (tracker::levels()[$thesis->level] ?? s($thesis->level)));

    $modinfo = get_fast_modinfo($course);
    $badges = [0 => 'badge-secondary', 1 => 'badge-success', 2 => 'badge-danger'];
    $table = new html_table();
    $table->head = [get_string('milestone', 'local_researchcentral'), get_string('linked_activity', 'local_researchcentral'),
        get_string('due', 'local_researchcentral'), get_string('status', 'local_researchcentral'),
        get_string('completedon', 'local_researchcentral'), get_string('doccheck', 'local_researchcentral'),
        get_string('action', 'local_researchcentral')];
    $table->attributes['class'] = 'table table-striped generaltable';
    $ruled = tracker::stage_ids_with_rules($id);
    $latestreports = tracker::get_latest_reports((int)$thesis->id);
    $canact = ((int)$thesis->userid === (int)$USER->id) || $ismanager
        || ($issupervisor && (int)$thesis->supervisorid === (int)$USER->id);
    $repbadge = ['pass' => 'badge-success', 'warn' => 'badge-warning', 'fail' => 'badge-danger', 'error' => 'badge-secondary'];
    foreach (tracker::get_milestones($thesis->id) as $m) {
        $check = '';
        if ($m->cmid && isset($ruled[$m->stageid])) {
            if (isset($latestreports[$m->stageid])) {
                $lr = $latestreports[$m->stageid];
                $check .= html_writer::link(
                    new moodle_url('/local/researchcentral/report.php', ['id' => $id, 'reportid' => $lr->id]),
                    html_writer::span(get_string('overall_' . $lr->status, 'local_researchcentral'), 'badge ' . $repbadge[$lr->status])) . ' ';
            }
            if ($canact) {
                $check .= html_writer::link(new moodle_url('/local/researchcentral/index.php',
                    ['id' => $id, 'action' => 'check', 'msid' => $m->id, 'sesskey' => sesskey()]),
                    get_string('checkdocument', 'local_researchcentral'), ['class' => 'btn btn-sm btn-outline-secondary']);
            }
        }
        $activity = get_string('manual', 'local_researchcentral');
        if ($m->cmid && isset($modinfo->cms[$m->cmid]) && $modinfo->cms[$m->cmid]->uservisible) {
            $cm = $modinfo->cms[$m->cmid];
            $activity = html_writer::link($cm->url ?: new moodle_url('/course/view.php', ['id' => $id]), $cm->get_formatted_name());
        }
        $action = '';
        if (!$m->cmid && (int)$m->status !== tracker::COMPLETE && $staff) {
            $action = html_writer::link(new moodle_url('/local/researchcentral/index.php',
                ['id' => $id, 'action' => 'complete', 'msid' => $m->id, 'sesskey' => sesskey()]),
                get_string('markcomplete', 'local_researchcentral'), ['class' => 'btn btn-sm btn-outline-primary']);
        }
        $table->data[] = [
            format_string($m->name), $activity,
            $m->duedate ? userdate($m->duedate, get_string('strftimedatefullshort', 'langconfig')) : '-',
            html_writer::span(get_string('status_' . $m->status, 'local_researchcentral'), 'badge ' . $badges[(int)$m->status]),
            $m->timecompleted ? userdate($m->timecompleted, get_string('strftimedatefullshort', 'langconfig')) : '-',
            $check ?: '-',
            $action,
        ];
    }
    echo html_writer::table($table);
    if ($staff) {
        echo html_writer::link(new moodle_url('/local/researchcentral/index.php', ['id' => $id]),
            get_string('back', 'local_researchcentral'));
    }
} else {
    tracker::sync_course($id);
    $rows = tracker::get_overview($id, $ismanager ? 0 : (int)$USER->id);
    if (!$rows) {
        echo $OUTPUT->notification(get_string('nothesesyet', 'local_researchcentral'), 'info');
    } else {
        $table = new html_table();
        $table->head = [get_string('student', 'local_researchcentral'), get_string('supervisor', 'local_researchcentral'),
            get_string('level', 'local_researchcentral'), get_string('progress', 'local_researchcentral'),
            get_string('overdue', 'local_researchcentral'), get_string('nextdue', 'local_researchcentral'), ''];
        $table->attributes['class'] = 'table table-striped generaltable';
        foreach ($rows as $r) {
            $student = core_user::get_user($r->userid);
            $sup = core_user::get_user($r->supervisorid);
            $pct = $r->total ? round(100 * $r->done / $r->total) : 0;
            $bar = html_writer::div(html_writer::div($pct . '%', 'progress-bar', ['style' => "width: {$pct}%"]), 'progress');
            $table->data[] = [
                fullname($student), $sup ? fullname($sup) : '-',
                tracker::levels()[$r->level] ?? s($r->level),
                $bar . html_writer::span("{$r->done}/{$r->total}", 'small text-muted'),
                $r->overdue ? html_writer::span((int)$r->overdue, 'badge badge-danger') : '0',
                $r->nextdue ? userdate($r->nextdue, get_string('strftimedatefullshort', 'langconfig')) : '-',
                html_writer::link(new moodle_url('/local/researchcentral/index.php', ['id' => $id, 'userid' => $r->userid]),
                    get_string('view', 'local_researchcentral')),
            ];
        }
        echo html_writer::table($table);
    }
}
echo $OUTPUT->footer();
