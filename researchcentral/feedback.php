<?php
// Feedback archive for quality assurance: every supervisor review and decision, searchable and exportable.
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\portal;
use local_researchcentral\local\tracker;
use local_researchcentral\output\ui;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
portal::require_coordinator();
$f = [
    'schoolid' => optional_param('schoolid', 0, PARAM_INT),
    'level' => optional_param('level', '', PARAM_ALPHA),
    'progid' => optional_param('progid', 0, PARAM_INT),
    'supervisorid' => optional_param('supervisorid', 0, PARAM_INT),
];
$rec = optional_param('rec', '', PARAM_ALPHA);
$q = optional_param('q', '', PARAM_TEXT);
$url = new moodle_url('/local/researchcentral/feedback.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('feedback_archive', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

$all = portal::projects('coordinator');
$projects = portal::projects('coordinator', $f);
$progopts = $supopts = [];
foreach ($all as $p) {
    $progopts[$p->progid] = $p->progname;
    $supopts[$p->supervisorid] = $p->supervisor ? fullname($p->supervisor) : '-';
}
asort($progopts);
asort($supopts);

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/portal.php', ['view' => 'coordinator']), '← ' . get_string('back', 'local_researchcentral'), ['class' => 'tt-back']);
echo $OUTPUT->heading(get_string('feedback_archive', 'local_researchcentral'), 3);
echo html_writer::div(get_string('feedback_archive_d', 'local_researchcentral'), 'tt-sub mb-3');
$sel = fn(string $n, array $o, $v, string $none) => html_writer::select($o, $n, $v, $none, ['class' => 'custom-select', 'onchange' => 'this.form.submit()']);
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out(false), 'class' => 'tt-filters']);
echo $sel('schoolid', portal::schools(true), $f['schoolid'], get_string('all_schools', 'local_researchcentral'));
echo $sel('level', tracker::levels(), $f['level'], get_string('level_all', 'local_researchcentral'));
echo $sel('progid', $progopts, $f['progid'], get_string('all_programmes', 'local_researchcentral'));
echo $sel('supervisorid', $supopts, $f['supervisorid'], get_string('all_supervisors', 'local_researchcentral'));
echo $sel('rec', \local_researchcentral\local\review::recommendations(), $rec, get_string('all'));
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'q', 'value' => $q, 'class' => 'form-control', 'placeholder' => get_string('forum_search', 'local_researchcentral')]);
echo html_writer::tag('button', get_string('filter', 'local_researchcentral'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::link(new moodle_url('/local/researchcentral/dataset.php', array_filter($f) + ['kind' => 'reviews', 'sesskey' => sesskey()]),
    get_string('feedback_export', 'local_researchcentral'), ['class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

if (!$projects) {
    echo html_writer::div(get_string('no_projects', 'local_researchcentral'), 'tt-empty');
    echo $OUTPUT->footer();
    exit;
}
[$in, $params] = $DB->get_in_or_equal(array_keys($projects), SQL_PARAMS_NAMED);
$where = "r.projid $in";
if ($rec !== '') {
    $where .= ' AND r.recommendation = :rec';
    $params['rec'] = $rec;
}
if ($q !== '') {
    $where .= ' AND (' . $DB->sql_like('r.comment', ':q1', false) . ' OR ' . $DB->sql_like('r.confidential', ':q2', false) . ')';
    $params['q1'] = $params['q2'] = '%' . $DB->sql_like_escape($q) . '%';
}
$total = $DB->count_records_sql("SELECT COUNT(1) FROM {local_researchcentral_review} r WHERE $where", $params);
$page = optional_param('page', 0, PARAM_INT);
$per = 30;
$rows = $DB->get_records_sql("SELECT r.*, s.name AS stagename FROM {local_researchcentral_review} r
                                JOIN {local_researchcentral_pms} m ON m.id = r.pmsid JOIN {local_researchcentral_stage} s ON s.id = m.stageid
                               WHERE $where ORDER BY r.timecreated DESC, r.id DESC", $params, $page * $per, $per);
echo html_writer::div(get_string('feedback_count', 'local_researchcentral', $total), 'tt-banner');
if (!$rows) {
    echo html_writer::div(get_string('no_projects', 'local_researchcentral'), 'tt-empty');
} else {
    $users = $DB->get_records_list('user', 'id', array_unique(array_column($rows, 'userid')));
    $t = new html_table();
    $t->attributes['class'] = 'tt-table';
    $t->head = [get_string('when', 'local_researchcentral'), get_string('student', 'local_researchcentral'), get_string('stage', 'local_researchcentral'),
        get_string('reviewer', 'local_researchcentral'), get_string('rec_label', 'local_researchcentral'), get_string('feedback', 'local_researchcentral'), ''];
    $fs = get_file_storage();
    foreach ($rows as $r) {
        $pr = $projects[$r->projid];
        $files = count($fs->get_area_files(context_system::instance()->id, 'local_researchcentral', 'feedback', $r->id, 'id', false));
        $t->data[] = [userdate($r->timecreated, get_string('strftimedatetimeshort', 'langconfig')), ui::user_line($pr->student) . html_writer::div(s($pr->progname), 'tt-sub'),
            format_string($r->stagename) . html_writer::div(get_string('round_n', 'local_researchcentral', $r->round), 'tt-sub'),
            s(isset($users[$r->userid]) ? fullname($users[$r->userid]) : '-') . ($r->status === 'superseded' ? html_writer::div(get_string('review_superseded', 'local_researchcentral'), 'tt-sub') : ''),
            ui::pill($r->recommendation === 'approve' ? 'approved' : ($r->recommendation === 'minor' ? 'submitted' : 'revision'), get_string('rec_' . $r->recommendation, 'local_researchcentral')),
            s(shorten_text((string)$r->comment, 160)) . ($files ? html_writer::div('📎 ' . $files, 'tt-sub') : ''),
            html_writer::link(new moodle_url('/local/researchcentral/stage.php', ['id' => $r->pmsid]), get_string('open', 'local_researchcentral'), ['class' => 'btn btn-sm btn-outline-primary'])];
    }
    echo html_writer::div(html_writer::table($t), 'tt-tablewrap');
    echo $OUTPUT->paging_bar($total, $page, $per, new moodle_url($url, array_filter($f) + ['rec' => $rec, 'q' => $q]));
}
echo $OUTPUT->footer();
