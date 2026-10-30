<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');
require_once($CFG->libdir . '/excellib.class.php');

use local_researchcentral\local\gradesheet;
use local_researchcentral\local\portal;
use local_researchcentral\local\tracker;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$iscoord = portal::is_coordinator();
if (!$iscoord && !portal::is_supervisor()) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('grades_export', 'local_researchcentral'));
}
$scope = $iscoord ? 'coordinator' : 'supervisor';
$f = [
    'schoolid' => optional_param('schoolid', 0, PARAM_INT),
    'level' => optional_param('level', '', PARAM_ALPHA),
    'progid' => optional_param('progid', 0, PARAM_INT),
    'supervisorid' => $iscoord ? optional_param('supervisorid', 0, PARAM_INT) : 0,
];
if ($scope === 'supervisor') {
    unset($f['schoolid'], $f['level']);
}
$percent = (bool)optional_param('percent', 0, PARAM_INT);
$allstages = (bool)optional_param('allstages', 0, PARAM_INT);
$format = optional_param('format', 'xlsx', PARAM_ALPHA) === 'csv' ? 'csv' : 'xlsx';
$mine = $iscoord && optional_param('mine', 0, PARAM_INT);
if ($mine) {
    $f['supervisorid'] = $USER->id;
}

if (optional_param('download', 0, PARAM_INT)) {
    require_sesskey();
    $sheets = gradesheet::build(portal::projects($scope, $f), $percent, $allstages);
    $label = 'grades';
    foreach (['schoolid' => 'local_researchcentral_school', 'progid' => 'local_researchcentral_prog'] as $k => $tbl) {
        if (!empty($f[$k]) && ($nm = $DB->get_field($tbl, 'name', ['id' => $f[$k]]))) {
            $label .= '_' . clean_filename(\core_text::substr($nm, 0, 30));
        }
    }
    if (!empty($f['supervisorid']) && ($su = core_user::get_user($f['supervisorid']))) {
        $label .= '_' . clean_filename($su->lastname);
    }
    $label .= '_' . gmdate('Ymd');
    portal::log(0, 'gradeexport', $format . ' sheets=' . count($sheets));
    if ($format === 'csv') {
        [$head, $rows] = gradesheet::merge($sheets);
        $w = new csv_export_writer();
        $w->set_filename($label);
        $w->add_data($head);
        foreach ($rows as $r) {
            $w->add_data($r);
        }
        $w->download_file();
        exit;
    }
    $wb = new MoodleExcelWorkbook('-');
    $wb->send($label . '.xlsx');
    $used = [];
    $bold = $wb->add_format(['bold' => 1]);
    foreach ($sheets ?: ['Grades' => ['head' => ['Student Name', 'Email'], 'rows' => []]] as $name => $s) {
        $title = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $name);
        $title = \core_text::substr(trim($title), 0, 28) ?: 'Sheet';
        $base = $title;
        for ($i = 2; isset($used[$title]); $i++) {
            $title = \core_text::substr($base, 0, 26) . ' ' . $i;
        }
        $used[$title] = true;
        $ws = $wb->add_worksheet($title);
        foreach ($s['head'] as $c => $h) {
            $ws->write_string(0, $c, (string)$h, $bold);
        }
        foreach ($s['rows'] as $r => $row) {
            foreach ($row as $c => $v) {
                if ($v === '' || $v === null) {
                    continue;
                }
                is_numeric($v) && $c >= 2 ? $ws->write_number($r + 1, $c, (float)$v) : $ws->write_string($r + 1, $c, (string)$v);
            }
        }
        $ws->set_column(0, 0, 28);
        $ws->set_column(1, 1, 32);
    }
    $wb->close();
    exit;
}

