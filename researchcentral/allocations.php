<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

use local_researchcentral\local\portal;
use local_researchcentral\form\import_form;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
portal::require_coordinator();
$url = new moodle_url('/local/researchcentral/allocations.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('allocations', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

$cols = ['student', 'supervisor', 'programme', 'title', 'startdate'];
$op = optional_param('op', '', PARAM_ALPHA);

// Export current allocations; or an empty template.
if ($op === 'export' || $op === 'template') {
    $w = new csv_export_writer();
    $w->set_filename('thesis_allocations_' . userdate(time(), '%Y%m%d'));
    $w->add_data($cols);
    if ($op === 'export') {
        foreach (portal::projects('coordinator') as $p) {
            $w->add_data([$p->student->email ?? '', $p->supervisor->email ?? '', $p->progname, $p->title,
                userdate($p->startdate, '%Y-%m-%d')]);
        }
    } else {
        $w->add_data(['student@example.com', 'lecturer@example.com', 'Masters in Education', 'Research title', userdate(time(), '%Y-%m-%d')]);
    }
    $w->download_file();
    exit;
}

/** Find an active user by email, falling back to username. */
$finduser = function (string $v) use ($DB): ?stdClass {
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    $users = $DB->get_records('user', ['email' => $v, 'deleted' => 0, 'suspended' => 0], '', 'id, firstname, lastname, email');
    if (count($users) === 1) {
        return reset($users);
    }
    if (!$users) {
        $u = $DB->get_record('user', ['username' => \core_text::strtolower($v), 'deleted' => 0, 'suspended' => 0],
            'id, firstname, lastname, email');
        return $u ?: null;
    }
    return null; // Ambiguous email.
};
$progs = [];
foreach ($DB->get_records('local_researchcentral_prog') as $g) {
    $progs[\core_text::strtolower(trim($g->name))][] = $g;
}

/** Validate one row; returns [plan, message]. plan: create | reassign | same | error. */
$check = function (array $r) use ($finduser, $progs, $DB): array {
    $stu = $finduser($r['student'] ?? '');
    $sup = $finduser($r['supervisor'] ?? '');
    if (!$stu) {
        return ['error', get_string('imp_err_student', 'local_researchcentral'), null];
    }
    if (!$sup) {
        return ['error', get_string('imp_err_supervisor', 'local_researchcentral'), null];
    }
    if ($stu->id == $sup->id) {
        return ['error', get_string('err_samesupervisor', 'local_researchcentral'), null];
    }
    $cands = $progs[\core_text::strtolower(trim($r['programme'] ?? ''))] ?? [];
    if (count($cands) !== 1) {
        return ['error', get_string($cands ? 'imp_err_progambig' : 'imp_err_prog', 'local_researchcentral'), null];
    }
    $prog = $cands[0];
    if (!portal::can_manage_prog($prog)) {
        return ['error', get_string('imp_err_scope', 'local_researchcentral'), null];
    }
    if (!$DB->record_exists('local_researchcentral_stage', ['progid' => $prog->id])) {
        return ['error', get_string('err_nostages', 'local_researchcentral'), null];
    }
    $start = trim($r['startdate'] ?? '') === '' ? time() : strtotime($r['startdate']);
    if (!$start) {
        return ['error', get_string('imp_err_date', 'local_researchcentral'), null];
    }
    $data = ['stu' => $stu, 'sup' => $sup, 'prog' => $prog, 'title' => trim($r['title'] ?? ''), 'start' => $start];
    $proj = $DB->get_record('local_researchcentral_proj', ['userid' => $stu->id, 'progid' => $prog->id]);
    if ($proj) {
        $data['proj'] = $proj;
        return $proj->supervisorid == $sup->id ? ['same', get_string('imp_same', 'local_researchcentral'), $data]
            : ['reassign', get_string('imp_reassign', 'local_researchcentral'), $data];
    }
    return ['create', get_string('imp_create', 'local_researchcentral'), $data];
};

$read = function (csv_import_reader $cir) use ($cols): array {
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

$iid = optional_param('iid', 0, PARAM_INT);
$apply = optional_param('apply', 0, PARAM_INT);

if ($iid && $apply) {
    require_sesskey();
    $cir = new csv_import_reader($iid, 'researchcentral_alloc');
    [$header, $rows] = $read($cir);
    $created = $reassigned = $skipped = 0;
    foreach ($rows as $r) {
        [$plan, , $d] = $check($r);
        if ($plan === 'create') {
            portal::create_project((int)$d['stu']->id, (int)$d['sup']->id, (int)$d['prog']->id, $d['title'], (int)$d['start']);
            $created++;
        } else if ($plan === 'reassign') {
            portal::reassign($d['proj'], (int)$d['sup']->id);
            $reassigned++;
        } else {
            $skipped++;
        }
    }
    $cir->cleanup(true);
    redirect($url, get_string('imp_done', 'local_researchcentral', (object)['c' => $created, 'r' => $reassigned, 's' => $skipped]),
        null, \core\output\notification::NOTIFY_SUCCESS);
}

$form = new import_form($url);
$preview = null;
if ($data = $form->get_data()) {
    $content = $form->get_file_content('csvfile');
    $iid = csv_import_reader::get_new_iid('researchcentral_alloc');
    $cir = new csv_import_reader($iid, 'researchcentral_alloc');
    $count = $cir->load_csv_content($content, 'utf-8', $data->delimiter);
    if (!$count) {
        throw new moodle_exception('csvloaderror', 'error', $url, $cir->get_error());
    }
    [$header, $rows] = $read($cir);
    $missing = array_diff(['student', 'supervisor', 'programme'], $header);
    $preview = ['rows' => $rows, 'missing' => $missing, 'iid' => $iid];
}

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/portal.php', ['view' => 'coordinator']), '← ' . get_string('back', 'local_researchcentral'), ['class' => 'tt-back']);
echo $OUTPUT->heading(get_string('allocations', 'local_researchcentral'), 3);

if ($preview) {
    if ($preview['missing']) {
        echo $OUTPUT->notification(get_string('imp_missingcols', 'local_researchcentral', implode(', ', $preview['missing'])), 'error');
        echo html_writer::link($url, get_string('back', 'local_researchcentral'));
    } else {
        $t = new html_table();
        $t->attributes['class'] = 'tt-table';
        $t->head = ['#', get_string('student', 'local_researchcentral'), get_string('supervisor', 'local_researchcentral'),
            get_string('programme', 'local_researchcentral'), get_string('imp_action', 'local_researchcentral')];
        $n = ['create' => 0, 'reassign' => 0, 'same' => 0, 'error' => 0];
        foreach ($preview['rows'] as $i => $r) {
            [$plan, $msg] = $check($r);
            $n[$plan]++;
            $pill = $plan === 'error' ? 'overdue' : ($plan === 'same' ? 'pending' : ($plan === 'create' ? 'approved' : 'submitted'));
            $t->data[] = [$i + 1, s($r['student'] ?? ''), s($r['supervisor'] ?? ''), s($r['programme'] ?? ''),
                local_researchcentral\output\ui::pill($pill, $msg)];
        }
        echo local_researchcentral\output\ui::stats([
            local_researchcentral\output\ui::stat((string)$n['create'], get_string('imp_create', 'local_researchcentral')),
            local_researchcentral\output\ui::stat((string)$n['reassign'], get_string('imp_reassign', 'local_researchcentral')),
            local_researchcentral\output\ui::stat((string)$n['same'], get_string('imp_same', 'local_researchcentral')),
            local_researchcentral\output\ui::stat((string)$n['error'], get_string('imp_errors', 'local_researchcentral'), $n['error'] ? 'tt-bad' : ''),
        ]);
        echo html_writer::table($t);
        if ($n['create'] + $n['reassign'] > 0) {
            echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'iid', 'value' => $preview['iid']]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'apply', 'value' => 1]) .
                html_writer::tag('button', get_string('imp_apply', 'local_researchcentral', $n['create'] + $n['reassign']), ['class' => 'btn btn-primary mr-2']) .
                html_writer::link($url, get_string('cancel'), ['class' => 'btn btn-outline-secondary']) .
                html_writer::end_tag('form');
            echo html_writer::div(get_string('imp_note', 'local_researchcentral'), 'tt-sub mt-2');
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
