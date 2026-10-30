<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\tracker;

$id = required_param('id', PARAM_INT);
$course = get_course($id);
\local_researchcentral\local\portal::gate();
require_login($course);
$context = context_course::instance($id);
\local_researchcentral\local\tracker::require_enabled($id);
if (!tracker::get_roles((int)$USER->id, $id)['manager']) {
    require_capability('local/researchcentral:viewanalytics', $context);
}

$PAGE->set_url('/local/researchcentral/analytics.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('analytics', 'local_researchcentral'));
$PAGE->set_heading($course->fullname);

tracker::sync_course($id);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('analytics', 'local_researchcentral'));
echo html_writer::link(new moodle_url('/local/researchcentral/index.php', ['id' => $id]),
    get_string('back', 'local_researchcentral'), ['class' => 'd-block mb-3']);

// 1. Status split.
$counts = tracker::status_counts($id);
echo $OUTPUT->heading(get_string('analytics_status', 'local_researchcentral'), 4);
if (array_sum($counts) === 0) {
    echo $OUTPUT->notification(get_string('nodata', 'local_researchcentral'), 'info');
} else {
    $pie = new \core\chart_pie();
    $pie->add_series(new \core\chart_series(get_string('analytics_status', 'local_researchcentral'),
        [$counts[tracker::COMPLETE], $counts[tracker::PENDING], $counts[tracker::OVERDUE]]));
    $pie->set_labels([get_string('status_1', 'local_researchcentral'), get_string('status_0', 'local_researchcentral'),
        get_string('status_2', 'local_researchcentral')]);
    echo $OUTPUT->render($pie);
}

// 2. Stage bottlenecks.
$stages = tracker::stage_stats($id);
echo $OUTPUT->heading(get_string('analytics_stages', 'local_researchcentral'), 4);
if ($stages) {
    $bar = new \core\chart_bar();
    $bar->set_stacked(true);
    $bar->add_series(new \core\chart_series(get_string('status_1', 'local_researchcentral'),
        array_map(fn($s) => (int)$s->done, array_values($stages))));
    $bar->add_series(new \core\chart_series(get_string('status_0', 'local_researchcentral'),
        array_map(fn($s) => (int)$s->pending, array_values($stages))));
    $bar->add_series(new \core\chart_series(get_string('status_2', 'local_researchcentral'),
        array_map(fn($s) => (int)$s->overdue, array_values($stages))));
    $bar->set_labels(array_map(fn($s) => format_string($s->name), array_values($stages)));
    echo $OUTPUT->render($bar);
} else {
    echo $OUTPUT->notification(get_string('nodata', 'local_researchcentral'), 'info');
}

// 3. Supervisor workload.
echo $OUTPUT->heading(get_string('analytics_supervisors', 'local_researchcentral'), 4);
$load = tracker::supervisor_load($id);
if ($load) {
    $table = new html_table();
    $table->head = [get_string('supervisor', 'local_researchcentral'), get_string('theses', 'local_researchcentral'),
        get_string('done', 'local_researchcentral'), get_string('overdue', 'local_researchcentral')];
    $table->attributes['class'] = 'table table-striped generaltable';
    foreach ($load as $r) {
        $u = core_user::get_user($r->supervisorid);
        $table->data[] = [$u ? fullname($u) : '-', (int)$r->theses, "{$r->done}/{$r->total}", (int)$r->overdue];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nodata', 'local_researchcentral'), 'info');
}

// 4. Turnaround per stage.
echo $OUTPUT->heading(get_string('analytics_turnaround', 'local_researchcentral'), 4);
$avg = tracker::avg_days_to_complete($id);
if ($avg) {
    $table = new html_table();
    $table->head = [get_string('milestone', 'local_researchcentral'), get_string('avgdays', 'local_researchcentral'),
        get_string('samplesize', 'local_researchcentral')];
    $table->attributes['class'] = 'table table-striped generaltable';
    foreach ($avg as $a) {
        $table->data[] = [format_string($a['name']), $a['avg'], $a['n']];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nodata', 'local_researchcentral'), 'info');
}

// 5. Document checks.
echo $OUTPUT->heading(get_string('analytics_doccheck', 'local_researchcentral'), 4);
$dc = tracker::doc_check_summary($id);
if ($dc['stages']) {
    $table = new html_table();
    $table->head = [get_string('milestone', 'local_researchcentral'), get_string('overall_pass', 'local_researchcentral'),
        get_string('overall_warn', 'local_researchcentral'), get_string('overall_fail', 'local_researchcentral')];
    $table->attributes['class'] = 'table table-striped generaltable';
    foreach ($dc['stages'] as $s) {
        $table->data[] = [format_string($s['name']), (int)($s['pass'] ?? 0), (int)($s['warn'] ?? 0), (int)($s['fail'] ?? 0)];
    }
    echo html_writer::table($table);
    if ($dc['issues']) {
        echo html_writer::tag('p', html_writer::tag('strong', get_string('analytics_topissues', 'local_researchcentral')) . ': ' .
            implode(', ', array_map(function ($k, $n) {
                return get_string('chk_' . $k, 'local_researchcentral') . " ($n)";
            }, array_keys(array_slice($dc['issues'], 0, 5, true)), array_slice($dc['issues'], 0, 5, true))));
    }
} else {
    echo $OUTPUT->notification(get_string('nodata', 'local_researchcentral'), 'info');
}

// 6. Level split.
echo $OUTPUT->heading(get_string('analytics_levels', 'local_researchcentral'), 4);
$levels = tracker::level_counts($id);
if ($levels) {
    $labels = tracker::levels();
    $table = new html_table();
    $table->head = [get_string('level', 'local_researchcentral'), get_string('theses', 'local_researchcentral')];
    $table->attributes['class'] = 'table table-striped generaltable';
    foreach ($levels as $l) {
        $table->data[] = [$labels[$l->level] ?? s($l->level), (int)$l->cnt];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
