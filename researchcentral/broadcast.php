<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\comms;
use local_researchcentral\local\portal;
use local_researchcentral\local\tracker;
use local_researchcentral\output\ui;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
comms::require_allowed();
$view = optional_param('view', 'compose', PARAM_ALPHA);
$url = new moodle_url('/local/researchcentral/broadcast.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('broadcast', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

$audiences = comms::audiences();
$readf = function () use ($audiences) {
    $aud = optional_param('audience', 'students', PARAM_ALPHA);
    if (!isset($audiences[$aud])) {
        $aud = 'students';
    }
    return [$aud, [
        'schoolid' => optional_param('schoolid', 0, PARAM_INT),
        'level' => optional_param('level', '', PARAM_ALPHA),
        'progid' => optional_param('progid', 0, PARAM_INT),
        'supervisorid' => optional_param('supervisorid', 0, PARAM_INT),
        'state' => optional_param('state', '', PARAM_ALPHA),
        'stalled' => optional_param('stalled', 0, PARAM_INT),
        'q' => optional_param('q', '', PARAM_TEXT),
    ]];
};
[$audience, $f] = $readf();

// Send.
if (optional_param('send', 0, PARAM_INT) && data_submitted()) {
    require_sesskey();
    $subject = trim(required_param('subject', PARAM_TEXT));
    $body = trim(required_param('body', PARAM_RAW));
    $channel = optional_param('channel', 'email', PARAM_ALPHA) === 'notify' ? 'notify' : 'email';
    $picked = array_map('intval', optional_param_array('rcpt', [], PARAM_INT));
    $all = comms::resolve($audience, $f);           // Scope is re-checked here; posted ids are only a subset selector.
    $recipients = array_intersect_key($all, array_flip($picked));
    if ($subject === '' || $body === '' || !$recipients) {
        redirect(new moodle_url($url, ['audience' => $audience] + array_filter($f)), get_string('bc_missing', 'local_researchcentral'), null, \core\output\notification::NOTIFY_ERROR);
    }
    $b = comms::send($subject, $body, $channel, comms::describe($audience, $f, count($recipients)), $recipients, (bool)optional_param('copyme', 0, PARAM_INT));
    $msg = $b->status === 'done' ? get_string('bc_sent', 'local_researchcentral', (object)['sent' => $b->sent, 'total' => $b->total])
        : get_string('bc_queued', 'local_researchcentral', $b->total);
    redirect(new moodle_url($url, ['view' => 'history']), $msg, null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/portal.php', ['view' => 'coordinator']), '← ' . get_string('back', 'local_researchcentral'), ['class' => 'tt-back']);
echo $OUTPUT->heading(get_string('broadcast', 'local_researchcentral'), 3);
echo ui::tabs(['compose' => get_string('bc_compose', 'local_researchcentral'), 'history' => get_string('bc_history', 'local_researchcentral')], $view, $url);

if ($view === 'history') {
    $sql = "SELECT b.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
              FROM {local_researchcentral_bcast} b JOIN {user} u ON u.id = b.userid ";
    $params = [];
    if (!is_siteadmin()) {
        $sql .= 'WHERE b.userid = :me ';
        $params['me'] = $USER->id;
    }
    $rows = $DB->get_records_sql($sql . 'ORDER BY b.timecreated DESC', $params, 0, 100);
    if (!$rows) {
        echo html_writer::div(get_string('bc_nohistory', 'local_researchcentral'), 'tt-empty');
    } else {
        $t = new html_table();
        $t->attributes['class'] = 'tt-table';
        $t->head = [get_string('bc_when', 'local_researchcentral'), get_string('bc_from', 'local_researchcentral'), get_string('bc_subject', 'local_researchcentral'),
            get_string('bc_audience', 'local_researchcentral'), get_string('bc_delivered', 'local_researchcentral')];
        foreach ($rows as $r) {
            $t->data[] = [userdate($r->timecreated, get_string('strftimedatetimeshort', 'langconfig')), s(fullname($r)), s($r->subject),
                s($r->audience), $r->sent . ' / ' . $r->total . ' ' . html_writer::span(get_string('bcstatus_' . $r->status, 'local_researchcentral'), 'tt-sub')];
        }
        echo html_writer::table($t);
    }
    echo $OUTPUT->footer();
    exit;
}

// Compose: filters.
$all = portal::projects('coordinator');
$progopts = $supopts = [];
foreach ($all as $p) {
    $progopts[$p->progid] = $p->progname;
    $supopts[$p->supervisorid] = ui::user_line($p->supervisor);
}
asort($progopts);
asort($supopts);
$states = [];
foreach (['active', 'review', 'revision', 'overdue', 'completed'] as $sx) {
    $states[$sx] = get_string('state_' . $sx, 'local_researchcentral');
}
$sel = fn(string $n, array $o, $v, string $none) => html_writer::select($o, $n, $v, $none, ['class' => 'custom-select']);
echo html_writer::div(get_string('bc_intro', 'local_researchcentral'), 'tt-sub mb-2');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out(false), 'class' => 'tt-filters']);
echo html_writer::select($audiences, 'audience', $audience, false, ['class' => 'custom-select']);
echo $sel('schoolid', portal::schools(true), $f['schoolid'], get_string('all_schools', 'local_researchcentral'));
if ($audience !== 'coordinators') {
    echo $sel('level', tracker::levels(), $f['level'], get_string('level_all', 'local_researchcentral'));
    echo $sel('progid', $progopts, $f['progid'], get_string('all_programmes', 'local_researchcentral'));
    echo $sel('supervisorid', $supopts, $f['supervisorid'], get_string('all_supervisors', 'local_researchcentral'));
    echo $sel('state', $states, $f['state'], get_string('bc_anystate', 'local_researchcentral'));
    echo $sel('stalled', [7 => get_string('stalled_n', 'local_researchcentral', 7), 14 => get_string('stalled_n', 'local_researchcentral', 14),
        30 => get_string('stalled_n', 'local_researchcentral', 30), 60 => get_string('stalled_n', 'local_researchcentral', 60)], $f['stalled'],
        get_string('bc_anyactivity', 'local_researchcentral'));
}
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'q', 'value' => $f['q'], 'class' => 'form-control', 'placeholder' => get_string('bc_findperson', 'local_researchcentral')]);
echo html_writer::tag('button', get_string('bc_preview', 'local_researchcentral'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::link($url, get_string('reset_filters', 'local_researchcentral'), ['class' => 'btn btn-outline-secondary']);
echo html_writer::end_tag('form');

$rcpts = comms::resolve($audience, $f);
echo html_writer::div(html_writer::tag('strong', (string)count($rcpts)) . ' ' . get_string('bc_matching', 'local_researchcentral'), 'tt-sub mb-2');
if (!$rcpts) {
    echo html_writer::div(get_string('bc_none', 'local_researchcentral'), 'tt-empty');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'send', 'value' => 1]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'audience', 'value' => $audience]);
