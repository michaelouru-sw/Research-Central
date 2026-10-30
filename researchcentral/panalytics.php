<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\portal;
use local_researchcentral\output\ui;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
portal::require_coordinator();
$url = new moodle_url('/local/researchcentral/panalytics.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('analytics', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

// Filters.
$f = [
    'schoolid' => optional_param('schoolid', 0, PARAM_INT),
    'level' => optional_param('level', '', PARAM_ALPHA),
    'progid' => optional_param('progid', 0, PARAM_INT),
    'supervisorid' => optional_param('supervisorid', 0, PARAM_INT),
    'state' => optional_param('state', '', PARAM_ALPHA),
];
$since = optional_param('since', 0, PARAM_INT); // Months back for the activity charts (0 = all).

$all = portal::projects('coordinator');
$projects = portal::projects('coordinator', $f);
$now = time();

// Filter option lists (from all projects, so options never vanish).
$progopts = [];
$supopts = [];
foreach ($all as $p) {
    $progopts[$p->progid] = $p->progname;
    $supopts[$p->supervisorid] = ui::user_line($p->supervisor);
}
asort($progopts);
asort($supopts);
$selfilter = function (string $name, array $opts, $val, string $nothing) {
    return html_writer::select($opts, $name, $val, $nothing, ['class' => 'custom-select', 'onchange' => 'this.form.submit()']);
};
$states = [];
foreach (['active', 'review', 'revision', 'overdue', 'completed'] as $sx) {
    $states[$sx] = get_string('state_' . $sx, 'local_researchcentral');
}

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/portal.php', ['view' => 'coordinator']), '← ' . get_string('back', 'local_researchcentral'), ['class' => 'tt-back']);
echo $OUTPUT->heading(get_string('analytics', 'local_researchcentral'), 3);

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out(false), 'class' => 'tt-filters']);
echo $selfilter('schoolid', portal::schools(true), $f['schoolid'], get_string('all_schools', 'local_researchcentral'));
echo $selfilter('level', \local_researchcentral\local\tracker::levels(), $f['level'], get_string('level_all', 'local_researchcentral'));
echo $selfilter('progid', $progopts, $f['progid'], get_string('all_programmes', 'local_researchcentral'));
echo $selfilter('supervisorid', $supopts, $f['supervisorid'], get_string('all_supervisors', 'local_researchcentral'));
echo $selfilter('state', $states, $f['state'], get_string('all'));
echo $selfilter('since', [3 => get_string('last_months', 'local_researchcentral', 3), 6 => get_string('last_months', 'local_researchcentral', 6),
    12 => get_string('last_months', 'local_researchcentral', 12)], $since, get_string('all_time', 'local_researchcentral'));