$url = new moodle_url('/local/researchcentral/grades.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('grades_export', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

$all = portal::projects($scope);
$projects = portal::projects($scope, $f);
$progopts = $supopts = [];
foreach ($all as $p) {
    $progopts[$p->progid] = $p->progname . ($p->school ? ' – ' . $p->school : '');
    foreach (array_merge([$p->supervisor], $p->cosupervisors) as $su) {
        if ($su) {
            $supopts[$su->id] = fullname($su);
        }
    }
}
asort($progopts);
asort($supopts);
$base = array_filter($f) + ['percent' => (int)$percent, 'allstages' => (int)$allstages];
$dl = fn(array $extra, string $fmt = 'xlsx') => new moodle_url($url, $extra + ['download' => 1, 'format' => $fmt, 'sesskey' => sesskey(), 'percent' => (int)$percent, 'allstages' => (int)$allstages]);

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/portal.php'), '← ' . get_string('back', 'local_researchcentral'), ['class' => 'tt-back']);
echo $OUTPUT->heading(get_string('grades_export', 'local_researchcentral'), 3);
echo html_writer::div(get_string('grades_intro', 'local_researchcentral'), 'tt-sub mb-3');

$sel = fn(string $n, array $o, $v, string $none) => html_writer::select($o, $n, $v, $none, ['class' => 'custom-select', 'onchange' => 'this.form.submit()']);
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out(false), 'class' => 'tt-filters']);
if ($iscoord) {
    echo $sel('schoolid', portal::schools(true), $f['schoolid'], get_string('all_schools', 'local_researchcentral'));
    echo $sel('level', tracker::levels(), $f['level'], get_string('level_all', 'local_researchcentral'));
}
echo $sel('progid', $progopts, $f['progid'], get_string('all_programmes', 'local_researchcentral'));
if ($iscoord) {
    echo $sel('supervisorid', $supopts, $f['supervisorid'], get_string('all_supervisors', 'local_researchcentral'));
}
echo html_writer::tag('label', html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'percent', 'value' => 1, 'checked' => $percent ? 'checked' : null, 'onchange' => 'this.form.submit()']) .
    ' ' . get_string('grades_percent', 'local_researchcentral'), ['class' => 'mb-0']);
echo html_writer::tag('label', html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'allstages', 'value' => 1, 'checked' => $allstages ? 'checked' : null, 'onchange' => 'this.form.submit()']) .
    ' ' . get_string('grades_allstages', 'local_researchcentral'), ['class' => 'mb-0']);
echo html_writer::link($url, get_string('reset_filters', 'local_researchcentral'), ['class' => 'btn btn-outline-secondary']);
echo html_writer::end_tag('form');

$sheets = gradesheet::build($projects, $percent, $allstages);
echo html_writer::div(get_string('grades_scope', 'local_researchcentral', (object)['n' => count($projects), 'p' => count($sheets)]), 'tt-banner');
echo html_writer::div(
    html_writer::link($dl(array_filter($f), 'xlsx'), get_string('grades_xlsx', 'local_researchcentral'), ['class' => 'btn btn-primary mr-2']) .
    html_writer::link($dl(array_filter($f), 'csv'), get_string('grades_csv', 'local_researchcentral'), ['class' => 'btn btn-outline-primary']), 'mb-3');

if ($sheets) {
    $name = array_key_first($sheets);
    $s = $sheets[$name];
    echo html_writer::tag('h6', get_string('grades_preview', 'local_researchcentral', s($name)));
    $t = new html_table();
    $t->attributes['class'] = 'tt-table';
    $t->head = array_map('s', $s['head']);
    foreach (array_slice($s['rows'], 0, 8) as $r) {
        $t->data[] = array_map(fn($v) => s((string)$v), $r);
    }
    echo html_writer::div(html_writer::table($t), 'tt-tablewrap');
}

// One-click downloads per school, programme and supervisor.
$block = function (string $title, array $items, callable $params) use ($dl): string {
    if (!$items) {
        return '';
    }
    $li = '';
    foreach ($items as $id => $label) {
        $li .= html_writer::div(html_writer::span(s($label), 'mr-2') .
            html_writer::link($dl($params($id), 'xlsx'), 'Excel', ['class' => 'btn btn-sm btn-outline-primary mr-1']) .
            html_writer::link($dl($params($id), 'csv'), 'CSV', ['class' => 'btn btn-sm btn-outline-secondary']), 'tt-quick');
    }
    return html_writer::tag('details', html_writer::tag('summary', s($title), ['class' => 'tt-strong']) . $li, ['class' => 'tt-card']);
};
echo html_writer::tag('h5', get_string('grades_quick', 'local_researchcentral'), ['class' => 'mt-4']);
if ($iscoord) {
    echo $block(get_string('grades_perschool', 'local_researchcentral'), portal::schools(true), fn($id) => ['schoolid' => $id]);
}
echo $block(get_string('grades_perprog', 'local_researchcentral'), $progopts, fn($id) => ['progid' => $id]);
if ($iscoord) {
    echo $block(get_string('grades_persup', 'local_researchcentral'), $supopts, fn($id) => ['supervisorid' => $id]);
}
echo $OUTPUT->footer();
