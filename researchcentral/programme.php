<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\portal;
use local_researchcentral\form\pstage_form;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
portal::require_coordinator();
$prog = $DB->get_record('local_researchcentral_prog', ['id' => $id], '*', MUST_EXIST);
portal::require_manage_prog($prog);
$context = context_system::instance();
$url = new moodle_url('/local/researchcentral/programme.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(format_string($prog->name));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

$used = fn($stageid) => $DB->record_exists('local_researchcentral_pms', ['stageid' => $stageid]);

if (in_array($action, ['delete', 'up', 'down'], true)) {
    require_sesskey();
    $stage = $DB->get_record('local_researchcentral_stage', ['id' => required_param('stage', PARAM_INT), 'progid' => $id], '*', MUST_EXIST);
    if ($action === 'delete') {
        if ($used($stage->id)) {
            redirect($url, get_string('stage_inuse', 'local_researchcentral'), null, \core\output\notification::NOTIFY_ERROR);
        }
        $DB->delete_records('local_researchcentral_rules', ['stageid' => $stage->id]);
        $DB->delete_records('local_researchcentral_stage', ['id' => $stage->id]);
    } else {
        $all = array_values($DB->get_records('local_researchcentral_stage', ['progid' => $id], 'sortorder, id'));
        foreach ($all as $i => $s) {
            if ((int)$s->id === (int)$stage->id) {
                $j = $action === 'up' ? $i - 1 : $i + 1;
                if (isset($all[$j])) {
                    [$all[$i], $all[$j]] = [$all[$j], $all[$i]];
                }
                break;
            }
        }
        foreach ($all as $i => $s) {
            $DB->set_field('local_researchcentral_stage', 'sortorder', $i + 1, ['id' => $s->id]);
        }
    }
    redirect($url);
}

$form = new pstage_form($url, ['id' => $id]);
if ($data = $form->get_data()) {
    $max = (int)$DB->get_field_sql('SELECT MAX(sortorder) FROM {local_researchcentral_stage} WHERE progid = ?', [$id]);
    $DB->insert_record('local_researchcentral_stage', (object)[
        'courseid' => 0, 'progid' => $id, 'name' => trim($data->name), 'cmid' => 0, 'level' => 'all',
        'sortorder' => $max + 1, 'defaultdays' => max(1, (int)$data->defaultdays),
        'needsupload' => empty($data->needsupload) ? 0 : 1, 'maxmb' => max(0, min(\local_researchcentral\local\uploads::ceiling_mb(), (int)$data->maxmb)),
        'maxgrade' => max(0, min(1000, (int)$data->maxgrade)), 'weight' => max(0, min(100, (int)$data->weight)), 'timecreated' => time()]);
    redirect($url);
}

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/programmes.php'), '← ' . get_string('programmes', 'local_researchcentral'), ['class' => 'tt-back']);
echo $OUTPUT->heading(format_string($prog->name), 3);
echo html_writer::div(s(portal::school_name($prog)) . ' · ' . get_string('level_' . ($prog->level ?: 'masters'), 'local_researchcentral'), 'tt-sub mb-3');

$stages = array_values($DB->get_records('local_researchcentral_stage', ['progid' => $id], 'sortorder, id'));
$rules = $DB->get_records('local_researchcentral_rules', null, '', 'stageid, id');
$t = new html_table();
$t->attributes['class'] = 'tt-table';
$t->head = ['#', get_string('stage', 'local_researchcentral'), get_string('stage_days', 'local_researchcentral'),
    get_string('upload_required', 'local_researchcentral'), get_string('marks', 'local_researchcentral'), get_string('checker', 'local_researchcentral'), ''];
foreach ($stages as $i => $s) {
    $act = [];
    $mk = fn($a) => new moodle_url($url, ['action' => $a, 'stage' => $s->id, 'sesskey' => sesskey()]);
    if ($i > 0) {
        $act[] = html_writer::link($mk('up'), '↑', ['class' => 'btn btn-sm btn-light', 'title' => get_string('moveup')]);
    }
    if ($i < count($stages) - 1) {
        $act[] = html_writer::link($mk('down'), '↓', ['class' => 'btn btn-sm btn-light', 'title' => get_string('movedown')]);
    }
    if ($s->needsupload) {
        $act[] = html_writer::link(new moodle_url('/local/researchcentral/prules.php', ['id' => $id, 'stageid' => $s->id]),
            get_string('rules', 'local_researchcentral'), ['class' => 'btn btn-sm btn-outline-secondary']);
    }
    $act[] = html_writer::link($mk('delete'), get_string('delete'), ['class' => 'btn btn-sm btn-outline-danger']);
    $t->data[] = [$i + 1, html_writer::div(format_string($s->name), 'tt-strong'), (int)$s->defaultdays,
        $s->needsupload ? get_string('yes') . ($s->maxmb ? ' · ' . (int)$s->maxmb . ' MB' : '') : get_string('no'),
        $s->maxgrade ? ('/' . (int)$s->maxgrade . ($s->weight ? ' · ' . (int)$s->weight . '%' : '')) : '-',
        !empty($rules[$s->id]) ? get_string('checker_on', 'local_researchcentral') : '-',
        implode(' ', $act)];
}
echo html_writer::table($t);
echo html_writer::tag('h5', get_string('stage_add', 'local_researchcentral'), ['class' => 'mt-4']);
$form->display();
echo $OUTPUT->footer();
