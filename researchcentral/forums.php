<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\forums;
use local_researchcentral\local\portal;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/researchcentral/forums.php');
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('forums', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

$list = forums::visible();
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/portal.php'), '← ' . get_string('back', 'local_researchcentral'), ['class' => 'tt-back']);
echo $OUTPUT->heading(get_string('forums', 'local_researchcentral'), 3);
if (!$list) {
    echo html_writer::div(get_string('portal_nothing', 'local_researchcentral'), 'tt-empty');
    echo $OUTPUT->footer();
    exit;
}
echo html_writer::div(get_string('forums_intro', 'local_researchcentral'), 'tt-sub mb-3');
$groups = [];
foreach ($list as $f) {
    $groups[$f->type][] = $f;
}
foreach (forums::TYPES as $type) {
    if (empty($groups[$type])) {
        continue;
    }
    echo html_writer::tag('h5', get_string('forumtype_' . $type, 'local_researchcentral'), ['class' => 'mt-3']);
    $cards = '';
    foreach ($groups[$type] as $f) {
        $badge = $f->unread ? html_writer::span($f->unread . ' ' . get_string('forum_new', 'local_researchcentral'), 'tt-pill tt-review ml-2') : '';
        $cards .= html_writer::link(new moodle_url('/local/researchcentral/forum.php', ['id' => $f->id]),
            html_writer::div(s($f->name) . $badge, 'tt-strong') .
            html_writer::div(get_string('forum_threadcount', 'local_researchcentral', $f->threads) . ' · ' .
                get_string('forumdesc_' . $f->type, 'local_researchcentral'), 'tt-sub'),
            ['class' => 'tt-card tt-hubcard']);
    }
    echo html_writer::div($cards, 'tt-chartgrid');
}
echo $OUTPUT->footer();
