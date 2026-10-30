<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\portal;
use local_researchcentral\form\rules_form;

$id = required_param('id', PARAM_INT);
$stageid = required_param('stageid', PARAM_INT);
\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
portal::require_coordinator();
portal::require_manage_prog($DB->get_record('local_researchcentral_prog', ['id' => $id], '*', MUST_EXIST));
$stage = $DB->get_record('local_researchcentral_stage', ['id' => $stageid, 'progid' => $id], '*', MUST_EXIST);

$url = new moodle_url('/local/researchcentral/prules.php', ['id' => $id, 'stageid' => $stageid]);
$back = new moodle_url('/local/researchcentral/programme.php', ['id' => $id]);
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('rules', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

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
echo $OUTPUT->heading(get_string('rules', 'local_researchcentral') . ': ' . format_string($stage->name), 3);
echo html_writer::link($back, get_string('back', 'local_researchcentral'), ['class' => 'tt-back']);
$form->display();
echo $OUTPUT->footer();
