<?php
// Seminars and research events: schedule, register, record attendance.
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\portal;
use local_researchcentral\form\event_form;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$roles = portal::roles();
if (!$roles) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('seminars', 'local_researchcentral'));
}
$context = context_system::instance();
$url = new moodle_url('/local/researchcentral/seminars.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('seminars', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));
$L = fn(string $k, $a = null) => get_string($k, 'local_researchcentral', $a);
$dts = get_string('strftimedatetimeshort', 'langconfig');
$iscoord = in_array('coordinator', $roles, true);
$cancreate = $iscoord || in_array('supervisor', $roles, true);
$do = optional_param('do', '', PARAM_ALPHA);
$eid = optional_param('event', 0, PARAM_INT);
$edit = optional_param('edit', 0, PARAM_INT);
$mine = $DB->get_fieldset_select('local_researchcentral_proj', 'progid', 'userid = ?', [$USER->id]);
$ismanager = fn(stdClass $e) => $iscoord || (int)$e->createdby === (int)$USER->id;

if ($do && $eid) {
    require_sesskey();
    $e = $DB->get_record('local_researchcentral_event', ['id' => $eid], '*', MUST_EXIST);
    $reg = $DB->get_record('local_researchcentral_eventreg', ['eventid' => $eid, 'userid' => $USER->id]);
    if ($do === 'register' && !$reg && $e->endtime > time()) {
        $n = $DB->count_records('local_researchcentral_eventreg', ['eventid' => $eid]);
        if ($e->capacity > 0 && $n >= $e->capacity) {
            redirect($url, $L('event_full'), null, \core\output\notification::NOTIFY_WARNING);
        }
        $DB->insert_record('local_researchcentral_eventreg', (object)['eventid' => $eid, 'userid' => $USER->id, 'status' => 'registered', 'timecreated' => time()]);
        redirect($url, $L('event_registered'));
    }
    if ($do === 'cancel' && $reg && $reg->status === 'registered') {
        $DB->delete_records('local_researchcentral_eventreg', ['id' => $reg->id]);
        redirect($url, $L('event_unregistered'));
    }
    if ($do === 'delete' && $ismanager($e)) {
        $DB->delete_records('local_researchcentral_eventreg', ['eventid' => $eid]);
        $DB->delete_records('local_researchcentral_event', ['id' => $eid]);
        redirect($url, $L('deleted'));
    }
    if ($do === 'attendance' && $ismanager($e) && data_submitted()) {
        $present = array_map('intval', (array)($_POST['present'] ?? []));
        foreach ($DB->get_records('local_researchcentral_eventreg', ['eventid' => $eid]) as $r) {
            $DB->set_field('local_researchcentral_eventreg', 'status', in_array((int)$r->userid, $present, true) ? 'attended' : 'registered', ['id' => $r->id]);
        }
        redirect($url, $L('saved'));
    }
}

$progs = [];
foreach ($DB->get_records('local_researchcentral_prog', null, 'name', 'id, name') as $p) {
    $progs[$p->id] = format_string($p->name);
}
$form = null;
if ($cancreate) {
    $form = new event_form($url, ['progs' => $progs, 'edit' => $edit]);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($d = $form->get_data()) {
        $row = (object)['title' => trim($d->title), 'descr' => trim($d->descr), 'starttime' => (int)$d->starttime, 'endtime' => (int)$d->starttime + (int)$d->duration * 60,
            'venue' => trim($d->venue), 'joinurl' => trim($d->joinurl), 'progid' => (int)$d->progid, 'level' => '', 'capacity' => max(0, (int)$d->capacity)];
        if ($d->edit) {
            $old = $DB->get_record('local_researchcentral_event', ['id' => $d->edit], '*', MUST_EXIST);
            if (!$ismanager($old)) {
                throw new moodle_exception('nopermissions', 'error');
            }
            $row->id = $old->id;
            $DB->update_record('local_researchcentral_event', $row);
        } else {
            $row->createdby = $USER->id;
            $row->timecreated = time();
            $row->id = $DB->insert_record('local_researchcentral_event', $row);
            if ($d->notify) {
                $sql = "SELECT DISTINCT userid FROM {local_researchcentral_proj}" . ($row->progid ? ' WHERE progid = ?' : '');
                $a = (object)['title' => $row->title, 'when' => userdate($row->starttime, $dts), 'where' => $row->venue ?: ($row->joinurl ?: '-'), 'who' => fullname($USER)];
                foreach ($DB->get_fieldset_sql($sql, $row->progid ? [$row->progid] : []) as $uid) {
                    if ((int)$uid !== (int)$USER->id) {
                        portal::notify((int)$uid, $L('msg_event_subject', $a), $L('msg_event_body', $a), $url, 'workflow');
                    }
                }
            }
        }
        redirect($url, $L('saved'));
    }
}

$events = $DB->get_records_select('local_researchcentral_event', 'endtime > ?', [time() - 30 * DAYSECS], 'starttime');
$regs = [];
foreach ($DB->get_records_list('local_researchcentral_eventreg', 'eventid', array_keys($events ?: [0])) as $r) {
    $regs[$r->eventid][(int)$r->userid] = $r;
}
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/portal.php'), '← ' . $L('pluginname'), ['class' => 'tt-back']);
echo $OUTPUT->heading($L('seminars'), 3);
echo html_writer::div($L('seminars_intro'), 'tt-sub mb-3');
$upcoming = $past = [];
foreach ($events as $e) {
    $relevant = !$e->progid || $iscoord || in_array('supervisor', $roles, true) || in_array((int)$e->progid, array_map('intval', $mine), true) || (int)$e->createdby === (int)$USER->id;
    if (!$relevant) {
        continue;
    }
    if ($e->endtime > time()) {
        $upcoming[] = $e;
    } else {
        $past[] = $e;
    }
}
$card = function (stdClass $e, bool $isfuture) use ($regs, $USER, $L, $dts, $ismanager, $url, $progs, $DB, $edit) {
    $r = $regs[$e->id][(int)$USER->id] ?? null;
    $n = count($regs[$e->id] ?? []);
    $when = userdate($e->starttime, $dts) . ' – ' . userdate($e->endtime, '%H:%M');
    $head = html_writer::div(html_writer::span(s($e->title), 'tt-strong') . ' ' .
        ($e->progid && isset($progs[$e->progid]) ? html_writer::span(s($progs[$e->progid]), 'tt-chk tt-chk-warn') : html_writer::span($L('event_all'), 'tt-chk tt-chk-pass')));
    $meta = html_writer::div($when . ($e->venue ? ' · ' . s($e->venue) : ''), 'tt-sub') .
        ($e->descr ? html_writer::div(nl2br(s($e->descr)), 'tt-body mt-1') : '') .
        html_writer::div($L('event_regs', (object)['n' => $n, 'cap' => $e->capacity ?: '∞']), 'tt-sub mt-1');
    $act = '';
    if ($isfuture) {
        if (trim($e->joinurl) !== '' && preg_match('~^https?://~i', $e->joinurl)) {
            $act .= html_writer::link($e->joinurl, $L('appt_join'), ['class' => 'btn btn-sm btn-outline-primary mr-2', 'target' => '_blank', 'rel' => 'noopener']);
        }
        $act .= $r ? html_writer::link(new moodle_url($url, ['do' => 'cancel', 'event' => $e->id, 'sesskey' => sesskey()]), '✓ ' . $L('event_registered_btn'), ['class' => 'btn btn-sm btn-success mr-2'])
            : html_writer::link(new moodle_url($url, ['do' => 'register', 'event' => $e->id, 'sesskey' => sesskey()]), $L('event_register'), ['class' => 'btn btn-sm btn-primary mr-2']);
    } else if ($r) {
        $act .= html_writer::span($r->status === 'attended' ? '✓ ' . $L('event_attended') : $L('event_registered_btn'), 'tt-flag ' . ($r->status === 'attended' ? 'tt-flag-yes' : 'tt-flag-no'));
    }
    if ($ismanager($e)) {
        $act .= html_writer::link(new moodle_url($url, ['edit' => $e->id]), $L('edit'), ['class' => 'mr-2']) .
            html_writer::link(new moodle_url($url, ['do' => 'delete', 'event' => $e->id, 'sesskey' => sesskey()]), $L('delete'), ['class' => 'mr-2']);
    }
    $att = '';
    if ($ismanager($e) && !$isfuture && !empty($regs[$e->id])) {
        $rows = '';
        foreach ($regs[$e->id] as $uid => $rr) {
            $u = core_user::get_user($uid);
            $rows .= html_writer::div(html_writer::tag('label', html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'present[]', 'value' => $uid] + ($rr->status === 'attended' ? ['checked' => 'checked'] : [])) . ' ' . ($u ? s(fullname($u)) : '-')));
        }
        $att = html_writer::tag('details', html_writer::tag('summary', $L('event_attendance')) .
            html_writer::start_tag('form', ['method' => 'post', 'action' => (new moodle_url($url, ['do' => 'attendance', 'event' => $e->id, 'sesskey' => sesskey()]))->out(false)]) .
            $rows . html_writer::tag('button', $L('save'), ['type' => 'submit', 'class' => 'btn btn-sm btn-primary mt-1']) . html_writer::end_tag('form'), ['class' => 'mt-2']);
    }
    return html_writer::div($head . $meta . html_writer::div($act, 'mt-2') . $att, 'tt-card mb-2');
};
echo html_writer::tag('h4', $L('event_upcoming'));
foreach ($upcoming as $e) {
    echo $card($e, true);
}
if (!$upcoming) {
    echo html_writer::div($L('event_none'), 'tt-empty');
}
if ($past) {
    echo html_writer::tag('h4', $L('event_past'), ['class' => 'mt-4']);
    foreach (array_reverse($past) as $e) {
        echo $card($e, false);
    }
}
if ($form) {
    if ($edit) {
        $e = $DB->get_record('local_researchcentral_event', ['id' => $edit]);
        if ($e && $ismanager($e) && !$form->is_submitted()) {
            $form->set_data((object)['edit' => $e->id, 'title' => $e->title, 'descr' => $e->descr, 'starttime' => $e->starttime, 'duration' => max(30, (int)(($e->endtime - $e->starttime) / 60)),
                'venue' => $e->venue, 'joinurl' => $e->joinurl, 'progid' => $e->progid, 'capacity' => $e->capacity, 'notify' => 0]);
        }
    }
    echo html_writer::tag('h4', $L($edit ? 'event_edit' : 'event_add'), ['class' => 'mt-4']);
    $form->display();
}
echo $OUTPUT->footer();
