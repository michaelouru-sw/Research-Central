<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\portal;
use local_researchcentral\form\prog_form;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
portal::require_coordinator();
$context = context_system::instance();
$url = new moodle_url('/local/researchcentral/programmes.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('programmes', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

$form = new prog_form($url);
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/researchcentral/portal.php'));
}
if ($data = $form->get_data()) {
    if (!portal::can_manage_school((int)$data->schoolid)) {
        throw new moodle_exception('nopermissions', 'error', '', 'school');
    }
    $level = in_array($data->level, ['diploma', 'ug', 'masters', 'phd']) ? $data->level : 'masters';
    $progid = $DB->insert_record('local_researchcentral_prog', (object)[
        'name' => trim($data->name), 'schoolid' => (int)$data->schoolid, 'level' => $level, 'timecreated' => time()]);
    if (!empty($data->usetemplate)) {
        portal::seed_stages($progid, $level);
    }
    redirect(new moodle_url('/local/researchcentral/programme.php', ['id' => $progid]));
}

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/portal.php', ['view' => 'coordinator']), '← ' . get_string('back', 'local_researchcentral'), ['class' => 'tt-back']);
echo $OUTPUT->heading(get_string('programmes', 'local_researchcentral'), 3);
$sql = "SELECT g.*, sc.name AS school, (SELECT COUNT(1) FROM {local_researchcentral_stage} s WHERE s.progid = g.id) AS stages,
               (SELECT COUNT(1) FROM {local_researchcentral_proj} p WHERE p.progid = g.id) AS students
          FROM {local_researchcentral_prog} g LEFT JOIN {local_researchcentral_school} sc ON sc.id = g.schoolid ORDER BY sc.name, g.name";
$progs = array_filter($DB->get_records_sql($sql), fn($g) => portal::can_manage_school((int)$g->schoolid));
if (!$progs) {
    echo html_writer::div(get_string('no_programmes', 'local_researchcentral'), 'tt-empty');
} else {
    $t = new html_table();
    $t->attributes['class'] = 'tt-table';
    $t->head = [get_string('programme', 'local_researchcentral'), get_string('school', 'local_researchcentral'),
        get_string('stage_level', 'local_researchcentral'), get_string('stages', 'local_researchcentral'),
        get_string('stat_students', 'local_researchcentral'), ''];
    foreach ($progs as $g) {
        $t->data[] = [html_writer::div(format_string($g->name), 'tt-strong'), s($g->school ?? ''),
            get_string('level_' . ($g->level ?: 'masters'), 'local_researchcentral'), (int)$g->stages, (int)$g->students,
            html_writer::link(new moodle_url('/local/researchcentral/programme.php', ['id' => $g->id]),
                get_string('edit'), ['class' => 'btn btn-sm btn-outline-primary'])];
    }
    echo html_writer::table($t);
}
echo html_writer::tag('h5', get_string('prog_add', 'local_researchcentral'), ['class' => 'mt-4']);
if (!portal::schools(true)) {
    echo $OUTPUT->notification(get_string('no_schools_yet', 'local_researchcentral') . ' ' .
        (is_siteadmin() ? html_writer::link(new moodle_url('/local/researchcentral/schools.php'), get_string('schools', 'local_researchcentral')) : ''), 'warning');
} else {
    $form->display();
}
echo $OUTPUT->footer();
