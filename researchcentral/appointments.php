<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\appt;
use local_researchcentral\local\cal;
use local_researchcentral\local\portal;
use local_researchcentral\output\ui;
use local_researchcentral\form\slot_form;
use local_researchcentral\form\book_form;
use local_researchcentral\form\apptedit_form;
use local_researchcentral\form\links_form;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$context = context_system::instance();
$isstudent = portal::is_student();
$issup = portal::is_supervisor();
if (!$isstudent && !$issup) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('book_appointment', 'local_researchcentral'));
}
$url = new moodle_url('/local/researchcentral/appointments.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('appointments', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

$fmt = get_string('strftimedatetimeshort', 'langconfig');
$action = optional_param('action', '', PARAM_ALPHA);
$slotid = optional_param('slot', 0, PARAM_INT);
$ok = \core\output\notification::NOTIFY_SUCCESS;
$bad = \core\output\notification::NOTIFY_ERROR;

/** Projects the current user supervises: [projid => "Student (programme)"]. */
$myprojects = function () use ($DB, $USER) {
    $out = [];
    foreach (portal::projects('supervisor') as $p) {
        $out[$p->id] = ($p->student ? fullname($p->student) : '-') . ' (' . format_string($p->progname) . ')';
    }
    return $out;
};

// ---- Supervisor actions -------------------------------------------------------------------------------------------
$slotform = $linksform = $editform = null;
if ($issup) {
    $students = $myprojects();
    $slotform = new slot_form($url, ['students' => $students, 'activities' => appt::activity_links($USER->id)]);
    if ($data = $slotform->get_data()) {
        $platform = $data->platform;
        $link = trim($data->joinurl ?? '');
        if (!empty($data->activity) && strpos($data->activity, '|') !== false) {
            [$platform, $link] = explode('|', $data->activity, 2);
        }
        if ($link === '' && $platform !== 'inperson') {
            $link = appt::default_link($USER->id, $platform);
        }
        if (!empty($data->savelink) && $link !== '' && $platform !== 'inperson' && empty($data->activity)) {
            set_user_preference('local_researchcentral_link_' . $platform, $link);
        }
        $windows = [];
        for ($i = 1; $i <= 3; $i++) {
            if (($data->{'ws' . $i} ?? '') !== '' && ($data->{'we' . $i} ?? '') !== '') {
                $windows[] = [(int)$data->{'ws' . $i}, (int)$data->{'we' . $i}];
            }
        }
        $days = [];
        for ($d = 0; $d <= 6; $d++) {
            if (!empty($data->{'day' . $d})) {
                $days[] = $d;
            }
        }
        [$made, $skipped] = appt::generate($USER->id, ['from' => $data->fromdate, 'to' => !empty($data->userange) ? $data->todate : $data->fromdate,
            'days' => $days, 'windows' => $windows, 'duration' => $data->duration, 'gap' => $data->gap, 'platform' => $platform,
            'location' => $data->location, 'joinurl' => $link, 'agenda' => $data->agenda, 'supnote' => $data->supnote,
            'projid' => (int)($data->projid ?? 0)]);
        redirect($url, get_string('slots_added_skipped', 'local_researchcentral', (object)['n' => $made, 's' => $skipped]), null, $made ? $ok : $bad);
    }
    $linksform = new links_form($url);
    if ($data = $linksform->get_data()) {
        foreach (['zoom', 'meet', 'teams'] as $p) {
            set_user_preference('local_researchcentral_link_' . $p, trim($data->{'link_' . $p} ?? ''));
        }
        redirect($url, get_string('links_saved', 'local_researchcentral'), null, $ok);
    }
    $linksform->set_data((object)['link_zoom' => appt::default_link($USER->id, 'zoom'), 'link_meet' => appt::default_link($USER->id, 'meet'),
        'link_teams' => appt::default_link($USER->id, 'teams')]);

    if ($action === 'edit' && $slotid) {
        $a = $DB->get_record('local_researchcentral_appt', ['id' => $slotid, 'supervisorid' => $USER->id], '*', MUST_EXIST);
        $editform = new apptedit_form($url, ['slot' => $slotid, 'when' => userdate($a->starttime, $fmt) . ' – ' . userdate($a->endtime, '%H:%M'),
            'students' => $a->status === 'open' ? $students : []]);
        if ($editform->is_cancelled()) {
            redirect($url);
        }
        if ($data = $editform->get_data()) {
            $upd = (object)['id' => $a->id, 'platform' => $data->platform, 'joinurl' => trim($data->joinurl ?? ''), 'location' => trim($data->location),
                'agenda' => trim($data->agenda), 'supnote' => trim($data->supnote), 'minutes' => trim($data->minutes)];
            $allocate = null;
            if ($a->status === 'open' && !empty($data->projid)) {
                $proj = $DB->get_record('local_researchcentral_proj', ['id' => (int)$data->projid], '*', MUST_EXIST);
                if (!portal::is_project_supervisor($proj) || appt::clash((int)$proj->userid, (int)$a->starttime, (int)$a->endtime, (int)$a->id)) {
                    redirect($url, get_string('appt_clash_student', 'local_researchcentral'), null, $bad);
                }
                $upd->studentid = $proj->userid;
                $upd->projid = $proj->id;
                $upd->status = 'booked';
                $upd->allocated = 1;
                $upd->topic = trim($data->agenda);
                $allocate = (int)$proj->userid;
            }
            $DB->update_record('local_researchcentral_appt', $upd);
            $fresh = $DB->get_record('local_researchcentral_appt', ['id' => $a->id]);
            if ($fresh->status === 'booked') {
                cal::sync_events($fresh);
                appt::tell($fresh, $allocate ? 'allocated' : 'changed', (int)$fresh->studentid);
            }
            redirect($url, get_string('appt_saved', 'local_researchcentral'), null, $ok);
        }
    }
    if ($action === 'cancelopen') {
        require_sesskey();
        $n = $DB->count_records_select('local_researchcentral_appt', "supervisorid = ? AND status = 'open' AND starttime > ?", [$USER->id, time()]);
        $DB->set_field_select('local_researchcentral_appt', 'status', 'cancelled', "supervisorid = ? AND status = 'open' AND starttime > ?", [$USER->id, time()]);
        redirect($url, get_string('slots_removed', 'local_researchcentral', $n), null, $ok);
    }
}

// ---- Shared actions (supervisor or student) -----------------------------------------------------------------------
if ($action === 'cancel' && $slotid) {
    require_sesskey();
    $a = $DB->get_record('local_researchcentral_appt', ['id' => $slotid], '*', MUST_EXIST);
    $mine = (int)$a->supervisorid === (int)$USER->id || (int)$a->studentid === (int)$USER->id;
    if (!$mine || $a->status === 'cancelled') {
        throw new moodle_exception('nopermissions', 'error', '', 'cancel');
    }
    $info = (object)['when' => userdate($a->starttime, $fmt), 'who' => fullname($USER)];
    $bysup = (int)$a->supervisorid === (int)$USER->id;
    cal::drop_events($a);
    if ($bysup || $a->allocated) {
        $DB->set_field('local_researchcentral_appt', 'status', 'cancelled', ['id' => $a->id]);
        $other = $bysup ? (int)$a->studentid : (int)$a->supervisorid;
    } else {
        $DB->update_record('local_researchcentral_appt', (object)['id' => $a->id, 'studentid' => 0, 'projid' => 0, 'status' => 'open', 'topic' => '',
            'studentconf' => 0, 'supconf' => 0, 'rem24' => 0, 'rem1' => 0]);
        $other = (int)$a->supervisorid;
    }
    if ($other) {
        portal::notify($other, get_string('msg_cancel_subject', 'local_researchcentral', $info),
            get_string('msg_cancel_body', 'local_researchcentral', $info), $url, 'booking');
    }
    redirect($url, get_string('appt_cancelled_ok', 'local_researchcentral'), null, $ok);
}
if (in_array($action, ['attended', 'missed'], true) && $slotid) {
    require_sesskey();
    $a = $DB->get_record('local_researchcentral_appt', ['id' => $slotid], '*', MUST_EXIST);
    appt::confirm($a, (int)$USER->id, $action === 'attended');
    redirect($url, get_string('av_recorded', 'local_researchcentral'), null, $ok);
}

// ---- Student booking ----------------------------------------------------------------------------------------------
$bookform = null;
if ($isstudent && $slotid && in_array($action, ['book', 'pick'], true)) {
    $slot = $DB->get_record('local_researchcentral_appt', ['id' => $slotid], '*', MUST_EXIST);
    $proj = null;
    foreach (portal::projects('student') as $p) {
        if (portal::is_project_supervisor($p, (int)$slot->supervisorid)) {
            $proj = $p;
            break;
        }
    }
    if (!$proj) {
        throw new moodle_exception('nopermissions', 'error', '', 'book');
    }
    $bookform = new book_form($url, ['slot' => $slotid, 'when' => userdate($slot->starttime, $fmt) . ' – ' . userdate($slot->endtime, '%H:%M'),
        'agenda' => $slot->agenda, 'where' => cal::where($slot)]);
    if ($bookform->is_cancelled()) {
        redirect($url);
    }
    if ($data = $bookform->get_data()) {
        $tx = $DB->start_delegated_transaction();
        $fresh = $DB->get_record('local_researchcentral_appt', ['id' => $slotid], '*', MUST_EXIST);
        if ($fresh->status !== 'open' || $fresh->starttime < time()) {
            $tx->allow_commit();
            redirect($url, get_string('slot_taken', 'local_researchcentral'), null, $bad);
        }
        if (appt::clash((int)$USER->id, (int)$fresh->starttime, (int)$fresh->endtime)) {
            $tx->allow_commit();
            redirect($url, get_string('appt_clash_you', 'local_researchcentral'), null, $bad);
        }
        $DB->update_record('local_researchcentral_appt', (object)['id' => $slotid, 'studentid' => $USER->id,
            'projid' => $proj->id, 'status' => 'booked', 'topic' => trim($data->topic), 'allocated' => 0]);
        $tx->allow_commit();
        $booked = $DB->get_record('local_researchcentral_appt', ['id' => $slotid]);
        cal::sync_events($booked);
        $info = appt::info($booked);
        foreach ([(int)$slot->supervisorid, (int)$USER->id] as $uid) {
            portal::notify($uid, get_string('msg_booked_subject', 'local_researchcentral', $info),
                get_string('msg_booked_body', 'local_researchcentral', $info), $url, 'booking');
        }
        redirect($url, get_string('appt_booked_ok', 'local_researchcentral'), null, $ok);
    }
}

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/portal.php'), '← ' . get_string('back', 'local_researchcentral'), ['class' => 'tt-back']);
echo $OUTPUT->heading(get_string('appointments', 'local_researchcentral'), 3);

if ($bookform) {
    $bookform->display();
    echo $OUTPUT->footer();
    exit;
}
if ($editform) {
    echo html_writer::tag('h5', get_string('appt_edit', 'local_researchcentral'));
    $a = $DB->get_record('local_researchcentral_appt', ['id' => $slotid]);
    $editform->set_data((object)['platform' => $a->platform ?: 'inperson', 'joinurl' => $a->joinurl, 'location' => $a->location,
        'agenda' => $a->agenda, 'supnote' => $a->supnote, 'minutes' => $a->minutes]);
    $editform->display();
    echo $OUTPUT->footer();
    exit;
}

// ---- Rendering helpers --------------------------------------------------------------------------------------------
$userbyid = fn($id) => $id ? (($u = core_user::get_user($id)) ? s(fullname($u)) : '-') : '-';
$vmap = ['verified' => 'approved', 'awaiting' => 'submitted', 'disputed' => 'overdue', 'missed' => 'revision', 'upcoming' => 'active',
    'open' => 'pending', 'cancelled' => 'pending'];
$cancelbtn = fn($a) => html_writer::link(new moodle_url($url, ['action' => 'cancel', 'slot' => $a->id, 'sesskey' => sesskey()]),
    get_string('cancel'), ['class' => 'btn btn-sm btn-outline-danger']);
$where = function (stdClass $a): string {
    $out = '';
    if (trim((string)$a->joinurl) !== '') {
        $out .= html_writer::link(new moodle_url('/local/researchcentral/join.php', ['id' => $a->id]),
            '▶ ' . get_string('appt_join', 'local_researchcentral') . ' (' . s(appt::platforms()[$a->platform ?: 'other'] ?? '') . ')',
            ['class' => 'btn btn-sm btn-primary mb-1', 'target' => '_blank', 'rel' => 'noopener']);
    }
    if (trim((string)$a->location) !== '') {
        $out .= html_writer::div(s($a->location), 'tt-sub');
    }
    return $out ?: '-';
};
$calbtns = fn($a) => html_writer::link(cal::google_url($a), 'Google', ['class' => 'btn btn-sm btn-outline-secondary mr-1', 'target' => '_blank', 'rel' => 'noopener']) .
    html_writer::link(new moodle_url('/local/researchcentral/ical.php', ['id' => $a->id]), '.ics', ['class' => 'btn btn-sm btn-outline-secondary']);
$evidence = function (stdClass $a): string {
    $bits = [];
    if ((int)$a->studentjoin) {
        $bits[] = get_string('av_studentjoined', 'local_researchcentral');
    }
    if ((int)$a->supjoin) {
        $bits[] = get_string('av_supjoined', 'local_researchcentral');
    }
    return $bits ? html_writer::div(s(implode(' · ', $bits)), 'tt-sub') : '';
};
/** Attendance buttons for the current user when the meeting has started and they have not answered. */
$attend = function (stdClass $a) use ($USER, $url): string {
    if ($a->status === 'cancelled' || $a->status === 'open' || time() < (int)$a->starttime) {
        return '';
    }
    $mineconf = (int)$a->studentid === (int)$USER->id ? (int)$a->studentconf : (int)$a->supconf;
    if ($mineconf) {
        return html_writer::div(get_string($mineconf === 1 ? 'av_youattended' : 'av_youmissed', 'local_researchcentral'), 'tt-sub');
    }
    return html_writer::link(new moodle_url($url, ['action' => 'attended', 'slot' => $a->id, 'sesskey' => sesskey()]),
        '✓ ' . get_string('av_attended', 'local_researchcentral'), ['class' => 'btn btn-sm btn-success mr-1']) .
        html_writer::link(new moodle_url($url, ['action' => 'missed', 'slot' => $a->id, 'sesskey' => sesskey()]),
        get_string('av_notheld', 'local_researchcentral'), ['class' => 'btn btn-sm btn-outline-secondary']);
};
/** Table of appointments grouped by day. */
$render = function (array $rows, string $viewer) use ($fmt, $userbyid, $vmap, $cancelbtn, $where, $calbtns, $evidence, $attend, $url, $OUTPUT): string {
    if (!$rows) {
        return html_writer::div(get_string('no_bookings', 'local_researchcentral'), 'tt-empty');
    }
    $byday = [];
    foreach ($rows as $a) {
        $byday[userdate($a->starttime, '%A %d %B %Y')][] = $a;
    }
    $out = '';
    foreach ($byday as $day => $list) {
        $out .= html_writer::tag('h6', s($day), ['class' => 'tt-dayhead']);
        $t = new html_table();
        $t->attributes['class'] = 'tt-table';
        $t->head = [get_string('slot_when', 'local_researchcentral'), get_string($viewer === 'student' ? 'supervisor' : 'student', 'local_researchcentral'),
            get_string('appt_agenda', 'local_researchcentral'), get_string('slot_location', 'local_researchcentral'),
            get_string('status', 'local_researchcentral'), ''];
        foreach ($list as $a) {
            $v = appt::verification($a);
            $with = $viewer === 'student' ? $userbyid($a->supervisorid) : $userbyid($a->studentid);
            $notes = ($a->topic ? html_writer::div(s($a->topic), 'tt-strong') : '') .
                ($a->agenda && $a->agenda !== $a->topic ? html_writer::div(s($a->agenda), 'tt-sub') : '') .
                ($a->minutes ? html_writer::div(html_writer::span(get_string('appt_minutes', 'local_researchcentral') . ': ', 'tt-label') . s($a->minutes), 'tt-feedback') : '') .
                ($viewer === 'supervisor' && $a->supnote ? html_writer::div(html_writer::span(get_string('appt_supnote', 'local_researchcentral') . ': ', 'tt-label') . s($a->supnote), 'tt-sub') : '');
            $btns = '';
            if ($v !== 'cancelled') {
                if ($a->status === 'booked' && time() < (int)$a->endtime) {
                    $btns .= $calbtns($a) . ' ';
                }
                $btns .= $attend($a);
                if ($viewer === 'supervisor') {
                    $btns .= ' ' . html_writer::link(new moodle_url($url, ['action' => 'edit', 'slot' => $a->id]), get_string('edit'),
                        ['class' => 'btn btn-sm btn-outline-primary']);
                }
                if (time() < (int)$a->endtime && $a->status !== 'done') {
                    $btns .= ' ' . $cancelbtn($a);
                }
            }
            $t->data[] = [userdate($a->starttime, '%H:%M') . ' – ' . userdate($a->endtime, '%H:%M') .
                ($a->allocated ? html_writer::div(get_string('appt_allocated', 'local_researchcentral'), 'tt-sub') : ''),
                $with, $notes ?: '-', $where($a),
                ui::pill($vmap[$v], get_string('av_' . $v, 'local_researchcentral')) . $evidence($a), $btns];
        }
        $out .= html_writer::div(html_writer::table($t), 'tt-tablewrap');
    }
    return $out;
};

echo html_writer::div(get_string('appt_calendar_hint', 'local_researchcentral') . ' ' .
    html_writer::link(new moodle_url('/calendar/export.php'), get_string('appt_calendar_export', 'local_researchcentral')), 'tt-sub mb-3');

// ---- Student view -------------------------------------------------------------------------------------------------
if ($isstudent) {
    echo html_writer::tag('h5', get_string('my_bookings', 'local_researchcentral'));
    $upcoming = $DB->get_records_select('local_researchcentral_appt', "studentid = ? AND status <> 'cancelled' AND endtime > ?", [$USER->id, time()], 'starttime');
    echo $render($upcoming, 'student');
    $past = $DB->get_records_select('local_researchcentral_appt', "studentid = ? AND status <> 'cancelled' AND endtime <= ? AND endtime > ?",
        [$USER->id, time(), time() - 60 * DAYSECS], 'starttime DESC', '*', 0, 15);
    if ($past) {
        echo html_writer::tag('h5', get_string('past_meetings', 'local_researchcentral'), ['class' => 'mt-4']);
        echo $render($past, 'student');
    }

    echo html_writer::tag('h5', get_string('available_slots', 'local_researchcentral'), ['class' => 'mt-4']);
    $sups = [];
    foreach (portal::projects('student') as $p) {
        $sups = array_merge($sups, portal::supervisor_ids($p));
    }
    $open = [];
    if ($sups) {
        [$in, $params] = $DB->get_in_or_equal(array_unique($sups), SQL_PARAMS_NAMED);
        $params['now'] = time();
        $open = $DB->get_records_select('local_researchcentral_appt', "supervisorid $in AND status = 'open' AND starttime > :now",
            $params, 'starttime', '*', 0, 120);
    }
    if (!$open) {
        echo html_writer::div(get_string('no_slots', 'local_researchcentral'), 'tt-empty');
    } else {
        $byday = [];
        foreach ($open as $a) {
            $byday[userdate($a->starttime, '%A %d %B %Y')][] = $a;
        }
        foreach ($byday as $day => $list) {
            $chips = '';
            foreach ($list as $a) {
                $chips .= html_writer::link(new moodle_url($url, ['action' => 'pick', 'slot' => $a->id]),
                    html_writer::span(userdate($a->starttime, '%H:%M') . ' – ' . userdate($a->endtime, '%H:%M'), 'tt-strong') .
                    html_writer::div($userbyid($a->supervisorid) . (appt::is_online($a) ? ' · ' . get_string('platform_online', 'local_researchcentral') : ''), 'tt-sub'),
                    ['class' => 'tt-slot']);
            }
            echo html_writer::tag('h6', s($day), ['class' => 'tt-dayhead']) . html_writer::div($chips, 'tt-slots');
        }
    }
}

// ---- Supervisor view ----------------------------------------------------------------------------------------------
if ($issup) {
    echo html_writer::tag('h5', get_string('my_calendar', 'local_researchcentral'), ['class' => 'mt-4']);
    $up = $DB->get_records_select('local_researchcentral_appt', "supervisorid = ? AND status <> 'cancelled' AND endtime > ?", [$USER->id, time()], 'starttime', '*', 0, 200);
    echo $render($up, 'supervisor');
    $past = $DB->get_records_select('local_researchcentral_appt', "supervisorid = ? AND studentid > 0 AND status <> 'cancelled' AND endtime <= ? AND endtime > ?",
        [$USER->id, time(), time() - 60 * DAYSECS], 'starttime DESC', '*', 0, 25);
    if ($past) {
        echo html_writer::tag('h5', get_string('past_meetings', 'local_researchcentral'), ['class' => 'mt-4']);
        echo $render($past, 'supervisor');
    }
    $nopen = $DB->count_records_select('local_researchcentral_appt', "supervisorid = ? AND status = 'open' AND starttime > ?", [$USER->id, time()]);
    echo html_writer::tag('h5', get_string('add_slots', 'local_researchcentral'), ['class' => 'mt-4']);
    echo html_writer::div(get_string('slot_intro', 'local_researchcentral'), 'tt-sub mb-2');
    $slotform->display();
    if ($nopen) {
        echo html_writer::link(new moodle_url($url, ['action' => 'cancelopen', 'sesskey' => sesskey()]),
            get_string('slots_remove_open', 'local_researchcentral', $nopen), ['class' => 'btn btn-sm btn-outline-danger mb-3']);
    }
    echo html_writer::tag('h5', get_string('my_links', 'local_researchcentral'), ['class' => 'mt-4']);
    echo html_writer::div(get_string('my_links_d', 'local_researchcentral'), 'tt-sub mb-2');
    $linksform->display();
}
echo $OUTPUT->footer();