foreach ($f as $k => $v) {
    if ($v !== '' && $v !== 0) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
    }
}
$t = new html_table();
$t->attributes['class'] = 'tt-table';
$t->head = [html_writer::empty_tag('input', ['type' => 'checkbox', 'checked' => 'checked', 'id' => 'tt-all', 'title' => get_string('selectall')]),
    get_string('bc_recipient', 'local_researchcentral'), 'Email', get_string('bc_context', 'local_researchcentral')];
foreach ($rcpts as $u) {
    $t->data[] = [html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'rcpt[]', 'value' => $u->id, 'checked' => 'checked', 'class' => 'tt-rc']),
        s(fullname($u)), s($u->email), s($u->ctx ?? '')];
}
echo html_writer::div(html_writer::table($t), 'tt-rcpt mb-3');
$PAGE->requires->js_amd_inline("document.getElementById('tt-all').addEventListener('change',function(e){document.querySelectorAll('.tt-rc').forEach(function(c){c.checked=e.target.checked;});});");

echo html_writer::start_div('tt-card tt-composer');
echo html_writer::tag('h5', get_string('bc_message', 'local_researchcentral'));
echo html_writer::div(html_writer::tag('label', get_string('bc_subject', 'local_researchcentral'), ['for' => 'tt-sub']) .
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'subject', 'id' => 'tt-sub', 'class' => 'form-control', 'required' => 'required', 'maxlength' => 255]), 'mb-2');
echo html_writer::div(html_writer::tag('label', get_string('bc_body', 'local_researchcentral'), ['for' => 'tt-body']) .
    html_writer::tag('textarea', '', ['name' => 'body', 'id' => 'tt-body', 'rows' => 9, 'class' => 'form-control', 'required' => 'required']) .
    html_writer::div(get_string('bc_merge', 'local_researchcentral') . ' ' . html_writer::span(s(implode('  ', comms::merge_fields())), 'tt-chk tt-chk-pass'), 'tt-sub mt-1'), 'mb-2');
echo html_writer::div(html_writer::tag('label', get_string('bc_channel', 'local_researchcentral'), ['for' => 'tt-ch']) .
    html_writer::select(['email' => get_string('bc_ch_email', 'local_researchcentral'), 'notify' => get_string('bc_ch_notify', 'local_researchcentral')],
        'channel', 'email', false, ['class' => 'custom-select', 'id' => 'tt-ch']), 'mb-2');
echo html_writer::div(html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'copyme', 'value' => 1, 'id' => 'tt-copy']) . ' ' .
    html_writer::tag('label', get_string('bc_copyme', 'local_researchcentral'), ['for' => 'tt-copy']), 'mb-3');
echo html_writer::tag('button', get_string('bc_send', 'local_researchcentral'), ['type' => 'submit', 'class' => 'btn btn-primary',
    'onclick' => "return confirm('" . addslashes_js(get_string('bc_confirm', 'local_researchcentral')) . "');"]);
echo html_writer::end_div();
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