echo html_writer::link($url, get_string('reset_filters', 'local_researchcentral'), ['class' => 'btn btn-outline-secondary']);
echo html_writer::link(new moodle_url('/local/researchcentral/dataset.php', array_filter($f)), get_string('dataset', 'local_researchcentral'), ['class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

if (!$projects) {
    echo html_writer::div(get_string('no_projects', 'local_researchcentral'), 'tt-empty');
    echo $OUTPUT->footer();
    exit;
}

// Gather data once.
$ids = array_keys($projects);
$cut = $since ? strtotime("-{$since} months", $now) : 0;
[$in, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
$vers = $DB->get_records_sql("SELECT v.id, v.pmsid, v.version, v.timecreated, m.projid, m.stageid, m.duedate, m.status, m.timesubmitted,
                                     m.timedecided, m.grade, s.name AS stagename, s.maxgrade, s.sortorder
                                FROM {local_researchcentral_ver} v
                                JOIN {local_researchcentral_pms} m ON m.id = v.pmsid
                                JOIN {local_researchcentral_stage} s ON s.id = m.stageid
                               WHERE m.projid $in", $params);

// KPIs.
$counts = ['active' => 0, 'review' => 0, 'revision' => 0, 'overdue' => 0, 'completed' => 0];
$overall = [];
foreach ($projects as $p) {
    $counts[$p->state]++;
    if ($ov = portal::overall($p->ms)) {
        $overall[$p->id] = $ov['percent'];
    }
}
$firstver = [];
$late = 0;
$subs = 0;
foreach ($vers as $v) {
    if ($cut && $v->timecreated < $cut) {
        continue;
    }
    $subs++;
    $late += ($v->duedate && $v->timecreated > $v->duedate) ? 1 : 0;
}
$turn = [];
foreach ($projects as $p) {
    foreach ($p->ms as $m) {
        if ($m->timesubmitted && $m->timedecided && $m->timedecided > $m->timesubmitted && (!$cut || $m->timedecided >= $cut)) {
            $turn[] = ($m->timedecided - $m->timesubmitted) / DAYSECS;
        }
    }
}
$fmt1 = fn($x) => format_float($x, 1);
echo ui::stats([
    ui::stat((string)count($projects), get_string('stat_students', 'local_researchcentral')),
    ui::stat((string)$counts['overdue'], get_string('state_overdue', 'local_researchcentral'), $counts['overdue'] ? 'tt-bad' : ''),
    ui::stat($overall ? $fmt1(array_sum($overall) / count($overall)) . '%' : '-', get_string('kpi_avgmark', 'local_researchcentral')),
    ui::stat($turn ? $fmt1(array_sum($turn) / count($turn)) . 'd' : '-', get_string('kpi_turnaround', 'local_researchcentral')),
    ui::stat($subs ? round(100 * $late / $subs) . '%' : '-', get_string('kpi_late', 'local_researchcentral'), $subs && $late / $subs > .3 ? 'tt-hot' : ''),
    ui::stat(count($projects) ? round(100 * $counts['completed'] / count($projects)) . '%' : '-', get_string('kpi_completion', 'local_researchcentral')),
]);

$colors = ['approved' => '#2f9e63', 'submitted' => '#d69e2e', 'revision' => '#dd6b20', 'pending' => '#9fb3c8', 'overdue' => '#d64545',
    'active' => '#3b82c4', 'review' => '#d69e2e', 'completed' => '#2f9e63'];
$panel = function (string $title, string $html, string $sub = '') {
    echo html_writer::div(html_writer::tag('h5', $title) . ($sub ? html_writer::div($sub, 'tt-sub mb-2') : '') . $html, 'tt-card tt-chart');
};
$render = fn($chart) => $OUTPUT->render($chart);

echo html_writer::start_div('tt-chartgrid');

// 1. Project status (doughnut).
$c = new \core\chart_pie();
$c->set_doughnut(true);
$series = new \core\chart_series(get_string('stat_students', 'local_researchcentral'), array_values($counts));
$series->set_colors(array_map(fn($k) => $colors[$k], array_keys($counts)));
$c->add_series($series);
$c->set_labels(array_map(fn($k) => get_string('state_' . $k, 'local_researchcentral'), array_keys($counts)));
$panel(get_string('ch_status', 'local_researchcentral'), $render($c), get_string('ch_hint', 'local_researchcentral'));

// 2. Pipeline: where theses are stuck (stacked by stage).
$order = [];
$stagenames = [];
$by = [];
foreach ($projects as $p) {
    foreach ($p->ms as $m) {
        $k = $m->stagename;
        $order[$k] = min($order[$k] ?? PHP_INT_MAX, (int)$m->sortorder);
        $stagenames[$k] = $m->stagename;
        $st = portal::is_overdue($m) ? 'overdue' : $m->status;
        $by[$k][$st] = ($by[$k][$st] ?? 0) + 1;
    }
}
uksort($stagenames, fn($a, $b) => $order[$a] <=> $order[$b]);
$c = new \core\chart_bar();
$c->set_stacked(true);
$c->set_horizontal(true);
foreach (['approved', 'submitted', 'revision', 'pending', 'overdue'] as $st) {
    $s = new \core\chart_series(get_string('state_' . $st, 'local_researchcentral'),
        array_map(fn($k) => $by[$k][$st] ?? 0, array_keys($stagenames)));
    $s->set_color($colors[$st]);
    $c->add_series($s);
}
$c->set_labels(array_values($stagenames));
$panel(get_string('an_bottlenecks', 'local_researchcentral'), $render($c), get_string('ch_hint', 'local_researchcentral'));

// 3. Supervisor workload.
$load = [];
foreach ($projects as $p) {
    $k = $p->supervisorid;
    $load[$k] = $load[$k] ?? ['name' => ui::user_line($p->supervisor), 'n' => 0, 'overdue' => 0, 'review' => 0];
    $load[$k]['n']++;
    $load[$k]['overdue'] += $p->overdue > 0 ? 1 : 0;
    $load[$k]['review'] += $p->review;
}
uasort($load, fn($a, $b) => $b['n'] <=> $a['n']);
$load = array_slice($load, 0, 15, true);
$c = new \core\chart_bar();
foreach ([['n', 'stat_students', '#3b82c4'], ['review', 'stat_awaiting', '#d69e2e'], ['overdue', 'state_overdue', '#d64545']] as [$key, $str, $col]) {
    $s = new \core\chart_series(get_string($str, 'local_researchcentral'), array_column($load, $key));
    if ($col !== '#3b82c4') {   // the main series takes the theme's own chart colour
        $s->set_color($col);
    }
    $c->add_series($s);
}
$c->set_labels(array_column($load, 'name'));
$panel(get_string('an_supervisors', 'local_researchcentral'), $render($c), get_string('ch_top15', 'local_researchcentral'));

// 4. Students by school and level.
$sl = [];
$levels = [];
foreach ($projects as $p) {
    $sn = $p->school ?: '-';
    $lv = $p->level ?: 'masters';
    $sl[$sn][$lv] = ($sl[$sn][$lv] ?? 0) + 1;
    $levels[$lv] = true;
}
ksort($sl);
$c = new \core\chart_bar();
$c->set_stacked(true);
$lvcol = ['diploma' => '#a9c4e8', 'ug' => '#7c9fd6', 'masters' => '#3b6fb6', 'phd' => '#1f3d6e'];
foreach (array_keys($levels) as $lv) {
    $s = new \core\chart_series(get_string('level_' . $lv, 'local_researchcentral'), array_map(fn($sn) => $sl[$sn][$lv] ?? 0, array_keys($sl)));
    $s->set_color($lvcol[$lv] ?? '#3b6fb6');
    $c->add_series($s);
}
$c->set_labels(array_keys($sl));
$panel(get_string('an_schools', 'local_researchcentral'), $render($c));

// 5. Average mark per stage.
$marks = [];
foreach ($projects as $p) {
    foreach ($p->ms as $m) {
        if ($m->maxgrade > 0 && $m->grade !== null) {
            $marks[$m->stagename][] = 100 * (float)$m->grade / (int)$m->maxgrade;
        }
    }
}
uksort($marks, fn($a, $b) => ($order[$a] ?? 0) <=> ($order[$b] ?? 0));
if ($marks) {
    $c = new \core\chart_bar();
    $s = new \core\chart_series(get_string('kpi_avgmark', 'local_researchcentral'),
        array_map(fn($a) => round(array_sum($a) / count($a), 1), array_values($marks)));
    $c->add_series($s);
    $c->set_labels(array_keys($marks));
    $y = $c->get_yaxis(0, true);
    $y->set_min(0);
    $y->set_max(100);
    $panel(get_string('ch_marks_stage', 'local_researchcentral'), $render($c));

    // 6. Overall mark distribution.
    $buckets = ['<40' => 0, '40-49' => 0, '50-59' => 0, '60-69' => 0, '70+' => 0];
    foreach ($overall as $pc) {
        $buckets[$pc < 40 ? '<40' : ($pc < 50 ? '40-49' : ($pc < 60 ? '50-59' : ($pc < 70 ? '60-69' : '70+')))]++;
    }
    $c = new \core\chart_bar();
    $s = new \core\chart_series(get_string('stat_students', 'local_researchcentral'), array_values($buckets));
    $s->set_color('#2f9e63');
    $c->add_series($s);
    $c->set_labels(array_keys($buckets));
    $panel(get_string('ch_marks_dist', 'local_researchcentral'), $render($c));
}

// 7. Review turnaround per stage (days).
$tr = [];
foreach ($projects as $p) {
    foreach ($p->ms as $m) {
        if ($m->timesubmitted && $m->timedecided > $m->timesubmitted && (!$cut || $m->timedecided >= $cut)) {
            $tr[$m->stagename][] = ($m->timedecided - $m->timesubmitted) / DAYSECS;
        }
    }
}
uksort($tr, fn($a, $b) => ($order[$a] ?? 0) <=> ($order[$b] ?? 0));
if ($tr) {
    $c = new \core\chart_bar();
    $s = new \core\chart_series(get_string('kpi_turnaround', 'local_researchcentral'),
        array_map(fn($a) => round(array_sum($a) / count($a), 1), array_values($tr)));
    $s->set_color('#d69e2e');
    $c->add_series($s);
    $c->set_labels(array_keys($tr));
    $panel(get_string('ch_turnaround', 'local_researchcentral'), $render($c), get_string('ch_turnaround_hint', 'local_researchcentral'));
}

// 8. Submissions per month, on time vs late.
$months = [];
for ($i = 11; $i >= 0; $i--) {
    $months[date('Y-m', strtotime("first day of -$i months", $now))] = ['ontime' => 0, 'late' => 0];
}
foreach ($vers as $v) {
    $k = date('Y-m', $v->timecreated);
    if (isset($months[$k])) {
        $months[$k][($v->duedate && $v->timecreated > $v->duedate) ? 'late' : 'ontime']++;
    }
}
$c = new \core\chart_line();
$c->set_smooth(true);
foreach ([['ontime', 'ch_ontime', '#2f9e63'], ['late', 'late', '#d64545']] as [$key, $str, $col]) {
    $s = new \core\chart_series(get_string($str, 'local_researchcentral'), array_column($months, $key));
    $s->set_color($col);
    $c->add_series($s);
}
$c->set_labels(array_keys($months));
$panel(get_string('ch_submissions', 'local_researchcentral'), $render($c), get_string('ch_last12', 'local_researchcentral'));

echo html_writer::end_div();

// At-risk students: 2+ overdue stages, or nothing submitted for 30 days while a stage is open.
$risk = [];
foreach ($projects as $p) {
    if ($p->state === 'completed') {
        continue;
    }
    $last = 0;
    foreach ($p->ms as $m) {
        $last = max($last, (int)$m->timesubmitted);
    }
    $last = max($last, (int)$p->startdate);
    $why = [];
    if ($p->overdue >= 2) {
        $why[] = get_string('risk_overdue', 'local_researchcentral', $p->overdue);
    }
    if ($now - $last > 30 * DAYSECS) {
        $why[] = get_string('risk_inactive', 'local_researchcentral', (int)floor(($now - $last) / DAYSECS));
    }
    if ($why) {
        $risk[] = [$p, implode(' · ', $why), $p->overdue * 100 + min(99, (int)(($now - $last) / DAYSECS))];
    }
}
usort($risk, fn($a, $b) => $b[2] <=> $a[2]);
echo html_writer::tag('h5', get_string('at_risk', 'local_researchcentral'), ['class' => 'mt-4']);
if (!$risk) {
    echo html_writer::div(get_string('no_risk', 'local_researchcentral'), 'tt-empty');
} else {
    $t = new html_table();
    $t->attributes['class'] = 'tt-table';
    $t->head = [get_string('student', 'local_researchcentral'), get_string('supervisor', 'local_researchcentral'),
        get_string('current_stage', 'local_researchcentral'), get_string('risk_reason', 'local_researchcentral'), ''];
    foreach (array_slice($risk, 0, 25) as [$p, $why]) {
        $t->data[] = [html_writer::div(ui::user_line($p->student), 'tt-strong') . html_writer::div(s($p->school ?? ''), 'tt-sub'),
            ui::user_line($p->supervisor), $p->current ? format_string($p->current->stagename) : '-', s($why),
            html_writer::link(new moodle_url('/local/researchcentral/project.php', ['id' => $p->id]), get_string('open', 'local_researchcentral'),
                ['class' => 'btn btn-sm btn-outline-primary'])];
    }
    echo html_writer::table($t);
}
// Supervisor response time and meeting verification.
$sla = (int)portal::cfg('review_sla_days', 7);
[$rin, $rparams] = $DB->get_in_or_equal(array_keys($projects), SQL_PARAMS_NAMED, 'rp');
$rows = [];
$bump = function (int $uid) use (&$rows) {
    $rows[$uid] = $rows[$uid] ?? ['n' => 0, 'first' => [], 'dec' => [], 'within' => 0, 'wait' => 0, 'late' => 0, 'mt' => 0, 'mv' => 0];
};
foreach ($DB->get_records_select('local_researchcentral_decision', "projid $rin AND timesubmitted > 0", $rparams) as $d) {
    $uid = (int)$projects[$d->projid]->supervisorid;
    $bump($uid);
    $rows[$uid]['n']++;
    $rows[$uid]['first'][] = max(0, $d->firstresponse - $d->timesubmitted) / DAYSECS;
    $dd = max(0, $d->timecreated - $d->timesubmitted) / DAYSECS;
    $rows[$uid]['dec'][] = $dd;
    $rows[$uid]['within'] += (!$sla || $dd <= $sla) ? 1 : 0;
}
foreach ($projects as $pr) {
    foreach ($pr->ms as $m) {
        if ($m->status === 'submitted' && $m->timesubmitted) {
            $uid = (int)$pr->supervisorid;
            $bump($uid);
            $rows[$uid]['wait']++;
            $rows[$uid]['late'] += ($sla && (time() - $m->timesubmitted) > $sla * DAYSECS) ? 1 : 0;
        }
    }
}
foreach ($DB->get_records_select('local_researchcentral_appt', "projid $rin AND studentid > 0 AND status IN ('booked','done') AND endtime < :n", $rparams + ['n' => time()]) as $a) {
    $bump((int)$a->supervisorid);
    $rows[(int)$a->supervisorid]['mt']++;
    $rows[(int)$a->supervisorid]['mv'] += local_researchcentral\local\appt::verification($a) === 'verified' ? 1 : 0;
}
echo html_writer::tag('h5', get_string('resp_title', 'local_researchcentral'), ['class' => 'mt-4']);
echo html_writer::div(get_string('resp_sub', 'local_researchcentral', $sla), 'tt-sub mb-2');
if (!$rows) {
    echo html_writer::div(get_string('no_projects', 'local_researchcentral'), 'tt-empty');
} else {
    $sus = $DB->get_records_list('user', 'id', array_keys($rows));
    $avg = fn(array $v) => $v ? format_float(array_sum($v) / count($v), 1) : '-';
    uasort($rows, fn($a, $b) => $b['late'] <=> $a['late']);
    $t = new html_table();
    $t->attributes['class'] = 'tt-table';
    $t->head = [get_string('supervisor', 'local_researchcentral'), get_string('resp_decisions', 'local_researchcentral'), get_string('resp_first', 'local_researchcentral'),
        get_string('resp_decision', 'local_researchcentral'), get_string('resp_within', 'local_researchcentral', $sla), get_string('resp_waiting', 'local_researchcentral'),
        get_string('resp_late', 'local_researchcentral'), get_string('resp_meetings', 'local_researchcentral')];
    foreach ($rows as $uid => $r) {
        $t->data[] = [isset($sus[$uid]) ? s(fullname($sus[$uid])) : '-', $r['n'], $avg($r['first']), $avg($r['dec']),
            $r['n'] ? round(100 * $r['within'] / $r['n']) . '%' : '-', $r['wait'],
            $r['late'] ? html_writer::span((string)$r['late'], 'tt-chk tt-chk-fail') : '0',
            $r['mt'] ? round(100 * $r['mv'] / $r['mt']) . '% (' . $r['mv'] . '/' . $r['mt'] . ')' : '-'];
    }
    echo html_writer::div(html_writer::table($t), 'tt-tablewrap');
}
echo $OUTPUT->footer();
