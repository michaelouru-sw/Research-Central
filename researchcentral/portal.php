<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\portal;
use local_researchcentral\output\ui;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$context = context_system::instance();
$roles = portal::roles();

$PAGE->set_context($context);
$PAGE->set_url('/local/researchcentral/portal.php');
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('pluginname', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

echo $OUTPUT->header();
if (!$roles) {
    echo $OUTPUT->notification(get_string('portal_nothing', 'local_researchcentral'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$view = optional_param('view', $roles[0], PARAM_ALPHA);
if (!in_array($view, $roles)) {
    $view = $roles[0];
}
$base = new moodle_url('/local/researchcentral/portal.php');
echo '<div class="tt-toprow">';
if (count($roles) >= 1) {
    $labels = [];
    foreach ($roles as $r) {
        $labels[$r] = get_string('role_' . $r, 'local_researchcentral');
    }
    echo ui::tabs($labels, $view, $base);
}
$unread = \local_researchcentral\local\forums::total_unread();
$cancomment = in_array('supervisor', $roles, true) || in_array('coordinator', $roles, true);
echo html_writer::div(ui::menu(get_string('ux_community', 'local_researchcentral') . ($unread ? ' (' . $unread . ')' : ''), [
    [get_string('forums', 'local_researchcentral') . ($unread ? ' (' . $unread . ')' : ''), new moodle_url('/local/researchcentral/forums.php')],
    [get_string('seminars', 'local_researchcentral'), new moodle_url('/local/researchcentral/seminars.php')],
    $cancomment ? [get_string('comment_bank', 'local_researchcentral'), new moodle_url('/local/researchcentral/comments.php')] : null,
], 'btn-outline-secondary btn-sm') .
    html_writer::link(new moodle_url('/local/researchcentral/search.php'), '<i class="fa fa-search" aria-hidden="true"></i>',
        ['class' => 'btn btn-outline-secondary btn-sm tt-searchicon', 'title' => get_string('ux_search_go', 'local_researchcentral'),
         'aria-label' => get_string('ux_search_go', 'local_researchcentral')]), 'tt-community');
echo '</div>';

$riskmap = [];
/** Table of projects for supervisors and coordinators. */
$projecttable = function (array $projects, bool $showsupervisor) use ($USER, &$riskmap) {
    if (!$projects) {
        return html_writer::div(get_string('no_projects', 'local_researchcentral'), 'tt-empty');
    }
    $t = new html_table();
    $t->attributes['class'] = 'tt-table';
    $t->head = [get_string('student', 'local_researchcentral'), get_string('programme', 'local_researchcentral')];
    if ($showsupervisor) {
        $t->head[] = get_string('supervisor', 'local_researchcentral');
    }
    array_push($t->head, get_string('current_stage', 'local_researchcentral'), get_string('status', 'local_researchcentral'), get_string('risk_title', 'local_researchcentral'),
        get_string('progress', 'local_researchcentral'), get_string('next_due', 'local_researchcentral'), '');
    $unread = \local_researchcentral\local\receipts::unread((int)$USER->id, array_map(fn($x) => (int)$x->id, $projects));
    foreach ($projects as $p) {
        $badge = !empty($unread[$p->id]) ? ' ' . html_writer::link(new moodle_url('/local/researchcentral/project.php', ['id' => $p->id], 'discussion'),
            '✉ ' . get_string('rcpt_unread_n', 'local_researchcentral', $unread[$p->id]), ['class' => 'tt-newbadge']) : '';
        $name = html_writer::link(new moodle_url('/local/researchcentral/project.php', ['id' => $p->id]),
            ui::user_line($p->student), ['class' => 'tt-link']);
        $row = [$name . $badge . html_writer::div(format_string($p->title), 'tt-sub'),
            format_string($p->progname) . html_writer::div(s($p->school ?? '') , 'tt-sub')];
        if ($showsupervisor) {
            $row[] = ui::user_line($p->supervisor) . ($p->cosupervisors ? html_writer::div('+ ' . implode(', ', array_map(fn($u) => s(fullname($u)), $p->cosupervisors)), 'tt-sub') : '');
        }
        $row[] = $p->current ? format_string($p->current->stagename) : '-';
        $row[] = ui::pill($p->state);
        $rk = $riskmap[$p->id] ?? null;
        $row[] = $rk ? html_writer::span(get_string('risk_' . $rk['level'], 'local_researchcentral'), 'tt-risk tt-risk-' . $rk['level'],
            ['role' => 'img', 'aria-label' => get_string('risk_title', 'local_researchcentral') . ': ' . get_string('risk_' . $rk['level'], 'local_researchcentral'), 'title' => s(implode(', ', array_map(fn($x) => get_string('risk_sig_' . $x, 'local_researchcentral'), $rk['signals'])))]) : '-';
        $row[] = ui::bar((int)$p->done, (int)$p->total);
        $row[] = ($p->current && $p->current->duedate) ? ui::due((int)$p->current->duedate) : '-';
        $row[] = html_writer::link(new moodle_url('/local/researchcentral/project.php', ['id' => $p->id]),
            get_string('open', 'local_researchcentral'), ['class' => 'btn btn-sm btn-outline-primary']) . ' ' .
            html_writer::link(new moodle_url('/local/researchcentral/workspace.php', ['id' => $p->id]), get_string('ws_title', 'local_researchcentral'), ['class' => 'btn btn-sm btn-outline-secondary']);
        $t->data[] = $row;
    }
    return html_writer::div(html_writer::table($t), 'tt-tablewrap');
};

$stats = function (array $projects, bool $supervisorview = false) use ($USER, $DB, &$riskmap) {
    $c = ['review' => 0, 'overdue' => 0, 'completed' => 0];
    $mine = $late = 0;
    $sla = (int)portal::cfg('review_sla_days', 7);
    $reviewed = [];
    if ($supervisorview && $projects) {
        // One query instead of one per stage: stages where I already reviewed the current round.
        [$pin, $pp] = $DB->get_in_or_equal(array_keys($projects), SQL_PARAMS_NAMED);
        foreach ($DB->get_records_sql("SELECT r.id, r.pmsid FROM {local_researchcentral_review} r
                 JOIN {local_researchcentral_pms} m ON m.id = r.pmsid AND m.status = 'submitted'
                WHERE r.projid $pin AND r.userid = :u AND r.status = 'active'
                  AND r.round = 1 + (SELECT COUNT(d.id) FROM {local_researchcentral_decision} d WHERE d.pmsid = r.pmsid)", $pp + ['u' => $USER->id]) as $r) {
            $reviewed[(int)$r->pmsid] = true;
        }
    }
    foreach ($projects as $p) {
        if (isset($c[$p->state])) {
            $c[$p->state]++;
        }
        if ($supervisorview) {
            foreach ($p->ms as $m) {
                if ($m->status === portal::SUBMITTED && !isset($reviewed[(int)$m->id])) {
                    $mine++;
                    $late += ($sla && $m->timesubmitted && (time() - $m->timesubmitted) > $sla * DAYSECS) ? 1 : 0;
                }
            }
        }
    }
    $out = [
        ui::stat((string)count($projects), get_string('stat_students', 'local_researchcentral')),
        ui::stat((string)$c['review'], get_string('stat_awaiting', 'local_researchcentral'), $c['review'] ? 'tt-hot' : ''),
    ];
    if ($supervisorview) {
        $out[] = ui::stat((string)$mine, get_string('stat_myreviews', 'local_researchcentral'), $mine ? 'tt-hot' : '');
        $out[] = ui::stat((string)$late, get_string('stat_beyondsla', 'local_researchcentral', $sla), $late ? 'tt-bad' : '');
        $rs = \local_researchcentral\local\review::response_stats(array_keys($projects));
        $out[] = ui::stat($rs ? format_float(array_sum(array_column($rs, 'first')) / count($rs), 1) . 'd' : '-', get_string('stat_avgresponse', 'local_researchcentral'));
    }
    $high = count(array_filter($riskmap, fn($r) => $r['level'] === 'high'));
    $out[] = ui::stat((string)$high, get_string('risk_high', 'local_researchcentral'), $high ? 'tt-bad' : '');
    $out[] = ui::stat((string)$c['overdue'], get_string('stat_overdue', 'local_researchcentral'), $c['overdue'] ? 'tt-bad' : '');
    $out[] = ui::stat((string)$c['completed'], get_string('stat_completed', 'local_researchcentral'));
    return ui::stats($out);
};

if ($view === 'student') {
    echo $OUTPUT->heading(get_string('my_thesis', 'local_researchcentral'), 3);
    foreach (portal::projects('student') as $p) {
        $sup = $p->supervisor;
        $nunread = \local_researchcentral\local\receipts::unread((int)$USER->id, [(int)$p->id])[(int)$p->id] ?? 0;
        $teamhtml = html_writer::div(ui::user_line($sup) . ' ' . html_writer::span(get_string('lead_supervisor', 'local_researchcentral'), 'tt-chk tt-chk-pass'), 'tt-strong') .
            html_writer::div($sup ? s($sup->email) : '', 'tt-sub');
        foreach ($p->cosupervisors as $cs) {
            $teamhtml .= html_writer::div(ui::user_line($cs) . ' ' . html_writer::span(get_string('co_supervisor', 'local_researchcentral'), 'tt-chk tt-chk-warn'), 'tt-strong mt-1') .
                html_writer::div(s($cs->email), 'tt-sub');
        }
        $supcard = html_writer::div(
            html_writer::div(get_string($p->cosupervisors ? 'supervision_team' : 'my_supervisor', 'local_researchcentral'), 'tt-label') .
            $teamhtml .
            html_writer::div(
                ($sup ? html_writer::link(new moodle_url('/local/researchcentral/project.php', ['id' => $p->id], 'discussion'),
                    get_string('send_message', 'local_researchcentral') . ($nunread ? ' ✉ ' . $nunread : ''), ['class' => 'btn btn-sm btn-outline-secondary mr-2']) : '') .
                html_writer::link(new moodle_url('/local/researchcentral/appointments.php'),
                    get_string('book_appointment', 'local_researchcentral'), ['class' => 'btn btn-sm btn-primary']),
                'mt-2'), 'tt-card tt-side');
        $head = html_writer::div(
            html_writer::div(format_string($p->title ?: get_string('untitled', 'local_researchcentral')), 'tt-title') .
            html_writer::div(format_string($p->progname) . ' · ' . get_string('level_' . $p->level, 'local_researchcentral') .
                ($p->school ? ' · ' . s($p->school) : ''), 'tt-sub') .
            html_writer::div(ui::pill($p->state) . ' ' . ui::bar((int)$p->done, (int)$p->total), 'mt-2') .
            (($ov = \local_researchcentral\local\portal::overall($p->ms)) ? html_writer::div(
                get_string('overall_mark', 'local_researchcentral') . ': ' . html_writer::span(format_float($ov['percent'], 1) . '%', 'tt-strong'), 'mt-2') : ''),
            'tt-card tt-main');
        $nax = \local_researchcentral\local\research::next_actions($p, (int)$USER->id);
        $rank = ['high' => 0, 'med' => 1, 'info' => 2];
        usort($nax, fn($a, $b) => ($rank[$a['level']] ?? 3) <=> ($rank[$b['level']] ?? 3));
        if ($nax) {
            $top = $nax[0];
            $cur = $p->current;
            $btn = get_string('open', 'local_researchcentral');
            $url = $top['url'];
            if (in_array($top['key'], ['na_overdue', 'na_revise', 'na_due_soon'], true) && $cur && $cur->needsupload && in_array($cur->status, [portal::PENDING, portal::REVISION], true)) {
                $url = new moodle_url('/local/researchcentral/submit.php', ['pms' => $cur->id]);
                $btn = get_string('upload', 'local_researchcentral');
            }
            echo ui::hero(get_string('ux_next', 'local_researchcentral'), get_string($top['key'], 'local_researchcentral', $top['a']), $url, $btn, $top['level']);
        } else {
            echo ui::hero(get_string('ux_next', 'local_researchcentral'), get_string('ux_all_clear', 'local_researchcentral'), null, '', 'ok');
        }
        echo html_writer::div(html_writer::link(new moodle_url('/local/researchcentral/project.php', ['id' => $p->id]),
            get_string('open_submissions', 'local_researchcentral'), ['class' => 'btn btn-primary mr-2']) .
            html_writer::link(new moodle_url('/local/researchcentral/workspace.php', ['id' => $p->id]), get_string('ws_title', 'local_researchcentral'), ['class' => 'btn btn-outline-primary']), 'tt-quick mb-3');
        echo html_writer::div($head . $supcard, 'tt-grid');
        echo ui::pipeline($p->ms);
        echo ui::legend();

        $na = \local_researchcentral\local\research::next_actions($p, (int)$USER->id);
        if ($na) {
            $li = '';
            foreach (array_slice($na, 0, 4) as $x) {
                $li .= html_writer::div(html_writer::link($x['url'], get_string($x['key'], 'local_researchcentral', $x['a']), ['class' => 'tt-link']), 'tt-next tt-next-' . $x['level']);
            }
            echo html_writer::div(html_writer::tag('h5', get_string('na_title', 'local_researchcentral')) . $li, 'tt-card mb-3');
        }
    }
    // Upcoming appointments.
    $appts = $DB->get_records_select('local_researchcentral_appt', 'studentid = ? AND starttime > ? AND status = ?',
        [$USER->id, time(), 'booked'], 'starttime', '*', 0, 3);
    if ($appts) {
        echo $OUTPUT->heading(get_string('upcoming_appts', 'local_researchcentral'), 5);
        foreach ($appts as $a) {
            echo html_writer::div(userdate($a->starttime) . ' · ' . s($a->topic), 'tt-appt');
        }
    }
} else {
    $scope = $view === 'coordinator' ? 'coordinator' : 'supervisor';
    $filters = [
        'state' => optional_param('state', '', PARAM_ALPHA),
        'q' => optional_param('q', '', PARAM_TEXT),
    ];
    if ($scope === 'coordinator') {
        $filters['schoolid'] = optional_param('schoolid', 0, PARAM_INT);
        $filters['level'] = optional_param('level', '', PARAM_ALPHA);
        $filters['progid'] = optional_param('progid', 0, PARAM_INT);
    }
    if ($scope === 'coordinator' && ($ms = portal::managed_schools()) !== null) {
        $names = array_intersect_key(portal::schools(), array_flip($ms));
        echo html_writer::div(get_string('coord_scope', 'local_researchcentral', s(implode(', ', $names))), 'tt-banner');
    }
    $all = portal::projects($scope);
    $projects = portal::projects($scope, $filters);
    $riskmap = \local_researchcentral\local\risk::scores($projects);

    if ($scope === 'coordinator') {
        echo ui::actionbar([
            html_writer::link(new moodle_url('/local/researchcentral/newproject.php'), get_string('project_new', 'local_researchcentral'), ['class' => 'btn btn-primary']),
            html_writer::link(new moodle_url('/local/researchcentral/allocations.php'), get_string('allocations', 'local_researchcentral'), ['class' => 'btn btn-outline-secondary']),
        ], [
            ui::menu(get_string('ux_manage', 'local_researchcentral'), [
                [get_string('programmes', 'local_researchcentral'), new moodle_url('/local/researchcentral/programmes.php')], [get_string('bulk', 'local_researchcentral'), new moodle_url('/local/researchcentral/bulk.php')], [get_string('broadcast', 'local_researchcentral'), new moodle_url('/local/researchcentral/broadcast.php')], [get_string('eth_title', 'local_researchcentral'), new moodle_url('/local/researchcentral/ethics.php')],
                [get_string('repo_title', 'local_researchcentral'), new moodle_url('/local/researchcentral/repository.php')], [get_string('my_appointments', 'local_researchcentral'), new moodle_url('/local/researchcentral/appointments.php')],
                (is_siteadmin() ? [get_string('schools', 'local_researchcentral'), new moodle_url('/local/researchcentral/schools.php')] : null)]),
            ui::menu(get_string('ux_reports', 'local_researchcentral'), [
                [get_string('analytics', 'local_researchcentral'), new moodle_url('/local/researchcentral/panalytics.php')], [get_string('lead_title', 'local_researchcentral'), new moodle_url('/local/researchcentral/leadership.php')], [get_string('grades_export', 'local_researchcentral'), new moodle_url('/local/researchcentral/grades.php')], [get_string('feedback_archive', 'local_researchcentral'), new moodle_url('/local/researchcentral/feedback.php')], [get_string('export_csv', 'local_researchcentral'), new moodle_url('/local/researchcentral/export.php')], [get_string('dataset', 'local_researchcentral'), new moodle_url('/local/researchcentral/dataset.php')]]),
        ]);
    } else {
        echo ui::actionbar([
            html_writer::link(new moodle_url('/local/researchcentral/appointments.php'), get_string('my_appointments', 'local_researchcentral'), ['class' => 'btn btn-primary']),
        ], [
            ui::menu(get_string('ux_reports', 'local_researchcentral'), [[get_string('grades_export', 'local_researchcentral'), new moodle_url('/local/researchcentral/grades.php')]]),
        ]);
    }
    if ($scope === 'coordinator' && ($pend = portal::extensions_to_confirm())) {
        $pt = new html_table();
        $pt->attributes['class'] = 'tt-table';
        $pt->head = [get_string('student', 'local_researchcentral'), get_string('stage', 'local_researchcentral'), get_string('ext_newdate', 'local_researchcentral'),
            get_string('ext_endorsed_by', 'local_researchcentral'), ''];
        $pusers = $DB->get_records_list('user', 'id', array_merge(array_column($pend, 'userid'), array_column($pend, 'recommendedby')));
        foreach ($pend as $e) {
            $pt->data[] = [s(fullname($pusers[$e->userid])), format_string($e->stagename), userdate($e->requestdate, get_string('strftimedatefullshort', 'langconfig')),
                s(fullname($pusers[$e->recommendedby])) . html_writer::div(s($e->recommendnote), 'tt-sub'),
                html_writer::link(new moodle_url('/local/researchcentral/project.php', ['id' => $e->projid]), get_string('ext_confirm_request', 'local_researchcentral'),
                    ['class' => 'btn btn-sm btn-primary'])];
        }
        echo html_writer::div(html_writer::tag('h5', get_string('ext_awaiting_confirm', 'local_researchcentral', count($pend))) .
            html_writer::div(html_writer::table($pt), 'tt-tablewrap'), 'tt-card');
    }
    $strip = [];
    if ($scope === 'supervisor') {
        foreach ($all as $p) {
            foreach ($p->ms as $mm) {
                if ($mm->status === portal::SUBMITTED && \local_researchcentral\local\review::can_review($p, $mm) && !isset(\local_researchcentral\local\review::active((int)$mm->id)[(int)$USER->id])) {
                    $strip[] = [(int)$mm->timesubmitted, ui::user_line($p->student) . ': ' . format_string($mm->stagename), new moodle_url('/local/researchcentral/stage.php', ['id' => $mm->id])];
                }
            }
        }
        usort($strip, fn($a, $b) => $a[0] <=> $b[0]);
        if ($strip) {
            echo ui::hero(get_string('ux_next', 'local_researchcentral'), get_string('na_review', 'local_researchcentral', $strip[0][1]) .
                (count($strip) > 1 ? ' (+' . (count($strip) - 1) . ')' : ''), $strip[0][2], get_string('review_write', 'local_researchcentral'), 'high');
        } else {
            echo ui::hero(get_string('ux_next', 'local_researchcentral'), get_string('ux_all_clear', 'local_researchcentral'), null, '', 'ok');
        }
    } else {
        $ne = count(array_filter(\local_researchcentral\local\ethics::queue(), fn($e) => $e->status === 'submitted'));
        $hi = count(array_filter($riskmap, fn($r) => $r['level'] === 'high'));
        $np = count(portal::extensions_to_confirm());
        if ($np) {
            echo ui::hero(get_string('ux_next', 'local_researchcentral'), get_string('ext_awaiting_confirm', 'local_researchcentral', $np), null, '', 'high');
        } else if ($ne) {
            echo ui::hero(get_string('ux_next', 'local_researchcentral'), get_string('eth_title', 'local_researchcentral') . ': ' . $ne, new moodle_url('/local/researchcentral/ethics.php'), get_string('open', 'local_researchcentral'), 'med');
        } else if ($hi) {
            echo ui::hero(get_string('ux_next', 'local_researchcentral'), get_string('risk_high', 'local_researchcentral') . ': ' . $hi, new moodle_url('/local/researchcentral/portal.php', ['view' => 'coordinator', 'state' => 'overdue']), get_string('open', 'local_researchcentral'), 'med');
        } else {
            echo ui::hero(get_string('ux_next', 'local_researchcentral'), get_string('ux_all_clear', 'local_researchcentral'), null, '', 'ok');
        }
    }
    echo ui::legend();
    echo $stats($all, $scope === 'supervisor');

    // Filters.
    $states = ['' => get_string('all')];
    foreach (['review', 'revision', 'overdue', 'active', 'completed'] as $s) {
        $states[$s] = get_string('state_' . $s, 'local_researchcentral');
    }
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $base->out(false), 'class' => 'tt-filters']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'view', 'value' => $view]);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'q', 'value' => $filters['q'], 'class' => 'form-control',
        'placeholder' => get_string('search_students', 'local_researchcentral')]);
    echo html_writer::select($states, 'state', $filters['state'], false, ['class' => 'custom-select']);
    if ($scope === 'coordinator') {
        echo html_writer::select(portal::schools(true), 'schoolid', $filters['schoolid'], get_string('all_schools', 'local_researchcentral'), ['class' => 'custom-select']);
        echo html_writer::select(['' => get_string('level_all', 'local_researchcentral')] + \local_researchcentral\local\tracker::levels(),
            'level', $filters['level'], false, ['class' => 'custom-select']);
    }
    echo html_writer::tag('button', get_string('filter', 'local_researchcentral'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');

    $perpage = 40;
    $page = max(0, optional_param('page', 0, PARAM_INT));
    echo $projecttable(array_slice($projects, $page * $perpage, $perpage, true), $scope === 'coordinator');
    if (count($projects) > $perpage) {
        echo $OUTPUT->paging_bar(count($projects), $page, $perpage, new moodle_url($base, array_filter(['view' => $view] + $filters)));
    }
}
echo $OUTPUT->footer();
