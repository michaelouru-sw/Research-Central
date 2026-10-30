<?php
// Supervisor comment bank: reusable review comments that can be inserted while reviewing.
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\portal;
use local_researchcentral\form\snip_form;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
if (!portal::is_supervisor() && !portal::is_coordinator()) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('comment_bank', 'local_researchcentral'));
}
$url = new moodle_url('/local/researchcentral/comments.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('comment_bank', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));
$L = fn(string $k, $a = null) => get_string($k, 'local_researchcentral', $a);
$edit = optional_param('edit', 0, PARAM_INT);
if (optional_param('do', '', PARAM_ALPHA) === 'delete' && $edit) {
    require_sesskey();
    $DB->delete_records('local_researchcentral_snip', ['id' => $edit, 'userid' => $USER->id]);
    redirect($url, $L('deleted'));
}
$form = new snip_form($url, ['edit' => $edit]);
if ($form->is_cancelled()) {
    redirect($url);
}
if ($d = $form->get_data()) {
    $row = (object)['userid' => $USER->id, 'title' => trim($d->title), 'body' => trim($d->body)];
    if ($d->edit) {
        $DB->get_record('local_researchcentral_snip', ['id' => $d->edit, 'userid' => $USER->id], 'id', MUST_EXIST);
        $row->id = $d->edit;
        $DB->update_record('local_researchcentral_snip', $row);
    } else {
        $row->timecreated = time();
        $DB->insert_record('local_researchcentral_snip', $row);
    }
    redirect($url, $L('saved'));
}
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/portal.php'), '← ' . $L('pluginname'), ['class' => 'tt-back']);
echo $OUTPUT->heading($L('comment_bank'), 3);
echo html_writer::div($L('comment_bank_d'), 'tt-sub mb-3');
$rows = $DB->get_records('local_researchcentral_snip', ['userid' => $USER->id], 'title');
$t = new html_table();
$t->attributes['class'] = 'tt-table';
$t->head = [$L('snip_title'), $L('snip_body'), ''];
foreach ($rows as $r) {
    $t->data[] = [s($r->title), nl2br(s($r->body)), html_writer::link(new moodle_url($url, ['edit' => $r->id]), $L('edit'), ['class' => 'mr-2']) .
        html_writer::link(new moodle_url($url, ['edit' => $r->id, 'do' => 'delete', 'sesskey' => sesskey()]), $L('delete'))];
    if ($edit === (int)$r->id && !$form->is_submitted()) {
        $form->set_data((object)['edit' => $r->id, 'title' => $r->title, 'body' => $r->body]);
    }
}
echo $rows ? html_writer::div(html_writer::table($t), 'tt-tablewrap') : html_writer::div($L('snip_none'), 'tt-empty');
echo html_writer::tag('h4', $L($edit ? 'snip_edit' : 'snip_add'), ['class' => 'mt-4']);
$form->display();
echo $OUTPUT->footer();
