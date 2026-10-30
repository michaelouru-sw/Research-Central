<?php
// Repository handoff: completed theses, with licence and embargo, exported as a DSpace Simple Archive package.
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_researchcentral\local\portal;
use local_researchcentral\local\repo;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
portal::require_coordinator();
$L = fn(string $k, $a = null) => get_string($k, 'local_researchcentral', $a);
$url = new moodle_url('/local/researchcentral/repository.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
raise_memory_limit(MEMORY_EXTRA);
core_php_time_limit::raise(300);

if (data_submitted() && optional_param('do', '', PARAM_ALPHA) !== '') {
    require_sesskey();
    $do = optional_param('do', '', PARAM_ALPHA);
    $lic = optional_param_array('lic', [], PARAM_TEXT);
    $emb = optional_param_array('emb', [], PARAM_RAW_TRIMMED);
    $han = optional_param_array('handle', [], PARAM_TEXT);
    $ids = array_map('intval', optional_param_array('sel', [], PARAM_INT));
    $cands = repo::candidates();
    foreach ($cands as $pid => $c) {
        if (isset($lic[$pid])) {
            repo::save_meta($DB->get_record('local_researchcentral_proj', ['id' => $pid], '*', MUST_EXIST), $lic[$pid], !empty($emb[$pid]) ? (strtotime($emb[$pid]) ?: 0) : 0, $han[$pid] ?? '');
        }
    }
    if ($do === 'export' && $ids) {
        [$zipfile, $ok, $skipped] = repo::build($ids);
        if ($ok) {
            send_temp_file($zipfile, basename($zipfile));
        }
        redirect($url, $L('repo_nothing_exported'), null, \core\output\notification::NOTIFY_WARNING);
    }
    redirect($url, $L('saved'));
}

$PAGE->set_pagelayout('report');
$PAGE->set_title($L('repo_title'));
$PAGE->set_heading($L('pluginname'));
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/portal.php', ['view' => 'coordinator']), '← ' . $L('back'), ['class' => 'tt-back']);
echo $OUTPUT->heading($L('repo_title'), 3);
echo html_writer::div($L('repo_help', repo::MAX_BATCH), 'tt-sub mb-3');
$rows = repo::candidates();
if (!$rows) {
    echo html_writer::div($L('repo_none'), 'tt-empty');
} else {
    $t = new html_table();
    $t->attributes['class'] = 'tt-table';
    $t->head = ['', $L('student'), $L('repo_thesis'), $L('repo_completed'), $L('repo_license'), $L('repo_embargo'), $L('repo_handle'), $L('status')];
    foreach ($rows as $r) {
        $u = core_user::get_user($r->userid);
        $has = repo::final_file((int)$r->id) !== null;
        $t->data[] = [
            $has ? html_writer::checkbox('sel[]', $r->id, $r->rstatus !== 'exported', '', ['aria-label' => $L('repo_select')]) : '',
            $u ? s(fullname($u)) : '-',
            s(format_string($r->title)) . html_writer::div(format_string($r->progname), 'tt-sub'),
            $r->completed ? userdate($r->completed, get_string('strftimedatefullshort', 'langconfig')) : '-',
            html_writer::select(repo::licenses(), "lic[{$r->id}]", $r->license ?: (string)portal::cfg('repo_license', 'CC BY-NC-ND 4.0'), false, ['class' => 'custom-select custom-select-sm']),
            html_writer::empty_tag('input', ['type' => 'date', 'name' => "emb[{$r->id}]", 'value' => $r->embargo ? date('Y-m-d', $r->embargo) : '', 'class' => 'form-control form-control-sm']),
            html_writer::empty_tag('input', ['type' => 'text', 'name' => "handle[{$r->id}]", 'value' => $r->handle, 'class' => 'form-control form-control-sm', 'maxlength' => 250, 'placeholder' => 'https://…']),
            $has ? ($r->rstatus === 'exported' ? html_writer::span($L('repo_exported_on', userdate($r->timeexported, get_string('strftimedatefullshort', 'langconfig'))), 'tt-chk tt-chk-pass') : html_writer::span($L('repo_ready'), 'tt-chk tt-chk-warn'))
                : html_writer::span($L('repo_nofile'), 'tt-chk tt-chk-no'),
        ];
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
        html_writer::div(html_writer::table($t), 'tt-tablewrap') .
        html_writer::tag('button', $L('repo_export'), ['type' => 'submit', 'name' => 'do', 'value' => 'export', 'class' => 'btn btn-primary mr-2']) .
        html_writer::tag('button', $L('save'), ['type' => 'submit', 'name' => 'do', 'value' => 'save', 'class' => 'btn btn-outline-secondary']) .
        html_writer::end_tag('form');
}
echo $OUTPUT->footer();
