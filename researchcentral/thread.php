<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\form\thread_form;
use local_researchcentral\local\forums;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$id = required_param('id', PARAM_INT);
$thread = $DB->get_record('local_researchcentral_thread', ['id' => $id], '*', MUST_EXIST);
$forum = $DB->get_record('local_researchcentral_forum', ['id' => $thread->forumid], '*', MUST_EXIST);
forums::require_access($forum);
$mod = forums::can_moderate($forum);
$isowner = (int)$thread->userid === (int)$USER->id;

$url = new moodle_url('/local/researchcentral/thread.php', ['id' => $id]);
$back = new moodle_url('/local/researchcentral/forum.php', ['id' => $forum->id]);
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title($thread->subject);
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

// Actions.
$action = optional_param('action', '', PARAM_ALPHA);
if ($action) {
    require_sesskey();
    $postid = optional_param('post', 0, PARAM_INT);
    $post = $postid ? $DB->get_record('local_researchcentral_post', ['id' => $postid, 'threadid' => $id]) : null;
    switch ($action) {
        case 'accept':
            if (!$post || !($isowner || $mod)) {
                throw new moodle_exception('nopermissions', 'error', '', 'accept');
            }
            $DB->set_field('local_researchcentral_thread', 'answerpostid', $thread->answerpostid == $post->id ? 0 : $post->id, ['id' => $id]);
            break;
        case 'pin':
        case 'lock':
            if (!$mod) {
                throw new moodle_exception('nopermissions', 'error', '', $action);
            }
            $field = $action === 'pin' ? 'pinned' : 'locked';
            $DB->set_field('local_researchcentral_thread', $field, $thread->$field ? 0 : 1, ['id' => $id]);
            break;
        case 'delpost':
            if (!$post || !($mod || (int)$post->userid === (int)$USER->id)) {
                throw new moodle_exception('nopermissions', 'error', '', 'delete');
            }
            $DB->delete_records('local_researchcentral_post', ['id' => $post->id]);
            if ($thread->answerpostid == $post->id) {
                $DB->set_field('local_researchcentral_thread', 'answerpostid', 0, ['id' => $id]);
            }
            break;
        case 'delthread':
            if (!($mod || $isowner)) {
                throw new moodle_exception('nopermissions', 'error', '', 'delete');
            }
            $DB->delete_records('local_researchcentral_post', ['threadid' => $id]);
            $DB->delete_records('local_researchcentral_thread', ['id' => $id]);
            redirect($back, get_string('forum_deleted', 'local_researchcentral'));
    }
    redirect($url);
}

$form = new thread_form($url, ['id' => $id, 'mode' => 'reply']);
if ($thread->locked) {
    $form = null;
} else if ($data = $form->get_data()) {
    $pid = forums::add_post($thread, $forum, trim($data->message));
    redirect(new moodle_url($url, [], 'p' . $pid), get_string('forum_reply_posted', 'local_researchcentral'));
}

$posts = $DB->get_records('local_researchcentral_post', ['threadid' => $id], 'timecreated, id');
$uids = array_unique(array_merge([$thread->userid], array_column($posts, 'userid')));
$users = $DB->get_records_list('user', 'id', $uids);

$act = function (string $label, array $params, string $class = 'btn btn-sm btn-outline-secondary', bool $confirm = false) use ($url) {
    $a = ['class' => $class . ' mr-1'];
    if ($confirm) {
        $a['onclick'] = "return confirm('" . addslashes_js(get_string('forum_confirmdelete', 'local_researchcentral')) . "');";
    }
    return html_writer::link(new moodle_url($url, $params + ['sesskey' => sesskey()]), $label, $a);
};
$byline = function (int $uid, int $time) use ($users) {
    $u = $users[$uid] ?? null;
    $role = forums::role_of($uid);
    return html_writer::span($u ? s(fullname($u)) : '-', 'tt-strong') . ' ' .
        html_writer::span(get_string('role_' . $role, 'local_researchcentral'), 'tt-pill tt-' . ($role === 'student' ? 'active' : ($role === 'supervisor' ? 'review' : 'completed'))) .
        ' ' . html_writer::span(userdate($time, get_string('strftimedatetimeshort', 'langconfig')), 'tt-sub');
};

echo $OUTPUT->header();
echo html_writer::link($back, '← ' . s($forum->name), ['class' => 'tt-back']);
echo $OUTPUT->heading(s($thread->subject), 3);

$tools = '';
if ($mod) {
    $tools .= $act(get_string($thread->pinned ? 'forum_unpin' : 'forum_pin', 'local_researchcentral'), ['action' => 'pin']);
    $tools .= $act(get_string($thread->locked ? 'forum_unlock' : 'forum_lock', 'local_researchcentral'), ['action' => 'lock']);
}
if ($mod || $isowner) {
    $tools .= $act(get_string('delete'), ['action' => 'delthread'], 'btn btn-sm btn-outline-danger', true);
}
echo html_writer::div(
    html_writer::div($byline((int)$thread->userid, (int)$thread->timecreated), 'mb-2') .
    html_writer::div(forums::format($thread->message), 'tt-body') .
    ($tools ? html_writer::div($tools, 'mt-2') : ''), 'tt-card');

echo html_writer::tag('h5', get_string('forum_replycount', 'local_researchcentral', count($posts)), ['class' => 'mt-3']);
foreach ($posts as $p) {
    $isans = (int)$thread->answerpostid === (int)$p->id;
    $tools = '';
    if ($isowner || $mod) {
        $tools .= $act(get_string($isans ? 'forum_unaccept' : 'forum_accept', 'local_researchcentral'), ['action' => 'accept', 'post' => $p->id],
            $isans ? 'btn btn-sm btn-success' : 'btn btn-sm btn-outline-success');
    }
    if ($mod || (int)$p->userid === (int)$USER->id) {
        $tools .= $act(get_string('delete'), ['action' => 'delpost', 'post' => $p->id], 'btn btn-sm btn-outline-danger', true);
    }
    echo html_writer::div(
        ($isans ? html_writer::div('✓ ' . get_string('forum_acceptedanswer', 'local_researchcentral'), 'tt-strong mb-1', ['style' => 'color:#1b6b3a']) : '') .
        html_writer::div($byline((int)$p->userid, (int)$p->timecreated), 'mb-2') .
        html_writer::div(forums::format($p->message), 'tt-body') .
        ($tools ? html_writer::div($tools, 'mt-2') : ''),
        'tt-card tt-post' . ($isans ? ' tt-post-answer' : ''), ['id' => 'p' . $p->id]);
}
if ($form) {
    echo html_writer::div(html_writer::tag('h5', get_string('forum_yourreply', 'local_researchcentral')) . $form->render(), 'tt-card');
} else {
    echo html_writer::div(get_string('forum_locked_note', 'local_researchcentral'), 'tt-banner');
}
echo $OUTPUT->footer();
