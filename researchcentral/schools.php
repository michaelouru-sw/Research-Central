<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\portal;
use local_researchcentral\form\scoord_form;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
portal::require_admin();
$url = new moodle_url('/local/researchcentral/schools.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('schools', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

$action = optional_param('action', '', PARAM_ALPHA);
$coordform = new scoord_form($url);
if ($data = $coordform->get_data()) {
    $DB->insert_record('local_researchcentral_scoord', (object)['schoolid' => (int)$data->schoolid, 'userid' => (int)$data->userid, 'timecreated' => time()]);
    portal::forget_roles();
    $a = (object)['school' => s($DB->get_field('local_researchcentral_school', 'name', ['id' => $data->schoolid]))];
    portal::notify((int)$data->userid, get_string('msg_coord_subject', 'local_researchcentral', $a),
        get_string('msg_coord_body', 'local_researchcentral', $a), new moodle_url('/local/researchcentral/portal.php', ['view' => 'coordinator']), 'workflow');
    redirect($url, get_string('coord_assigned', 'local_researchcentral'), null, \core\output\notification::NOTIFY_SUCCESS);
}
$name = trim(optional_param('name', '', PARAM_TEXT));
$sid = optional_param('sid', 0, PARAM_INT);

if ($action) {
    require_sesskey();
    $exists = function (string $n, int $except = 0) use ($DB) {
        foreach ($DB->get_records('local_researchcentral_school') as $r) {
            if ((int)$r->id !== $except && \core_text::strtolower($r->name) === \core_text::strtolower($n)) {
                return true;
            }
        }
        return false;
    };
    if ($action === 'add' && $name !== '') {
        if ($exists($name)) {
            redirect($url, get_string('school_exists', 'local_researchcentral'), null, \core\output\notification::NOTIFY_ERROR);
        }
        $DB->insert_record('local_researchcentral_school', (object)['name' => $name, 'timecreated' => time()]);
    } else if ($action === 'rename' && $sid && $name !== '') {
        if ($exists($name, $sid)) {
            redirect($url, get_string('school_exists', 'local_researchcentral'), null, \core\output\notification::NOTIFY_ERROR);
        }
        $DB->set_field('local_researchcentral_school', 'name', $name, ['id' => $sid]);
    } else if ($action === 'removecoord' && optional_param('cid', 0, PARAM_INT)) {
        $DB->delete_records('local_researchcentral_scoord', ['id' => required_param('cid', PARAM_INT)]);
        portal::forget_roles();
    } else if ($action === 'delete' && $sid) {
        if ($DB->record_exists('local_researchcentral_prog', ['schoolid' => $sid])) {
            redirect($url, get_string('school_inuse', 'local_researchcentral'), null, \core\output\notification::NOTIFY_ERROR);
        }
        $DB->delete_records('local_researchcentral_school', ['id' => $sid]);
    }
    redirect($url);
}

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/portal.php', ['view' => 'coordinator']), '← ' . get_string('back', 'local_researchcentral'), ['class' => 'tt-back']);
echo $OUTPUT->heading(get_string('schools', 'local_researchcentral'), 3);
echo html_writer::div(get_string('schools_intro', 'local_researchcentral'), 'tt-sub mb-3');

$rows = $DB->get_records_sql("SELECT sc.*, (SELECT COUNT(1) FROM {local_researchcentral_prog} g WHERE g.schoolid = sc.id) AS progs
                                FROM {local_researchcentral_school} sc ORDER BY sc.name");
if (!$rows) {
    echo html_writer::div(get_string('no_schools_yet', 'local_researchcentral'), 'tt-empty');
} else {
    $t = new html_table();
    $t->attributes['class'] = 'tt-table';
    $coords = portal::school_coordinators();
    $t->head = [get_string('school', 'local_researchcentral'), get_string('coordinators', 'local_researchcentral'), get_string('programmes', 'local_researchcentral'), ''];
    foreach ($rows as $r) {
        $rename = html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline-flex']) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'rename']) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sid', 'value' => $r->id]) .
            html_writer::empty_tag('input', ['type' => 'text', 'name' => 'name', 'value' => $r->name, 'class' => 'form-control form-control-sm mr-1', 'required' => 'required']) .
            html_writer::tag('button', get_string('rename', 'local_researchcentral'), ['class' => 'btn btn-sm btn-outline-secondary mr-1']) .
            html_writer::end_tag('form');
        $del = $r->progs ? '' : html_writer::link(new moodle_url($url, ['action' => 'delete', 'sid' => $r->id, 'sesskey' => sesskey()]),
            get_string('delete'), ['class' => 'btn btn-sm btn-outline-danger']);
        $clist = '';
        foreach ($coords[$r->id] ?? [] as $cu) {
            $clist .= html_writer::div(s(fullname($cu)) . ' ' . html_writer::link(
                new moodle_url($url, ['action' => 'removecoord', 'cid' => $cu->cid, 'sesskey' => sesskey()]), '×',
                ['class' => 'text-danger', 'title' => get_string('coord_remove', 'local_researchcentral')]), 'tt-sub');
        }
        $t->data[] = [html_writer::div(s($r->name), 'tt-strong'), $clist ?: html_writer::span('-', 'tt-sub'), (int)$r->progs, $rename . $del];
    }
    echo html_writer::table($t);
}
echo html_writer::tag('h5', get_string('school_add', 'local_researchcentral'), ['class' => 'mt-4']);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'class' => 'tt-filters']) .
    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'add']) .
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'name', 'class' => 'form-control', 'required' => 'required',
        'placeholder' => get_string('school_name', 'local_researchcentral')]) .
    html_writer::tag('button', get_string('school_add', 'local_researchcentral'), ['class' => 'btn btn-primary']) .
    html_writer::end_tag('form');
if ($rows) {
    echo html_writer::tag('h5', get_string('coord_assign_title', 'local_researchcentral'), ['class' => 'mt-4']);
    echo html_writer::div(get_string('coord_intro', 'local_researchcentral'), 'tt-sub mb-2');
    $coordform->display();
}
echo $OUTPUT->footer();
