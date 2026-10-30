<?php
// Leadership dashboard for deans and the DVC: throughput, time to completion, supervisor load and bottlenecks.
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_researchcentral\local\leadership;
use local_researchcentral\local\portal;
use local_researchcentral\output\ui;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
portal::require_coordinator();
$period = optional_param('period', '', PARAM_ALPHANUMEXT);
$schoolid = optional_param('schoolid', 0, PARAM_INT);
$fmt = optional_param('fmt', '', PARAM_ALPHA);
$refresh = optional_param('refresh', 0, PARAM_INT);
if ($refresh || $fmt === 'pdf') {
    require_sesskey();
}
$periods = leadership::periods();
if (!isset($periods[$period])) {
    $period = '';
}
$managed = portal::managed_schools();
if ($schoolid && !portal::can_manage_school($schoolid)) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('role_coordinator', 'local_researchcentral'));
}
$scope = $schoolid ? [$schoolid] : $managed;
[$from, $to] = leadership::period($period);
$L = fn(string $k, $a = null) => get_string($k, 'local_researchcentral', $a);
$d = leadership::data($scope, $from, $to, (bool)$refresh);
$scopename = $schoolid ? (portal::schools()[$schoolid] ?? '-') : ($managed === null ? $L('all_schools') : implode(', ', array_intersect_key(portal::schools(), array_flip($managed))));

if ($fmt === 'pdf') {
    $tmp = make_request_directory() . '/' . clean_filename($L('lead_title') . '_' . ($period ?: 'last12') . '.pdf');
    file_put_contents($tmp, leadership::pdf($d, $scopename, $periods[$period]));
    send_temp_file($tmp, basename($tmp));
}

$url = new moodle_url('/local/researchcentral/leadership.php', ['period' => $period, 'schoolid' => $schoolid]);
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title($L('lead_title'));
$PAGE->set_heading($L('pluginname'));
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/portal.php', ['view' => 'coordinator']), '← ' . $L('back'), ['class' => 'tt-back']);
echo $OUTPUT->heading($L('lead_title'), 3);
$schools = $managed === null ? portal::schools() : array_intersect_key(portal::schools(), array_flip($managed));
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out_omit_querystring(), 'class' => 'tt-filters']) .
    html_writer::select($periods, 'period', $period, false, ['class' => 'custom-select', 'onchange' => 'this.form.submit()', 'aria-label' => $L('lead_period')]) .
    (count($schools) > 1 ? html_writer::select($schools, 'schoolid', $schoolid, $L('all_schools'), ['class' => 'custom-select', 'onchange' => 'this.form.submit()']) : '') .
    html_writer::link(new moodle_url($url, ['fmt' => 'pdf', 'sesskey' => sesskey()]), '⬇ ' . $L('lead_pdf'), ['class' => 'btn btn-primary']) .
    html_writer::link(new moodle_url($url, ['refresh' => 1, 'sesskey' => sesskey()]), $L('lead_refresh'), ['class' => 'btn btn-outline-secondary']) .
    html_writer::end_tag('form');
echo html_writer::div($L('lead_built', userdate($d['built'], get_string('strftimedatetimeshort', 'langconfig'))), 'tt-sub mb-3');
$n1 = fn($v) => $v === null ? '-' : format_float($v, 1);
echo ui::stats([
    ui::stat((string)$d['completed_n'], $L('lead_completed')),
    ui::stat((string)$d['active_n'], $L('lead_active')),
    ui::stat((string)$d['overdue_n'], $L('lead_overdue'), $d['overdue_n'] ? 'tt-stat-warn' : ''),
    ui::stat($n1($d['ttc_median'] !== null ? $d['ttc_median'] / 30.4 : null), $L('lead_ttc') . ' (' . $L('lead_months') . ')'),
    ui::stat($n1($d['first_response']), $L('lead_firstresp') . ' (' . $L('lead_days') . ')'),
    ui::stat($n1($d['decide_days']), $L('lead_decide') . ' (' . $L('lead_days') . ')'),
]);

echo html_writer::tag('h5', $L('lead_by_school'), ['class' => 'mt-4']);
$t = new html_table();
$t->attributes['class'] = 'tt-table';
$t->head = [$L('school'), $L('lead_started'), $L('lead_active'), $L('lead_completed'), $L('lead_overdue'), $L('lead_inreview')];
foreach ($d['schools'] as $s) {
    $t->data[] = [s($s['name']), $s['started'], $s['active'], $s['completed'], $s['overdue'], $s['review']];
}
echo html_writer::div(html_writer::table($t), 'tt-tablewrap');

if ($d['levels']) {
    echo html_writer::tag('h5', $L('lead_by_level'), ['class' => 'mt-4']);
    $t = new html_table();
    $t->attributes['class'] = 'tt-table';
    $t->head = [$L('level'), $L('lead_active'), $L('lead_completed'), $L('lead_ttc') . ' (' . $L('lead_months') . ')'];
    foreach ($d['levels'] as $lv => $x) {
        $t->data[] = [s($L('level_' . $lv)), $x['active'], $x['completed'], $n1($x['median'] !== null ? $x['median'] / 30.4 : null)];
    }
    echo html_writer::div(html_writer::table($t), 'tt-tablewrap');
}

echo html_writer::tag('h5', $L('lead_bottlenecks'), ['class' => 'mt-4']);
echo html_writer::div($L('lead_bottlenecks_help'), 'tt-sub mb-2');
$t = new html_table();
$t->attributes['class'] = 'tt-table';
$t->head = [$L('stage'), $L('lead_overdue'), $L('lead_waiting'), $L('lead_revision'), $L('lead_approved')];
foreach (array_slice($d['stages'], 0, 15) as $s) {
    $t->data[] = [format_string($s['name']), $s['overdue'], $s['waiting'], $s['revision'], ui::bar($s['approved'], $s['total'])];
}
echo html_writer::div(html_writer::table($t), 'tt-tablewrap');

echo html_writer::tag('h5', $L('lead_load'), ['class' => 'mt-4']);
echo html_writer::div($L('lead_load_note', (object)['cap' => $d['load_cap'], 'n' => $d['load_over_n']]), 'tt-sub mb-2');
$t = new html_table();
$t->attributes['class'] = 'tt-table';
$t->head = [$L('supervisor'), $L('lead_theses'), $L('lead_as_lead')];
foreach ($d['load'] as $s) {
    $t->data[] = [s($s['name']) . ($s['over'] ? ' ' . html_writer::span($L('lead_over'), 'tt-chk tt-chk-no') : ''), $s['n'], $s['lead']];
}
echo html_writer::div(html_writer::table($t), 'tt-tablewrap');

if ($d['monthly']) {
    echo html_writer::tag('h5', $L('lead_monthly'), ['class' => 'mt-4']);
    $max = max($d['monthly']);
    $bars = '';
    foreach ($d['monthly'] as $mo => $n) {
        $bars .= html_writer::div(html_writer::span($mo, 'mr-2') . html_writer::span('', '', ['style' => 'display:inline-block;height:12px;background:var(--tt-blue);width:' . max(2, (int)round(220 * $n / $max)) . 'px']) . ' ' . $n, 'mb-1');
    }
    echo html_writer::div($bars, 'tt-card');
}
echo $OUTPUT->footer();
