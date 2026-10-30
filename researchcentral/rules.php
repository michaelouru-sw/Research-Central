<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\form\rules_form;

$id = required_param('id', PARAM_INT);
$stageid = required_param('stageid', PARAM_INT);

$course = get_course($id);
\local_researchcentral\local\portal::gate();
require_login($course);
$context = context_course::instance($id);
\local_researchcentral\local\tracker::require_enabled($id);
require_capability('local/researchcentral:manage', $context);

$stage = $DB->get_record('local_researchcentral_stage', ['id' => $stageid, 'courseid' => $id], '*', MUST_EXIST);

$url = new moodle_url('/local/researchcentral/rules.php', ['id' => $id, 'stageid' => $stageid]);
$back = new moodle_url('/local/researchcentral/manage.php', ['id' => $id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('rules', 'local_researchcentral'));
$PAGE->set_heading($course->fullname);

$form = new rules_form($url, ['courseid' => $id, 'stageid' => $stageid]);

if ($form->is_cancelled()) {
    redirect($back);
}
if ($data = $form->get_data()) {
    $rec = (object)[
        'stageid' => $stageid,
        'minwords' => max(0, (int)$data->minwords), 'maxwords' => max(0, (int)$data->maxwords),
        'minpages' => max(0, (int)$data->minpages), 'maxpages' => max(0, (int)$data->maxpages),
        'fontsize' => max(0, (int)$data->fontsize), 'linespacing' => max(0, (int)$data->linespacing),
        'minmargin' => max(0, (int)$data->minmargin), 'minrefs' => max(0, (int)$data->minrefs),
        'recentyears' => max(0, (int)$data->recentyears), 'minrecent' => min(100, max(0, (int)$data->minrecent)),
        'font' => trim($data->font), 'headings' => trim($data->headings),
        'refstyle' => in_array($data->refstyle, ['none', 'apa', 'harvard']) ? $data->refstyle : 'none',
        'excluderefs' => empty($data->excluderefs) ? 0 : 1,
        'severity' => $data->severity === 'fail' ? 'fail' : 'warn',
        'timemodified' => time(),
    ];
    if ($existing = $DB->get_record('local_researchcentral_rules', ['stageid' => $stageid], 'id')) {
        $rec->id = $existing->id;
        $DB->update_record('local_researchcentral_rules', $rec);
    } else {
        $DB->insert_record('local_researchcentral_rules', $rec);
    }
    redirect($back, get_string('rules_saved', 'local_researchcentral'));
}
if ($existing = $DB->get_record('local_researchcentral_rules', ['stageid' => $stageid])) {
    $form->set_data($existing);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('rules', 'local_researchcentral') . ': ' . format_string($stage->name));
if (!$stage->cmid) {
    echo $OUTPUT->notification(get_string('rules_needactivity', 'local_researchcentral'), 'warning');
}
echo html_writer::link($back, get_string('back', 'local_researchcentral'), ['class' => 'd-block mb-3']);
$form->display();
echo $OUTPUT->footer();
