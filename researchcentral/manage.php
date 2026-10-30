<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\tracker;
use local_researchcentral\form\stage_form;
use local_researchcentral\form\assign_form;

$id = required_param('id', PARAM_INT);
$delstage = optional_param('delstage', 0, PARAM_INT);

$course = get_course($id);
\local_researchcentral\local\portal::gate();
require_login($course);
$context = context_course::instance($id);
\local_researchcentral\local\tracker::require_enabled($id);
require_capability('local/researchcentral:manage', $context);

$url = new moodle_url('/local/researchcentral/manage.php', ['id' => $id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('managetheses', 'local_researchcentral'));
$PAGE->set_heading($course->fullname);

$back = new moodle_url('/local/researchcentral/index.php', ['id' => $id]);

if ($delstage) {
    require_sesskey();
    $stage = $DB->get_record('local_researchcentral_stage', ['id' => $delstage, 'courseid' => $id], '*', MUST_EXIST);
    $msids = $DB->get_fieldset_select('local_researchcentral_ms', 'id', 'stageid = ?', [$stage->id]);
    if ($msids) {
        [$insql, $inparams] = $DB->get_in_or_equal($msids);
        $DB->delete_records_select('local_researchcentral_log', "msid $insql", $inparams);
    }
    $DB->delete_records('local_researchcentral_ms', ['stageid' => $stage->id]);
    $DB->delete_records('local_researchcentral_report', ['stageid' => $stage->id]);
    $DB->delete_records('local_researchcentral_rules', ['stageid' => $stage->id]);
    $DB->delete_records('local_researchcentral_stage', ['id' => $stage->id]);
    redirect($url, get_string('stage_deleted', 'local_researchcentral'));
}

$stageform = new stage_form($url, ['courseid' => $id]);
$assignform = new assign_form($url, ['courseid' => $id]);

if ($data = $stageform->get_data()) {
    $DB->insert_record('local_researchcentral_stage', (object)[
        'courseid' => $id, 'name' => $data->name, 'cmid' => (int)$data->cmid, 'level' => $data->level,
        'sortorder' => (int)$data->sortorder, 'defaultdays' => (int)$data->defaultdays, 'timecreated' => time(),
    ]);
    redirect($url, get_string('stage_added', 'local_researchcentral'));
}
if ($data = $assignform->get_data()) {
    if (!$DB->record_exists('local_researchcentral_thesis', ['courseid' => $id, 'userid' => $data->userid])) {
        tracker::create_thesis($id, (int)$data->userid, (int)$data->supervisorid, $data->level,
            (string)$data->title, (int)$data->startdate);
    }
    redirect($back, get_string('assign_done', 'local_researchcentral'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managetheses', 'local_researchcentral'));
echo html_writer::link($back, get_string('back', 'local_researchcentral'), ['class' => 'd-block mb-3']);

echo $OUTPUT->heading(get_string('stages', 'local_researchcentral'), 3);
$stages = $DB->get_records('local_researchcentral_stage', ['courseid' => $id], 'sortorder, id');
if (!$stages) {
    echo $OUTPUT->notification(get_string('nostages', 'local_researchcentral'), 'info');
} else {
    $modinfo = get_fast_modinfo($course);
    $table = new html_table();
    $table->head = [get_string('stage_sortorder', 'local_researchcentral'), get_string('stage_name', 'local_researchcentral'),
        get_string('stage_activity', 'local_researchcentral'), get_string('stage_level', 'local_researchcentral'),
        get_string('stage_defaultdays', 'local_researchcentral'), ''];
    $table->attributes['class'] = 'table table-striped generaltable';
    foreach ($stages as $s) {
        $act = get_string('manual', 'local_researchcentral');
        if ($s->cmid && isset($modinfo->cms[$s->cmid])) {
            $act = $modinfo->cms[$s->cmid]->get_formatted_name();
        }
        $table->data[] = [$s->sortorder, format_string($s->name), $act,
            tracker::levels(true)[$s->level] ?? s($s->level), $s->defaultdays,
            html_writer::link(new moodle_url('/local/researchcentral/rules.php', ['id' => $id, 'stageid' => $s->id]),
                get_string('rules', 'local_researchcentral')) . ' | ' .
            html_writer::link(new moodle_url($url, ['delstage' => $s->id, 'sesskey' => sesskey()]),
                get_string('stage_delete', 'local_researchcentral'), ['class' => 'text-danger'])];
    }
    echo html_writer::table($table);
}
$stageform->display();

echo $OUTPUT->heading(get_string('assignstudent', 'local_researchcentral'), 3);
$assignform->display();
echo $OUTPUT->footer();
