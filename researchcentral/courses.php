<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_researchcentral\local\tracker;
use local_researchcentral\form\courses_form;

admin_externalpage_setup('local_researchcentral_courses');

$disable = optional_param('disable', 0, PARAM_INT);
$url = new moodle_url('/local/researchcentral/courses.php');

if ($disable) {
    require_sesskey();
    tracker::disable_course($disable);
    redirect($url, get_string('courses_disabled_done', 'local_researchcentral'));
}

$form = new courses_form($url);
if ($data = $form->get_data()) {
    foreach ((array)$data->courseids as $cid) {
        tracker::enable_course((int)$cid);
    }
    redirect($url, get_string('courses_enabled_done', 'local_researchcentral'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('thesiscourses', 'local_researchcentral'));
echo html_writer::tag('p', get_string('thesiscourses_intro', 'local_researchcentral'));

echo $OUTPUT->heading(get_string('coursesenabled', 'local_researchcentral'), 4);
$sql = "SELECT tc.courseid, c.fullname,
               (SELECT COUNT(1) FROM {local_researchcentral_thesis} t WHERE t.courseid = tc.courseid) AS theses
          FROM {local_researchcentral_course} tc
          JOIN {course} c ON c.id = tc.courseid
      ORDER BY c.fullname";
$rows = $DB->get_records_sql($sql);
if (!$rows) {
    echo $OUTPUT->notification(get_string('nocoursesenabled', 'local_researchcentral'), 'info');
} else {
    $table = new html_table();
    $table->head = [get_string('course'), get_string('studentsinfo', 'local_researchcentral'), ''];
    $table->attributes['class'] = 'table table-striped generaltable';
    foreach ($rows as $r) {
        $table->data[] = [
            format_string($r->fullname), (int)$r->theses,
            html_writer::link(new moodle_url('/local/researchcentral/index.php', ['id' => $r->courseid]),
                get_string('opencourse', 'local_researchcentral')) . ' | ' .
            html_writer::link(new moodle_url($url, ['disable' => $r->courseid, 'sesskey' => sesskey()]),
                get_string('disablecourse', 'local_researchcentral'), ['class' => 'text-danger']),
        ];
    }
    echo html_writer::table($table);
}

$form->display();
echo $OUTPUT->footer();
