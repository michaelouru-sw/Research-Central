<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

use local_researchcentral\local\dataset;
use local_researchcentral\local\portal;
use local_researchcentral\local\tracker;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
portal::require_coordinator();

$f = [
    'schoolid' => optional_param('schoolid', 0, PARAM_INT),
    'level' => optional_param('level', '', PARAM_ALPHA),
    'progid' => optional_param('progid', 0, PARAM_INT),
    'supervisorid' => optional_param('supervisorid', 0, PARAM_INT),
    'state' => optional_param('state', '', PARAM_ALPHA),
];
$kind = optional_param('kind', '', PARAM_ALPHA);
$anon = (bool)optional_param('anon', 0, PARAM_INT);

if ($kind) {
    require_sesskey();
    if ($kind === 'dictionary') {
        $w = new csv_export_writer();
        $w->set_filename('thesis_data_dictionary');
        $w->add_data(['file', 'column', 'description']);
        foreach (dataset::dictionary() as $row) {
            $w->add_data($row);
        }
        $w->download_file();
        exit;
    }
    if (!in_array($kind, dataset::KINDS, true)) {
        throw new moodle_exception('invalidparameter', 'debug');
    }
    $ds = new dataset($f, $anon);
    [$head, $rows] = $ds->build($kind);
    $w = new csv_export_writer();
    $w->set_filename('thesis_' . $kind . '_' . gmdate('Ymd_His') . ($anon ? '_anon' : ''));
    $w->add_data($head);
    foreach ($rows as $r) {
        $w->add_data($r);
    }
    portal::log(0, 'dataset', $kind . ' rows=' . count($rows) . ($anon ? ' anon' : ''));
    $w->download_file();
    exit;
}

$url = new moodle_url('/local/researchcentral/dataset.php', array_filter($f));
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('dataset', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

$all = portal::projects('coordinator');
$progopts = $supopts = [];
foreach ($all as $p) {
    $progopts[$p->progid] = $p->progname;
    $supopts[$p->supervisorid] = $p->supervisor ? fullname($p->supervisor) : '-';
}
asort($progopts);
asort($supopts);
$states = [];
foreach (['active', 'review', 'revision', 'overdue', 'completed'] as $sx) {
    $states[$sx] = get_string('state_' . $sx, 'local_researchcentral');
}
$n = (new dataset($f, false))->count();

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/panalytics.php', array_filter($f)), '← ' . get_string('analytics', 'local_researchcentral'), ['class' => 'tt-back']);
echo $OUTPUT->heading(get_string('dataset', 'local_researchcentral'), 3);
echo html_writer::div(get_string('dataset_intro', 'local_researchcentral'), 'tt-sub mb-3');

$sel = fn(string $nm, array $o, $v, string $none) => html_writer::select($o, $nm, $v, $none, ['class' => 'custom-select', 'onchange' => 'this.form.submit()']);
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out(false), 'class' => 'tt-filters']);
echo $sel('schoolid', portal::schools(true), $f['schoolid'], get_string('all_schools', 'local_researchcentral'));
echo $sel('level', tracker::levels(), $f['level'], get_string('level_all', 'local_researchcentral'));
echo $sel('progid', $progopts, $f['progid'], get_string('all_programmes', 'local_researchcentral'));
echo $sel('supervisorid', $supopts, $f['supervisorid'], get_string('all_supervisors', 'local_researchcentral'));
echo $sel('state', $states, $f['state'], get_string('all'));
echo html_writer::link(new moodle_url('/local/researchcentral/dataset.php'), get_string('reset_filters', 'local_researchcentral'), ['class' => 'btn btn-outline-secondary']);
echo html_writer::end_tag('form');
echo html_writer::div(get_string('dataset_scope', 'local_researchcentral', $n), 'tt-banner');

$cards = '';
foreach (dataset::KINDS as $k) {
    $dl = new moodle_url('/local/researchcentral/dataset.php', array_filter($f) + ['kind' => $k, 'sesskey' => sesskey()]);
    $dla = new moodle_url($dl, ['anon' => 1]);
    $cards .= html_writer::div(
        html_writer::div(get_string('ds_' . $k, 'local_researchcentral'), 'tt-strong') .
        html_writer::div(get_string('ds_' . $k . '_d', 'local_researchcentral'), 'tt-sub mb-2') .
        html_writer::link($dl, get_string('ds_download', 'local_researchcentral'), ['class' => 'btn btn-sm btn-primary mr-2']) .
        html_writer::link($dla, get_string('ds_download_anon', 'local_researchcentral'), ['class' => 'btn btn-sm btn-outline-secondary']),
        'tt-card');
}
echo html_writer::div($cards, 'tt-chartgrid');
echo html_writer::div(html_writer::link(new moodle_url('/local/researchcentral/dataset.php', ['kind' => 'dictionary', 'sesskey' => sesskey()]),
    get_string('ds_dictionary', 'local_researchcentral'), ['class' => 'btn btn-outline-secondary']), 'mt-3');
echo html_writer::div(get_string('ds_privacy', 'local_researchcentral'), 'tt-sub mt-3');
echo $OUTPUT->footer();
