<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

use local_researchcentral\local\portal;
use local_researchcentral\output\ui;
use local_researchcentral\form\import_form;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
portal::require_coordinator();
$type = optional_param('type', '', PARAM_ALPHA);
$op = optional_param('op', '', PARAM_ALPHA);
$iid = optional_param('iid', 0, PARAM_INT);
$apply = optional_param('apply', 0, PARAM_INT);

$url = new moodle_url('/local/researchcentral/bulk.php', ['type' => $type]);
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('bulk', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

$cfg = [
    'schools' => ['admin' => true, 'cols' => ['name'], 'req' => ['name']],
    'coordinators' => ['admin' => true, 'cols' => ['school', 'user'], 'req' => ['school', 'user']],
    'programmes' => ['admin' => false, 'cols' => ['name', 'school', 'level', 'standardstages'], 'req' => ['name', 'school']],
    'stages' => ['admin' => false, 'cols' => ['programme', 'stage', 'days', 'upload', 'maxmark', 'weight'], 'req' => ['programme', 'stage']],
];
$lc = fn($v) => \core_text::strtolower(trim((string)$v));

/** Lookups. */
$schoolsbyname = function () use ($DB, $lc) {
    $o = [];
    foreach ($DB->get_records('local_researchcentral_school') as $r) {
        $o[$lc($r->name)] = $r;
    }
    return $o;
};
$finduser = function (string $v) use ($DB, $lc): ?stdClass {
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    $u = $DB->get_records('user', ['email' => $v, 'deleted' => 0, 'suspended' => 0], '', 'id, firstname, lastname, email');
    if (count($u) === 1) {
        return reset($u);
    }
    if ($u) {
        return null;
    }
    return $DB->get_record('user', ['username' => $lc($v), 'deleted' => 0, 'suspended' => 0], 'id, firstname, lastname, email') ?: null;
};
$levelof = function (string $v): ?string {
    $v = \core_text::strtolower(trim($v));
    if ($v === '') {
        return 'masters';
    }
    $map = ['diploma' => 'diploma', 'certificate' => 'diploma', 'higher diploma' => 'diploma', 'ug' => 'ug', 'undergraduate' => 'ug', 'bachelor' => 'ug', 'bachelors' => 'ug', 'degree' => 'ug',
        'masters' => 'masters', 'master' => 'masters', 'msc' => 'masters', 'med' => 'masters', 'mba' => 'masters',
        'phd' => 'phd', 'doctorate' => 'phd', 'doctoral' => 'phd'];
    return $map[$v] ?? null;
};
$yes = fn($v) => in_array(\core_text::strtolower(trim((string)$v)), ['', '1', 'yes', 'y', 'true'], true);

/** Row validators: return [plan, message, data]. plan = create | update | same | error. */
$check = [];
$check['schools'] = function (array $r, array &$seen) use ($schoolsbyname, $lc): array {
    $n = trim($r['name'] ?? '');
    if ($n === '') {
        return ['error', get_string('bulk_err_empty', 'local_researchcentral'), null];
    }
    $k = $lc($n);
    if (isset($schoolsbyname()[$k]) || isset($seen[$k])) {
        return ['same', get_string('imp_same', 'local_researchcentral'), null];
    }
    $seen[$k] = true;
    return ['create', get_string('bulk_create_school', 'local_researchcentral'), ['name' => $n]];
};
$check['coordinators'] = function (array $r, array &$seen) use ($schoolsbyname, $finduser, $lc, $DB): array {
    $sc = $schoolsbyname()[$lc($r['school'] ?? '')] ?? null;
    if (!$sc) {
        return ['error', get_string('bulk_err_school', 'local_researchcentral'), null];
    }
    $u = $finduser($r['user'] ?? '');
    if (!$u) {
        return ['error', get_string('imp_err_student', 'local_researchcentral'), null];
    }
    $k = $sc->id . '|' . $u->id;
    if (isset($seen[$k]) || $DB->record_exists('local_researchcentral_scoord', ['schoolid' => $sc->id, 'userid' => $u->id])) {
        return ['same', get_string('imp_same', 'local_researchcentral'), null];
    }
    $seen[$k] = true;
    return ['create', get_string('bulk_create_coord', 'local_researchcentral'), ['school' => $sc, 'user' => $u]];
};
$check['programmes'] = function (array $r, array &$seen) use ($schoolsbyname, $levelof, $yes, $lc, $DB): array {
    $name = trim($r['name'] ?? '');
    $sc = $schoolsbyname()[$lc($r['school'] ?? '')] ?? null;
    if ($name === '') {
        return ['error', get_string('bulk_err_empty', 'local_researchcentral'), null];
    }
    if (!$sc) {
        return ['error', get_string('bulk_err_school', 'local_researchcentral'), null];
    }
    if (!portal::can_manage_school((int)$sc->id)) {
        return ['error', get_string('imp_err_scope', 'local_researchcentral'), null];
    }
    $level = $levelof($r['level'] ?? '');
    if (!$level) {
        return ['error', get_string('bulk_err_level', 'local_researchcentral'), null];
    }
    foreach ($DB->get_records('local_researchcentral_prog') as $g) {
        if ($lc($g->name) === $lc($name)) {
            return $g->schoolid == $sc->id ? ['same', get_string('imp_same', 'local_researchcentral'), null]
                : ['error', get_string('bulk_err_progname', 'local_researchcentral'), null];
        }
    }
    if (isset($seen[$lc($name)])) {
        return ['same', get_string('imp_same', 'local_researchcentral'), null];
    }
    $seen[$lc($name)] = true;
    return ['create', get_string('bulk_create_prog', 'local_researchcentral'),
        ['name' => $name, 'school' => $sc, 'level' => $level, 'template' => $yes($r['standardstages'] ?? '')]];
};
$check['stages'] = function (array $r, array &$seen) use ($lc, $DB): array {
    $pname = $lc($r['programme'] ?? '');
    $stage = trim($r['stage'] ?? '');
    $prog = null;
    foreach ($DB->get_records('local_researchcentral_prog') as $g) {
        if ($lc($g->name) === $pname) {
            $prog = $g;
        }
    }
    if (!$prog) {
        return ['error', get_string('imp_err_prog', 'local_researchcentral'), null];
    }
    if (!portal::can_manage_prog($prog)) {
        return ['error', get_string('imp_err_scope', 'local_researchcentral'), null];
    }
    if ($stage === '') {
        return ['error', get_string('bulk_err_empty', 'local_researchcentral'), null];
    }
    foreach (['days', 'maxmark', 'weight'] as $f) {
        $v = trim($r[$f] ?? '');
        if ($v !== '' && (!ctype_digit($v) || (int)$v > 5000)) {
            return ['error', get_string('bulk_err_number', 'local_researchcentral', $f), null];
        }
    }
    $upload = trim($r['upload'] ?? '');
    $d = ['prog' => $prog, 'stage' => $stage, 'days' => trim($r['days'] ?? '') === '' ? 30 : (int)$r['days'],
        'upload' => in_array($lc($upload), ['', '1', 'yes', 'y', 'true'], true) ? 1 : 0,
        'maxmark' => trim($r['maxmark'] ?? '') === '' ? null : (int)$r['maxmark'], 'weight' => (int)($r['weight'] ?? 0)];
    $k = $prog->id . '|' . $lc($stage);
    if (isset($seen[$k])) {
        return ['error', get_string('bulk_err_dupe', 'local_researchcentral'), null];
    }
    $seen[$k] = true;
    $ex = null;
    foreach ($DB->get_records('local_researchcentral_stage', ['progid' => $prog->id]) as $s) {
        if ($lc($s->name) === $lc($stage)) {
            $ex = $s;
        }
    }
    if ($ex) {
        $d['existing'] = $ex;
        return ['update', get_string('bulk_update_stage', 'local_researchcentral'), $d];
    }
    return ['create', get_string('bulk_create_stage', 'local_researchcentral'), $d];
};

/** Appliers. */
$applier = [];
$applier['schools'] = function (array $d) use ($DB) {
    $DB->insert_record('local_researchcentral_school', (object)['name' => $d['name'], 'timecreated' => time()]);
};
$applier['coordinators'] = function (array $d) use ($DB) {
    $DB->insert_record('local_researchcentral_scoord', (object)['schoolid' => $d['school']->id, 'userid' => $d['user']->id, 'timecreated' => time()]);
    portal::forget_roles();
    $a = (object)['school' => s($d['school']->name)];
    portal::notify((int)$d['user']->id, get_string('msg_coord_subject', 'local_researchcentral', $a),
        get_string('msg_coord_body', 'local_researchcentral', $a), new moodle_url('/local/researchcentral/portal.php', ['view' => 'coordinator']), 'workflow');
};
$applier['programmes'] = function (array $d) use ($DB) {
    $id = $DB->insert_record('local_researchcentral_prog', (object)['name' => $d['name'], 'schoolid' => $d['school']->id,
        'level' => $d['level'], 'timecreated' => time()]);
    if ($d['template']) {
        portal::seed_stages($id, $d['level']);
    }
};
$applier['stages'] = function (array $d) use ($DB) {
    $max = $d['maxmark'] ?? ($d['upload'] ? 100 : 0);
    if (!empty($d['existing'])) {
        $DB->update_record('local_researchcentral_stage', (object)['id' => $d['existing']->id, 'defaultdays' => max(1, $d['days']),
            'needsupload' => $d['upload'], 'maxgrade' => $max, 'weight' => min(100, $d['weight'])]);
        return;
    }
    $order = 1 + (int)$DB->get_field_sql('SELECT MAX(sortorder) FROM {local_researchcentral_stage} WHERE progid = ?', [$d['prog']->id]);
    $DB->insert_record('local_researchcentral_stage', (object)['courseid' => 0, 'progid' => $d['prog']->id, 'name' => $d['stage'],
        'cmid' => 0, 'level' => 'all', 'sortorder' => $order, 'defaultdays' => max(1, $d['days']), 'needsupload' => $d['upload'],
        'maxgrade' => $max, 'weight' => min(100, $d['weight']), 'timecreated' => time()]);
};

/** Export rows and template examples. */
$export = [];
$export['schools'] = fn() => array_map(fn($n) => [$n], array_values(portal::schools()));
$export['coordinators'] = function () {
    $rows = [];
    $names = portal::schools();
    foreach (portal::school_coordinators() as $sid => $list) {
        foreach ($list as $u) {
            $rows[] = [$names[$sid] ?? '', $u->email];
        }
    }
    return $rows;
};
$export['programmes'] = function () use ($DB) {
    $rows = [];
    $names = portal::schools();
    foreach ($DB->get_records('local_researchcentral_prog', null, 'name') as $g) {
        if (portal::can_manage_school((int)$g->schoolid)) {
            $rows[] = [$g->name, $names[$g->schoolid] ?? '', $g->level, 'no'];
        }
    }
    return $rows;
};
$export['stages'] = function () use ($DB) {
    $rows = [];
    foreach ($DB->get_records('local_researchcentral_prog', null, 'name') as $g) {
        if (!portal::can_manage_school((int)$g->schoolid)) {
            continue;
        }
        foreach ($DB->get_records('local_researchcentral_stage', ['progid' => $g->id], 'sortorder, id') as $s) {
            $rows[] = [$g->name, $s->name, $s->defaultdays, $s->needsupload ? 'yes' : 'no', $s->maxgrade, $s->weight];
        }
    }
    return $rows;
};
$example = [
    'schools' => [['School of Education']],
    'coordinators' => [['School of Education', 'coordinator@example.com']],
    'programmes' => [['Master of Education (Research)', 'School of Education', 'masters', 'yes']],
    'stages' => [['Master of Education (Research)', 'Literature review', '120', 'yes', '100', '20']],
];

$read = function (csv_import_reader $cir): array {
    $header = array_map(fn($h) => \core_text::strtolower(trim($h)), $cir->get_columns() ?: []);
    $rows = [];
    $cir->init();
    while ($line = $cir->next()) {
        $row = [];
        foreach ($header as $i => $h) {
            $row[$h] = $line[$i] ?? '';
        }
        $rows[] = $row;
    }
    $cir->close();
    return [$header, $rows];
};

// ----- Hub.
if (!$type || !isset($cfg[$type])) {
    echo $OUTPUT->header();
    echo html_writer::link(new moodle_url('/local/researchcentral/portal.php', ['view' => 'coordinator']), '← ' . get_string('back', 'local_researchcentral'), ['class' => 'tt-back']);
    echo $OUTPUT->heading(get_string('bulk', 'local_researchcentral'), 3);
    echo html_writer::div(get_string('bulk_intro', 'local_researchcentral'), 'tt-sub mb-3');
    $cards = '';
    $items = ['schools' => 'bulk_schools', 'coordinators' => 'bulk_coordinators', 'programmes' => 'bulk_programmes', 'stages' => 'bulk_stages'];
    foreach ($items as $k => $str) {
        if ($cfg[$k]['admin'] && !is_siteadmin()) {
            continue;
        }
        $cards .= html_writer::link(new moodle_url('/local/researchcentral/bulk.php', ['type' => $k]),
            html_writer::div(get_string($str, 'local_researchcentral'), 'tt-strong') .
            html_writer::div(get_string($str . '_d', 'local_researchcentral'), 'tt-sub'), ['class' => 'tt-card tt-hubcard']);
    }
    $cards .= html_writer::link(new moodle_url('/local/researchcentral/allocations.php'),
        html_writer::div(get_string('allocations', 'local_researchcentral'), 'tt-strong') .
        html_writer::div(get_string('bulk_alloc_d', 'local_researchcentral'), 'tt-sub'), ['class' => 'tt-card tt-hubcard']);
    echo html_writer::div($cards, 'tt-chartgrid');
    echo html_writer::div(get_string('bulk_users_note', 'local_researchcentral'), 'tt-sub mt-3');
    echo $OUTPUT->footer();
    exit;
}
if ($cfg[$type]['admin']) {
    portal::require_admin();
}
$cols = $cfg[$type]['cols'];

if ($op === 'export' || $op === 'template') {
    $w = new csv_export_writer();
    $w->set_filename('research_' . $type . '_' . userdate(time(), '%Y%m%d'));
    $w->add_data($cols);
    foreach ($op === 'export' ? $export[$type]() : $example[$type] as $row) {
        $w->add_data($row);
    }
    $w->download_file();
    exit;
}

$ns = 'researchcentral_bulk_' . $type;
if ($iid && $apply) {
    require_sesskey();
    $cir = new csv_import_reader($iid, $ns);
    [$header, $rows] = $read($cir);
    $seen = [];
    $n = ['create' => 0, 'update' => 0, 'same' => 0, 'error' => 0];
    foreach ($rows as $r) {
        [$plan, , $d] = $check[$type]($r, $seen);
        if ($plan === 'create' || $plan === 'update') {
            $applier[$type]($d);
        }
        $n[$plan]++;
    }
    $cir->cleanup(true);
    redirect($url, get_string('bulk_done', 'local_researchcentral', (object)['c' => $n['create'], 'u' => $n['update'], 's' => $n['same'], 'e' => $n['error']]),
        null, \core\output\notification::NOTIFY_SUCCESS);
}

$form = new import_form($url, ['colsdesc' => implode(', ', $cols) . '. ' . get_string('bulk_cols_' . $type, 'local_researchcentral')]);
$preview = null;
if ($data = $form->get_data()) {
    $iid = csv_import_reader::get_new_iid($ns);
    $cir = new csv_import_reader($iid, $ns);
    if (!$cir->load_csv_content($form->get_file_content('csvfile'), 'utf-8', $data->delimiter)) {
        throw new moodle_exception('csvloaderror', 'error', $url, $cir->get_error());
    }
    [$header, $rows] = $read($cir);
    $preview = ['header' => $header, 'rows' => $rows, 'missing' => array_diff($cfg[$type]['req'], $header), 'iid' => $iid];
}

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/bulk.php'), '← ' . get_string('bulk', 'local_researchcentral'), ['class' => 'tt-back']);
echo $OUTPUT->heading(get_string('bulk_' . $type, 'local_researchcentral'), 3);

if ($preview) {
    if ($preview['missing']) {
        echo $OUTPUT->notification(get_string('imp_missingcols', 'local_researchcentral', implode(', ', $preview['missing'])), 'error');
        echo html_writer::link($url, get_string('back', 'local_researchcentral'));
    } else {
        $t = new html_table();
        $t->attributes['class'] = 'tt-table';
        $show = array_slice($cols, 0, 4);
        $t->head = array_merge(['#'], $show, [get_string('imp_action', 'local_researchcentral')]);
        $n = ['create' => 0, 'update' => 0, 'same' => 0, 'error' => 0];
        $seen = [];
        foreach ($preview['rows'] as $i => $r) {
            [$plan, $msg] = $check[$type]($r, $seen);
            $n[$plan]++;
            $pill = $plan === 'error' ? 'overdue' : ($plan === 'same' ? 'pending' : ($plan === 'create' ? 'approved' : 'submitted'));
            $t->data[] = array_merge([$i + 1], array_map(fn($c) => s($r[$c] ?? ''), $show), [ui::pill($pill, $msg)]);
        }
        echo ui::stats([
            ui::stat((string)$n['create'], get_string('bulk_new', 'local_researchcentral')),
            ui::stat((string)$n['update'], get_string('bulk_updated', 'local_researchcentral')),
            ui::stat((string)$n['same'], get_string('imp_same', 'local_researchcentral')),
            ui::stat((string)$n['error'], get_string('imp_errors', 'local_researchcentral'), $n['error'] ? 'tt-bad' : ''),
        ]);
        echo html_writer::table($t);
        if ($n['create'] + $n['update'] > 0) {
            echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'iid', 'value' => $preview['iid']]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'apply', 'value' => 1]) .
                html_writer::tag('button', get_string('imp_apply', 'local_researchcentral', $n['create'] + $n['update']), ['class' => 'btn btn-primary mr-2']) .
                html_writer::link($url, get_string('cancel'), ['class' => 'btn btn-outline-secondary']) .
                html_writer::end_tag('form');
            echo html_writer::div(get_string('bulk_note', 'local_researchcentral'), 'tt-sub mt-2');
        } else {
            echo html_writer::link($url, get_string('back', 'local_researchcentral'));
        }
    }
} else {
    echo html_writer::div(
        html_writer::link(new moodle_url($url, ['op' => 'export']), get_string('imp_export', 'local_researchcentral'), ['class' => 'btn btn-outline-primary mr-2']) .
        html_writer::link(new moodle_url($url, ['op' => 'template']), get_string('imp_template', 'local_researchcentral'), ['class' => 'btn btn-outline-secondary']),
        'mb-4');
    echo html_writer::tag('h5', get_string('imp_import', 'local_researchcentral'));
    $form->display();
}
echo $OUTPUT->footer();
