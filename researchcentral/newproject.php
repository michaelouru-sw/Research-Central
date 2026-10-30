<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\portal;
use local_researchcentral\form\project_form;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
portal::require_coordinator();
$context = context_system::instance();
$url = new moodle_url('/local/researchcentral/newproject.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('project_new', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

$form = new project_form($url);
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/researchcentral/portal.php', ['view' => 'coordinator']));
}
if ($data = $form->get_data()) {
    $projid = portal::create_project((int)$data->userid, (int)$data->supervisorid, (int)$data->progid,
        trim($data->title), (int)$data->startdate);
    redirect(new moodle_url('/local/researchcentral/project.php', ['id' => $projid]),
        get_string('project_created', 'local_researchcentral'), null, \core\output\notification::NOTIFY_SUCCESS);
}
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/portal.php', ['view' => 'coordinator']), '← ' . get_string('back', 'local_researchcentral'), ['class' => 'tt-back']);
echo $OUTPUT->heading(get_string('project_new', 'local_researchcentral'), 3);
if (!$DB->record_exists('local_researchcentral_prog', [])) {
    echo $OUTPUT->notification(get_string('no_programmes', 'local_researchcentral'), 'warning');
    echo html_writer::link(new moodle_url('/local/researchcentral/programmes.php'), get_string('prog_add', 'local_researchcentral'), ['class' => 'btn btn-primary']);
} else {
    $form->display();
}
echo $OUTPUT->footer();
