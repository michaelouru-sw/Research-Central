<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

use local_researchcentral\local\portal;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
portal::require_coordinator();
$projects = portal::projects('coordinator', [
    'schoolid' => optional_param('schoolid', 0, PARAM_INT), 'level' => optional_param('level', '', PARAM_ALPHA),
    'state' => optional_param('state', '', PARAM_ALPHA)]);
$w = new csv_export_writer();
$w->set_filename('research_progress_' . userdate(time(), '%Y%m%d'));
$w->add_data([get_string('student', 'local_researchcentral'), 'Email', get_string('supervisor', 'local_researchcentral'),
    get_string('programme', 'local_researchcentral'), get_string('school', 'local_researchcentral'),
    get_string('stage_level', 'local_researchcentral'), get_string('thesistitle', 'local_researchcentral'),
    get_string('status', 'local_researchcentral'), get_string('current_stage', 'local_researchcentral'),
    get_string('stages_done', 'local_researchcentral'), get_string('overall_mark', 'local_researchcentral') . ' %']);
foreach ($projects as $p) {
    $ov = portal::overall($p->ms);
    $w->add_data([$p->student ? fullname($p->student) : '', $p->student->email ?? '', $p->supervisor ? fullname($p->supervisor) : '',
        $p->progname, $p->school, get_string('level_' . ($p->level ?: 'masters'), 'local_researchcentral'), $p->title,
        get_string('state_' . $p->state, 'local_researchcentral'), $p->current ? $p->current->stagename : '',
        $p->done . '/' . $p->total, $ov ? round($ov['percent'], 1) : '']);
}
$w->download_file();
