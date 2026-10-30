<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\form\thread_form;
use local_researchcentral\local\forums;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$id = required_param('id', PARAM_INT);
$forum = $DB->get_record('local_researchcentral_forum', ['id' => $id], '*', MUST_EXIST);
forums::require_access($forum);
$mod = forums::can_moderate($forum);

$url = new moodle_url('/local/researchcentral/forum.php', ['id' => $id]);
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title($forum->name);
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

$compose = optional_param('new', 0, PARAM_INT);
$form = new thread_form($url, ['id' => $id, 'mode' => 'thread']);
if ($form->is_cancelled()) {
    redirect($url);
}
if ($data = $form->get_data()) {
    $tid = forums::add_thread($forum, trim($data->subject), trim($data->message));
    redirect(new moodle_url('/local/researchcentral/thread.php', ['id' => $tid]), get_string('forum_thread_posted', 'local_researchcentral'));
}

$q = optional_param('q', '', PARAM_TEXT);
$filter = optional_param('show', 'all', PARAM_ALPHA);
$where = 't.forumid = :fid';
$params = ['fid' => $id];
if ($q !== '') {
    $where .= ' AND (' . $DB->sql_like('t.subject', ':q1', false) . ' OR ' . $DB->sql_like('t.message', ':q2', false) . ')';
    $params['q1'] = $params['q2'] = '%' . $DB->sql_like_escape($q) . '%';
}
if ($filter === 'open') {
    $where .= ' AND t.answerpostid = 0';
} else if ($filter === 'answered') {
    $where .= ' AND t.answerpostid > 0';
} else if ($filter === 'mine') {
    $where .= ' AND t.userid = :me';
    $params['me'] = $USER->id;
}
$sql = "SELECT t.*, (SELECT COUNT(1) FROM {local_researchcentral_post} p WHERE p.threadid = t.id) AS replies
          FROM {local_researchcentral_thread} t WHERE $where ORDER BY t.pinned DESC, t.lastpost DESC";
$threads = $DB->get_records_sql($sql, $params, 0, 200);
$users = $threads ? $DB->get_records_list('user', 'id', array_unique(array_column($threads, 'userid'))) : [];
$readsince = (int)$DB->get_field('local_researchcentral_fread', 'timeread', ['forumid' => $id, 'userid' => $USER->id]);

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/forums.php'), '← ' . get_string('forums', 'local_researchcentral'), ['class' => 'tt-back']);
echo $OUTPUT->heading(s($forum->name), 3);
echo html_writer::div(get_string('forumdesc_' . $forum->type, 'local_researchcentral'), 'tt-sub mb-3');

if ($compose || $form->is_submitted()) {
    echo html_writer::div(html_writer::tag('h5', get_string('forum_ask', 'local_researchcentral')) . $form->render(), 'tt-card');
} else {
    echo html_writer::div(html_writer::link(new moodle_url($url, ['new' => 1]), get_string('forum_ask', 'local_researchcentral'),
        ['class' => 'btn btn-primary']), 'mb-3');
}

$opts = ['all' => get_string('all'), 'open' => get_string('forum_unanswered', 'local_researchcentral'),
    'answered' => get_string('forum_answered', 'local_researchcentral'), 'mine' => get_string('forum_mine', 'local_researchcentral')];
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out(false), 'class' => 'tt-filters']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'q', 'value' => $q, 'class' => 'form-control',
    'placeholder' => get_string('forum_search', 'local_researchcentral')]);
echo html_writer::select($opts, 'show', $filter, false, ['class' => 'custom-select', 'onchange' => 'this.form.submit()']);
echo html_writer::tag('button', get_string('filter', 'local_researchcentral'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

if (!$threads) {
    echo html_writer::div(get_string('forum_empty', 'local_researchcentral'), 'tt-empty');
} else {
    $t = new html_table();
    $t->attributes['class'] = 'tt-table';
    $t->head = [get_string('forum_subject', 'local_researchcentral'), get_string('forum_askedby', 'local_researchcentral'),
        get_string('forum_replies', 'local_researchcentral'), get_string('forum_lastactivity', 'local_researchcentral')];
    foreach ($threads as $th) {
        $flags = '';
        if ($th->pinned) {
            $flags .= html_writer::span(get_string('forum_pinned', 'local_researchcentral'), 'tt-pill tt-active mr-1');
        }
        if ($th->answerpostid) {
            $flags .= html_writer::span('✓ ' . get_string('forum_answered', 'local_researchcentral'), 'tt-pill tt-completed mr-1');
        }
        if ($th->locked) {
            $flags .= html_writer::span(get_string('forum_locked', 'local_researchcentral'), 'tt-pill tt-revision mr-1');
        }
        if ($th->lastpost > $readsince && (int)$th->userid !== (int)$USER->id) {
            $flags .= html_writer::span(get_string('forum_new', 'local_researchcentral'), 'tt-pill tt-review mr-1');
        }
        $u = $users[$th->userid] ?? null;
        $t->data[] = [
            $flags . html_writer::link(new moodle_url('/local/researchcentral/thread.php', ['id' => $th->id]), s($th->subject), ['class' => 'tt-link']),
            $u ? s(fullname($u)) . html_writer::div(get_string('role_' . forums::role_of((int)$th->userid), 'local_researchcentral'), 'tt-sub') : '-',
            (int)$th->replies,
            userdate($th->lastpost, get_string('strftimedatetimeshort', 'langconfig')),
        ];
    }
    echo html_writer::table($t);
}
forums::mark_read($forum);
echo $OUTPUT->footer();
