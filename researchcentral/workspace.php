<?php
// Researcher workspace: what next, feedback responses, permits, publications, check-ins, viva and corrections.
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\portal;
use local_researchcentral\local\research;
use local_researchcentral\local\risk;
use local_researchcentral\local\meetings;
use local_researchcentral\local\receipts;
use local_researchcentral\local\ethics;
use local_researchcentral\local\docs;
use local_researchcentral\output\ui;

$id = required_param('id', PARAM_INT);
$tab = optional_param('tab', 'next', PARAM_ALPHA);
$edit = optional_param('edit', 0, PARAM_INT);
$do = optional_param('do', '', PARAM_ALPHA);

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$proj = $DB->get_record('local_researchcentral_proj', ['id' => $id], '*', MUST_EXIST);
portal::require_view($proj);
$isstudent = (int)$proj->userid === (int)$USER->id;
$issup = portal::is_project_supervisor($proj);
$iscoord = portal::can_manage_project($proj);
$isstaff = $issup || $iscoord;
$tabs = ['next', 'responses', 'meetings', 'permits', 'ethics', 'pubs', 'checkins', 'viva', 'documents'];
if (!in_array($tab, $tabs, true)) {
    $tab = 'next';
}
$context = context_system::instance();
$url = research::url($id, $tab);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('ws_title', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));

$L = fn(string $k, $a = null) => get_string($k, 'local_researchcentral', $a);
$dfs = get_string('strftimedatefullshort', 'langconfig');
$dts = get_string('strftimedatetimeshort', 'langconfig');
$student = core_user::get_user($proj->userid);
$team = portal::supervisor_ids($proj);
$back = fn(string $msg = '', string $type = \core\output\notification::NOTIFY_SUCCESS) => redirect($url, $msg, null, $type);
$names = function (int $u): string {
    $x = core_user::get_user($u);
    return $x ? fullname($x) : '-';
};
$tell = function (array $to, string $subjkey, string $bodykey, $a, string $provider = 'workflow') use ($proj, $url) {
    foreach (array_unique($to) as $uid) {
        if ((int)$uid !== (int)$GLOBALS['USER']->id) {
            portal::notify((int)$uid, get_string($subjkey, 'local_researchcentral', $a), get_string($bodykey, 'local_researchcentral', $a), $url, $provider);
        }
    }
};
$a0 = (object)['student' => fullname($student), 'title' => format_string($proj->title), 'who' => fullname($USER)];

// ---------- Actions ----------
if ($tab === 'responses' && optional_param('saveresp', 0, PARAM_INT)) {
    require_sesskey();
    if ($isstudent) {
        $n = research::save_responses($proj, $_POST['resp'] ?? [], $_POST['st'] ?? []);
        $back($L('resp_saved', $n));
    }
}
if ($tab === 'responses' && optional_param('saveverify', 0, PARAM_INT)) {
    require_sesskey();
    $n = research::save_verification($proj, $_POST['ver'] ?? [], $_POST['vnote'] ?? []);
    $back($L('ver_saved', $n));
}
if ($do === 'delete' && $edit) {
    require_sesskey();
    $table = ['permits' => 'local_researchcentral_permit', 'pubs' => 'local_researchcentral_pub'][$tab] ?? null;
    if ($table && ($isstudent || $isstaff)) {
        $DB->delete_records($table, ['id' => $edit, 'projid' => $id]);
        $back($L('deleted'));
    }
}
if ($tab === 'checkins' && $do === 'comment' && $isstaff) {
    require_sesskey();
    $cid = required_param('cid', PARAM_INT);
    $txt = trim(optional_param('supcomment', '', PARAM_TEXT));
    $ci = $DB->get_record('local_researchcentral_checkin', ['id' => $cid, 'projid' => $id], '*', MUST_EXIST);
    $DB->update_record('local_researchcentral_checkin', (object)['id' => $ci->id, 'supcomment' => $txt, 'supseen' => 1]);
    if ($txt !== '') {
        $tell([(int)$proj->userid], 'msg_ci_comment_subject', 'msg_ci_comment_body', $a0);
    }
    $back($L('saved'));
}
if ($tab === 'viva') {
    $viva = $DB->get_records('local_researchcentral_viva', ['projid' => $id], 'id DESC', '*', 0, 1);
    $viva = $viva ? reset($viva) : null;
    if ($do === 'corrdone' && $isstudent && $viva && !$viva->corrdone) {
        require_sesskey();
        $DB->update_record('local_researchcentral_viva', (object)['id' => $viva->id, 'corrdone' => 1,
            'corrnote' => trim(optional_param('corrnote', '', PARAM_TEXT))]);
        portal::log($id, 'viva', $L('viva_corr_submitted'));
        $tell(array_merge($team, portal::coordinator_ids($proj)), 'msg_corr_subject', 'msg_corr_body', $a0);
        $back($L('saved'));
    }
    if ($do === 'signoff' && $viva && $viva->corrdone && !$viva->signedby && ($iscoord || (int)$proj->supervisorid === (int)$USER->id)) {
        require_sesskey();
        $DB->update_record('local_researchcentral_viva', (object)['id' => $viva->id, 'signedby' => $USER->id, 'signedtime' => time()]);
        portal::log($id, 'viva', $L('viva_signed'));
        $tell([(int)$proj->userid], 'msg_signoff_subject', 'msg_signoff_body', $a0);
        $back($L('saved'));
    }
    if ($do === 'delpanel' && $edit && $isstaff) {
        require_sesskey();
        $DB->delete_records('local_researchcentral_vivapanel', ['id' => $edit, 'vivaid' => $viva ? $viva->id : 0]);
        $back($L('deleted'));
    }
}

// ---------- Form handling (must happen before any output) ----------
if ($tab === 'permits') {
    $form = new \local_researchcentral\form\permit_form($url, ['id' => $id, 'tab' => 'permits', 'edit' => $edit]);
    if ($form->is_cancelled()) {
        $back();
    }
    if ($d = $form->get_data()) {
        $row = (object)['projid' => $id, 'type' => $d->type, 'reference' => trim($d->reference), 'authority' => trim($d->authority), 'status' => $d->status,
            'issued' => (int)$d->issued, 'expiry' => (int)$d->expiry, 'note' => trim($d->note), 'remind' => 0, 'timemodified' => time()];
        if (!$isstudent && !$isstaff) {
            throw new moodle_exception('nopermissions', 'error');
        }
        if ($d->edit) {
            $DB->get_record('local_researchcentral_permit', ['id' => $d->edit, 'projid' => $id], 'id', MUST_EXIST);
            $row->id = $d->edit;
            $DB->update_record('local_researchcentral_permit', $row);
        } else {
            $row->timecreated = time();
            $DB->insert_record('local_researchcentral_permit', $row);
        }
        $tell($isstudent ? $team : [(int)$proj->userid], 'msg_permit_subject', 'msg_permit_body', $a0);
        $back($L('saved'));
    }
}
if ($tab === 'pubs') {
    $form = new \local_researchcentral\form\pub_form($url, ['id' => $id, 'tab' => 'pubs', 'edit' => $edit]);
    if ($form->is_cancelled()) {
        $back();
    }
    if ($d = $form->get_data()) {
        $row = (object)['projid' => $id, 'type' => $d->type, 'title' => trim($d->title), 'venue' => trim($d->venue), 'status' => $d->status,
            'doi' => trim($d->doi), 'year' => (int)$d->year, 'timemodified' => time()];
        if ($d->edit) {
            $DB->get_record('local_researchcentral_pub', ['id' => $d->edit, 'projid' => $id], 'id', MUST_EXIST);
            $row->id = $d->edit;
            $DB->update_record('local_researchcentral_pub', $row);
        } else {
            $row->timecreated = time();
            $DB->insert_record('local_researchcentral_pub', $row);
        }
        $back($L('saved'));
    }
}
if ($tab === 'checkins') {
    $period = research::period();
    $done = research::checkin_done($id, $period);
    if ($isstudent && !$done) {
        $form = new \local_researchcentral\form\checkin_form($url, ['id' => $id, 'tab' => 'checkins', 'period' => $period]);
        if ($d = $form->get_data()) {
            $DB->insert_record('local_researchcentral_checkin', (object)['projid' => $id, 'userid' => $USER->id, 'period' => $period, 'progress' => (int)$d->progress,
                'blockers' => trim($d->blockers), 'plans' => trim($d->plans), 'needsupport' => (int)$d->needsupport, 'supcomment' => '', 'supseen' => 0, 'timecreated' => time()]);
            $tell($team, $d->needsupport ? 'msg_ci_support_subject' : 'msg_ci_subject', $d->needsupport ? 'msg_ci_support_body' : 'msg_ci_body', $a0);
            $back($L('checkin_thanks'));
        }
    }
}
if ($tab === 'viva') {
    if ($isstaff) {
        $vform = new \local_researchcentral\form\viva_form($url, ['id' => $id, 'tab' => 'viva', 'edit' => $viva ? $viva->id : 0]);
        if ($d = $vform->get_data()) {
            $row = (object)['projid' => $id, 'starttime' => (int)$d->starttime, 'endtime' => (int)$d->starttime + (int)$d->duration * 60,
                'venue' => trim($d->venue), 'joinurl' => trim($d->joinurl), 'remind' => 0];
            if ($viva) {
                $row->id = $viva->id;
                $DB->update_record('local_researchcentral_viva', $row);
            } else {
                $row->status = 'scheduled';
                $row->timecreated = time();
                $DB->insert_record('local_researchcentral_viva', $row);
            }
            portal::log($id, 'viva', userdate($row->starttime, $dts));
            $tell(array_merge([(int)$proj->userid], $team), 'msg_viva_subject', 'msg_viva_body', (object)((array)$a0 + ['when' => userdate($row->starttime, $dts), 'where' => $row->venue ?: $row->joinurl ?: '-']));
            $back($L('saved'));
        }
        if ($viva) {
            $pform = new \local_researchcentral\form\vivapanel_form($url, ['id' => $id, 'tab' => 'viva', 'vivaid' => $viva->id, 'edit' => $edit]);
            if ($d = $pform->get_data()) {
                $row = (object)['vivaid' => $viva->id, 'userid' => 0, 'name' => trim($d->name), 'role' => $d->role, 'recommendation' => $d->recommendation, 'report' => trim($d->report)];
                if ($d->edit) {
                    $DB->get_record('local_researchcentral_vivapanel', ['id' => $d->edit, 'vivaid' => $viva->id], 'id', MUST_EXIST);
                    $row->id = $d->edit;
                    $DB->update_record('local_researchcentral_vivapanel', $row);
                } else {
                    $row->timecreated = time();
                    $DB->insert_record('local_researchcentral_vivapanel', $row);
                }
                $back($L('saved'));
            }
            $oform = new \local_researchcentral\form\vivaoutcome_form($url, ['id' => $id, 'tab' => 'viva']);
            if ($d = $oform->get_data()) {
                $DB->update_record('local_researchcentral_viva', (object)['id' => $viva->id, 'outcome' => $d->outcome, 'corrections' => trim($d->corrections),
                    'corrdeadline' => (int)$d->corrdeadline, 'status' => $d->outcome ? 'held' : $viva->status, 'corrdone' => 0, 'signedby' => 0, 'signedtime' => 0, 'remind' => 0]);
                portal::log($id, 'viva', $d->outcome ? $L('viva_out_' . $d->outcome) : '-');
                if ($d->outcome) {
                    $tell([(int)$proj->userid], 'msg_outcome_subject', 'msg_outcome_body', (object)((array)$a0 + ['outcome' => $L('viva_out_' . $d->outcome)]));
                }
                $back($L('saved'));
            }
        }
    }
}

// ---------- Ethics and documents actions ----------
if ($tab === 'ethics' && $do !== '' && data_submitted()) {
    require_sesskey();
    if ($do === 'apply' && $isstudent) {
        $ok = ethics::submit($proj, optional_param('etitle', '', PARAM_TEXT), optional_param('esummary', '', PARAM_TEXT), optional_param('erisk', 'low', PARAM_ALPHA));
        $back($ok ? $L('eth_sent') : $L('eth_incomplete'), $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
    } else if ($do === 'attach' && $isstudent) {
        $e = $DB->get_record('local_researchcentral_eth', ['id' => optional_param('eid', 0, PARAM_INT), 'projid' => $id]);
        $n = ($e && $e->status === 'submitted') ? ethics::save_uploads('eattach', 'ethics_app', (int)$e->id) : 0;
        $back($n ? $L('eth_files_added') : $L('eth_files_none'), $n ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
    } else if ($do === 'withdraw') {
        ethics::withdraw($proj, optional_param('eid', 0, PARAM_INT));
        $back($L('saved'));
    }
}
if ($tab === 'documents' && $do === 'revoke' && data_submitted()) {
    require_sesskey();
    if ($iscoord) {
        $DB->set_field('local_researchcentral_doc', 'revoked', 1, ['id' => optional_param('did', 0, PARAM_INT), 'projid' => $id]);
    }
    $back($L('saved'));
}

// ---------- Meeting log actions ----------
if ($tab === 'meetings' && $do !== '' && data_submitted()) {
    require_sesskey();
    $mid = optional_param('mid', 0, PARAM_INT);
    if ($do === 'savemeet') {
        $when = strtotime(optional_param('meetdate', '', PARAM_RAW_TRIMMED)) ?: time();
        meetings::save($proj, $mid, $when, optional_param('mode', 'inperson', PARAM_ALPHA), optional_param('agenda', '', PARAM_TEXT),
            $isstaff ? optional_param('minutes', '', PARAM_TEXT) : null, optional_param('mstatus', 'planned', PARAM_ALPHA));
        $back($L('saved'));
    } else if ($do === 'delmeet') {
        meetings::delete($proj, $mid);
        $back($L('deleted'));
    } else if ($do === 'addact') {
        $due = optional_param('due', '', PARAM_RAW_TRIMMED);
        meetings::add_action($proj, $mid, optional_param('owner', 0, PARAM_INT), optional_param('descr', '', PARAM_TEXT), $due !== '' ? (strtotime($due) ?: 0) : 0);
        $back($L('saved'));
    } else if ($do === 'actdone' || $do === 'actopen') {
        meetings::set_action($proj, optional_param('aid', 0, PARAM_INT), $do === 'actdone');
        $back($L('saved'));
    } else if ($do === 'delact') {
        meetings::delete_action($proj, optional_param('aid', 0, PARAM_INT));
        $back($L('deleted'));
    }
}

echo $OUTPUT->header();
echo \local_researchcentral\output\ui::project_crumbs($proj, [[$L('ws_title'), null]]);
echo $OUTPUT->heading($L('ws_title') . ': ' . s(fullname($student)), 3);
echo html_writer::div(format_string($proj->title), 'tt-sub mb-2');
$counts = research::response_counts($proj);
$labels = ['next' => $L('ws_next'),
    'responses' => $L('ws_responses') . ($counts[0] && $isstudent ? ' (' . $counts[0] . ')' : ($counts[1] && $isstaff ? ' (' . $counts[1] . ')' : '')),
    'meetings' => $L('ws_meetings'), 'permits' => $L('ws_permits'), 'ethics' => $L('ws_ethics'), 'pubs' => $L('ws_pubs'), 'checkins' => $L('ws_checkins'),
    'viva' => $L('ws_viva'), 'documents' => $L('ws_documents')];
echo ui::tabs($labels, $tab, new moodle_url('/local/researchcentral/workspace.php', ['id' => $id]), 'tab');

// ---------- Tab: what next ----------
if ($tab === 'next') {
    $actions = research::next_actions($proj, (int)$USER->id);
    echo html_writer::tag('h4', $L('na_title'), ['class' => 'mt-3']);
    if (!$actions) {
        echo html_writer::div($L('na_none'), 'tt-banner');
    }
    foreach ($actions as $x) {
        echo html_writer::div(html_writer::link($x['url'], $L($x['key'], $x['a']), ['class' => 'tt-link']), 'tt-next tt-next-' . $x['level']);
    }
    if ($isstaff) {
        $proj->ms = portal::milestones([$id])[$id] ?? [];
        $r = risk::scores([$proj])[$id];
        echo html_writer::tag('h4', $L('risk_title'), ['class' => 'mt-4']);
        $chips = array_map(fn($s) => html_writer::span($L('risk_sig_' . $s), 'tt-flag tt-flag-no'), $r['signals']);
        echo html_writer::div(html_writer::span($L('risk_' . $r['level']), 'tt-risk tt-risk-' . $r['level']) . ' ' .
            ($chips ? implode(' ', $chips) : html_writer::span($L('risk_nosignals'), 'tt-sub')), 'tt-flags');
        echo html_writer::div($L('risk_note'), 'tt-sub mt-1');
    }
    echo html_writer::tag('h4', $L('ws_tools'), ['class' => 'mt-4']);
    $btn = fn(moodle_url $u, string $t) => html_writer::link($u, $t, ['class' => 'btn btn-outline-primary mr-2 mb-2']);
    echo $btn(new moodle_url('/local/researchcentral/seminars.php'), $L('seminars')) .
        $btn(new moodle_url('/local/researchcentral/appointments.php'), $L('appointments')) .
        ($isstaff ? $btn(new moodle_url('/local/researchcentral/examiners.php', ['id' => $id]), $L('exam_manage')) : '');
    $vers = $DB->get_records_sql("SELECT m.id AS pmsid, s.name FROM {local_researchcentral_pms} m JOIN {local_researchcentral_stage} s ON s.id = m.stageid
        WHERE m.projid = ? AND EXISTS (SELECT 1 FROM {local_researchcentral_ver} v WHERE v.pmsid = m.id GROUP BY v.pmsid HAVING COUNT(v.id) > 1)", [$id]);
    foreach ($vers as $v) {
        echo $btn(new moodle_url('/local/researchcentral/compare.php', ['pms' => $v->pmsid]), $L('compare_versions') . ': ' . format_string($v->name));
    }
}

// ---------- Tab: supervision meetings ----------
if ($tab === 'meetings') {
    $mform = function (?stdClass $mt) use ($url, $L, $isstaff): string {
        $in = fn(string $n, string $v) => html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $n, 'value' => $v]);
        $dt = html_writer::empty_tag('input', ['type' => 'datetime-local', 'name' => 'meetdate', 'class' => 'form-control w-auto d-inline-block mr-2', 'required' => 'required',
            'value' => date('Y-m-d\TH:i', $mt ? (int)$mt->meetdate : time()), 'aria-label' => $L('meet_date')]);
        $h = $in('sesskey', sesskey()) . $in('do', 'savemeet') . $in('mid', (string)($mt->id ?? 0)) . html_writer::div($dt .
            html_writer::select(meetings::modes(), 'mode', $mt->mode ?? 'inperson', false, ['class' => 'custom-select w-auto d-inline-block mr-2', 'aria-label' => $L('meet_mode')]) .
            ($isstaff ? html_writer::select([meetings::PLANNED => $L('meet_planned'), meetings::HELD => $L('meet_held')], 'mstatus', $mt->status ?? meetings::PLANNED, false,
                ['class' => 'custom-select w-auto d-inline-block', 'aria-label' => $L('status')]) : ''), 'mb-2') .
            html_writer::tag('label', $L('meet_agenda'), ['class' => 'tt-label mb-0']) .
            html_writer::tag('textarea', s($mt->agenda ?? ''), ['name' => 'agenda', 'rows' => 3, 'class' => 'form-control mb-2']);
        if ($isstaff) {
            $h .= html_writer::tag('label', $L('meet_minutes'), ['class' => 'tt-label mb-0']) .
                html_writer::tag('textarea', s($mt->minutes ?? ''), ['name' => 'minutes', 'rows' => 6, 'class' => 'form-control mb-2']);
        }
        return html_writer::tag('form', $h . html_writer::tag('button', $L('save'), ['type' => 'submit', 'class' => 'btn btn-primary']), ['method' => 'post', 'action' => $url->out(false)]);
    };
    $all = meetings::for_project($id);
    $acts = meetings::actions_for($id);
    $seen = receipts::for_items('meet', array_map(fn($x) => (int)$x->id, $all));
    echo html_writer::div($L('meet_intro'), 'tt-sub mb-2 mt-2');
    echo html_writer::tag('details', html_writer::tag('summary', '+ ' . $L('meet_new'), ['class' => 'btn btn-primary btn-sm']) .
        html_writer::div($mform(null), 'tt-card mt-2'), ['class' => 'mb-3']);
    if (!$all) {
        echo html_writer::div($L('meet_none'), 'tt-empty');
    }
    $btn = fn(string $do, array $x, string $label, string $cls) => html_writer::tag('form', html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => $do]) .
        implode('', array_map(fn($k, $v) => html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]), array_keys($x), $x)) .
        html_writer::tag('button', $label, ['type' => 'submit', 'class' => 'btn btn-sm ' . $cls]), ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline']);
    foreach ($all as $mt) {
        $head = html_writer::span(userdate($mt->meetdate, $dts), 'tt-strong') . ' · ' . s(meetings::modes()[$mt->mode] ?? '') . ' ' .
            html_writer::span($L('meet_' . $mt->status), 'tt-chk ' . ($mt->status === 'held' ? 'tt-chk-pass' : 'tt-chk-warn'));
        $body = '';
        if (trim($mt->agenda) !== '') {
            $body .= html_writer::div($L('meet_agenda'), 'tt-label') . html_writer::div(nl2br(s($mt->agenda)), 'mb-2');
        }
        if (trim($mt->minutes) !== '') {
            $body .= html_writer::div($L('meet_minutes'), 'tt-label') . html_writer::div(nl2br(s($mt->minutes)), 'mb-2');
            if ($isstudent) {
                receipts::mark('meet', (int)$mt->id, $id, (int)$USER->id);
            } else if ($isstaff) {
                $sn = isset($seen[(int)$mt->id][(int)$proj->userid]);
                $body .= html_writer::div($sn ? '✓✓ ' . $L('meet_seen', userdate($seen[(int)$mt->id][(int)$proj->userid]->firsttime, $dts)) : '✓ ' . $L('meet_notseen'), 'tt-sub mb-2');
            }
        }
        $body .= html_writer::div($L('meet_actions'), 'tt-label');
        foreach ($acts[(int)$mt->id] ?? [] as $ac) {
            $done = $ac->status === 'done';
            $late = !$done && $ac->duedate && $ac->duedate < time();
            $mine = (int)$ac->ownerid === (int)$USER->id;
            $line = html_writer::span(($done ? '☑ ' : '☐ ') . s($ac->descr), $done ? 'tt-sub' : '') . ' ' . html_writer::span('(' . s($names((int)$ac->ownerid)) . ($ac->duedate ? ', ' . userdate($ac->duedate, $dfs) : '') . ')', 'tt-sub') .
                ($late ? ' ' . html_writer::span($L('overdue'), 'tt-chk tt-chk-no') : '') . ' ' .
                (($mine || $isstaff) ? $btn($done ? 'actopen' : 'actdone', ['aid' => $ac->id], $done ? $L('meet_reopen') : $L('meet_markdone'), 'btn-outline-secondary') : '') .
                ($isstaff ? ' ' . $btn('delact', ['aid' => $ac->id], $L('delete'), 'btn-link text-danger') : '');
            $body .= html_writer::div($line, 'mb-1');
        }
        if (empty($acts[(int)$mt->id])) {
            $body .= html_writer::div($L('meet_noactions'), 'tt-sub');
        }
        if ($isstaff) {
            $owners = [(int)$proj->userid => fullname($student)];
            foreach ($team as $t) {
                $owners[$t] = $names($t);
            }
            $body .= html_writer::tag('form', html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'addact']) . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'mid', 'value' => $mt->id]) .
                html_writer::empty_tag('input', ['type' => 'text', 'name' => 'descr', 'class' => 'form-control d-inline-block w-auto mr-1', 'size' => 40, 'maxlength' => 250, 'required' => 'required', 'placeholder' => $L('meet_action_ph'), 'aria-label' => $L('meet_action_ph')]) .
                html_writer::select($owners, 'owner', (int)$proj->userid, false, ['class' => 'custom-select w-auto d-inline-block mr-1', 'aria-label' => $L('meet_owner')]) .
                html_writer::empty_tag('input', ['type' => 'date', 'name' => 'due', 'class' => 'form-control d-inline-block w-auto mr-1', 'aria-label' => $L('due')]) .
                html_writer::tag('button', $L('meet_addaction'), ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-primary']), ['method' => 'post', 'action' => $url->out(false), 'class' => 'mt-2']);
        }
        if ($isstaff || (int)$mt->createdby === (int)$USER->id) {
            $body .= html_writer::tag('details', html_writer::tag('summary', $L('edit'), ['class' => 'btn btn-sm btn-outline-secondary mt-2']) . html_writer::div($mform($mt), 'mt-2'));
        }
        if ($isstaff) {
            $body .= ' ' . $btn('delmeet', ['mid' => $mt->id], $L('delete'), 'btn-link text-danger');
        }
        echo html_writer::div(html_writer::div($head, 'mb-2') . $body, 'tt-card mb-3');
    }
}

// ---------- Tab: ethics ----------
if ($tab === 'ethics') {
    echo html_writer::div($L('eth_intro'), 'tt-sub mb-2 mt-2');
    $tpl = ethics::file_links('ethics_template', 0);
    if ($tpl !== '') {
        echo html_writer::div(html_writer::div($L('eth_templates'), 'tt-label') . $tpl, 'tt-card mb-3');
    }
    $apps = ethics::for_project($id);
    foreach ($apps as $ap) {
        $chip = html_writer::span($L('eth_st_' . $ap->status), 'tt-chk ' . ($ap->status === 'submitted' ? 'tt-chk-warn' : ($ap->status === 'rejected' ? 'tt-chk-no' : 'tt-chk-pass')));
        $extra = '';
        if ($isstudent && $ap->status === 'submitted') {
            $extra .= html_writer::tag('form', html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'attach']) . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'eid', 'value' => $ap->id]) .
                html_writer::empty_tag('input', ['type' => 'file', 'name' => 'eattach[]', 'accept' => '.docx', 'multiple' => 'multiple', 'required' => 'required', 'class' => 'mr-2', 'aria-label' => $L('eth_attach')]) .
                html_writer::tag('button', $L('eth_attach'), ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-primary']),
                ['method' => 'post', 'enctype' => 'multipart/form-data', 'action' => $url->out(false), 'class' => 'mb-2']);
            $extra .= html_writer::tag('form', html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'withdraw']) . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'eid', 'value' => $ap->id]) .
                html_writer::tag('button', $L('eth_withdraw'), ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-secondary']), ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline mr-2']);
        }
        if (ethics::can_decide($proj)) {
            $extra .= html_writer::link(new moodle_url('/local/researchcentral/ethics.php', ['id' => $ap->id]), $ap->status === 'submitted' ? $L('eth_review') : $L('open'), ['class' => 'btn btn-sm btn-outline-primary']);
        }
        echo html_writer::div(html_writer::div(html_writer::span(s($ap->ref), 'tt-label') . ' ' . $chip, 'mb-1') . html_writer::div(s($ap->title), 'tt-strong') .
            html_writer::div($L('eth_risk') . ': ' . $L('eth_risk_' . $ap->risk) . ' · ' . userdate($ap->timesubmitted, $dts), 'tt-sub') .
            (($af = ethics::file_links('ethics_app', (int)$ap->id)) !== '' ? html_writer::div(html_writer::span($L('eth_attachments'), 'tt-label') . '<br>' . $af, 'mt-1') : '') .
            (trim((string)$ap->decisionnote) !== '' ? html_writer::div(nl2br(s($ap->decisionnote)), 'mt-1') : '') . html_writer::div($extra, 'mt-2'), 'tt-card mb-3');
    }
    if ($isstudent) {
        echo html_writer::tag('h5', $L('eth_apply'), ['class' => 'mt-3']);
        echo html_writer::tag('form', html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'apply']) .
            html_writer::empty_tag('input', ['type' => 'text', 'name' => 'etitle', 'maxlength' => 250, 'required' => 'required', 'class' => 'form-control mb-2', 'value' => format_string($proj->title), 'aria-label' => $L('eth_app_title')]) .
            html_writer::tag('textarea', '', ['name' => 'esummary', 'rows' => 7, 'required' => 'required', 'class' => 'form-control mb-2', 'placeholder' => $L('eth_summary_ph'), 'aria-label' => $L('eth_summary_ph')]) .
            html_writer::select(['low' => $L('eth_risk_low'), 'medium' => $L('eth_risk_medium'), 'high' => $L('eth_risk_high')], 'erisk', 'low', false, ['class' => 'custom-select w-auto d-inline-block mr-2', 'aria-label' => $L('eth_risk')]) .
            html_writer::div(html_writer::tag('label', $L('eth_attach_label'), ['class' => 'd-block']) .
                html_writer::empty_tag('input', ['type' => 'file', 'name' => 'eattach[]', 'accept' => '.docx', 'multiple' => 'multiple']) .
                html_writer::div($L('eth_attach_hint', implode(', ', ethics::EXTS)), 'tt-sub'), 'mb-2') .
            html_writer::tag('button', $L('eth_submit'), ['type' => 'submit', 'class' => 'btn btn-primary']), ['method' => 'post', 'enctype' => 'multipart/form-data', 'action' => $url->out(false), 'class' => 'tt-card']);
    } else if (!$apps) {
        echo html_writer::div($L('eth_none_project'), 'tt-empty');
    }
}

// ---------- Tab: documents ----------
if ($tab === 'documents') {
    echo html_writer::div($L('doc_intro'), 'tt-sub mb-2 mt-2');
    $allowed = docs::allowed($proj);
    $dlink = fn(string $kind, array $x = []) => new moodle_url('/local/researchcentral/document.php', ['id' => $id, 'kind' => $kind, 'sesskey' => sesskey()] + $x);
    $btns = '';
    foreach ($allowed as $k) {
        if ($k === 'supcert') {
            foreach ($team as $sid) {
                if ($iscoord || $sid === (int)$USER->id) {
                    $btns .= html_writer::link($dlink($k, ['who' => $sid]), '⬇ ' . $L('doc_supcert') . ': ' . s($names($sid)), ['class' => 'btn btn-outline-primary mr-2 mb-2']);
                }
            }
        } else {
            $btns .= html_writer::link($dlink($k), '⬇ ' . $L('doc_' . $k), ['class' => 'btn btn-outline-primary mr-2 mb-2']);
        }
    }
    echo $btns !== '' ? html_writer::div($btns, 'mb-3') : html_writer::div($L('doc_none'), 'tt-empty');
    $reg = $DB->get_records('local_researchcentral_doc', ['projid' => $id], 'timecreated DESC, id DESC', '*', 0, 100);
    if ($reg && $isstaff) {
        echo html_writer::tag('h5', $L('doc_register'));
        $t = new html_table();
        $t->attributes['class'] = 'tt-table';
        $t->head = [$L('doc_code'), $L('type'), $L('doc_date'), $L('doc_issuedby'), ''];
        foreach ($reg as $d) {
            $rv = '';
            if ($iscoord && !$d->revoked) {
                $rv = html_writer::tag('form', html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'revoke']) . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'did', 'value' => $d->id]) .
                    html_writer::tag('button', $L('doc_revoke'), ['type' => 'submit', 'class' => 'btn btn-sm btn-link text-danger']), ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline']);
            }
            $t->data[] = [html_writer::link(\local_researchcentral\local\docs::verify_url($d->code), s($d->code)), $L('doc_' . $d->kind) . ($d->revoked ? ' (' . $L('doc_revoked') . ')' : ''),
                userdate($d->timecreated, $dts), s($names((int)$d->issuedby)), $rv];
        }
        echo html_writer::div(html_writer::table($t), 'tt-tablewrap');
    }
}

// ---------- Tab: feedback responses ----------
if ($tab === 'responses') {
    $groups = research::response_groups($proj);
    echo html_writer::div($L('resp_intro'), 'tt-sub mb-3 mt-2');
    if (!$groups) {
        echo html_writer::div($L('resp_none'), 'tt-empty');
    }
    $edit_resp = $isstudent;
    $ver_mode = $isstaff;
    if ($groups) {
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $isstudent ? 'saveresp' : 'saveverify', 'value' => 1]);
    }
    foreach ($groups as $g) {
        echo html_writer::tag('h5', format_string($g['pms']->stagename) . ' · ' . $L('round_n', $g['round']), ['class' => 'mt-4']);
        foreach ($g['reviews'] as $rv) {
            $r = $rv['review'];
            echo html_writer::div(html_writer::span($names((int)$r->userid), 'tt-strong') . ' ' .
                html_writer::span($L((int)$r->userid === (int)$proj->supervisorid ? 'lead_supervisor' : 'co_supervisor'), 'tt-sub'), 'mt-2');
            foreach ($rv['items'] as $i => $text) {
                $no = $i + 1;
                $x = $rv['resp'][$no] ?? null;
                $st = $x ? $x->status : 'open';
                $cls = !$x || $st === 'open' ? 'tt-fb-open' : ((int)$x->verified === 1 ? 'tt-fb-ok' : ((int)$x->verified === 2 ? 'tt-fb-more' : 'tt-fb-wait'));
                $body = html_writer::div(html_writer::span('#' . $no, 'tt-label') . ' ' . nl2br(s($text)), 'tt-fb-point');
                if ($edit_resp) {
                    $opts = '';
                    foreach (research::RESP_STATUS as $s) {
                        $opts .= html_writer::tag('option', $L('resp_st_' . $s), ['value' => $s] + ($st === $s ? ['selected' => 'selected'] : []));
                    }
                    $body .= html_writer::tag('textarea', s($x->response ?? ''), ['name' => "resp[{$r->id}][$no]", 'rows' => 3, 'class' => 'form-control mt-1',
                        'placeholder' => $L('resp_placeholder')]) .
                        html_writer::div(html_writer::tag('select', $opts, ['name' => "st[{$r->id}][$no]", 'class' => 'custom-select d-inline-block w-auto']), 'mt-1');
                } else if ($x) {
                    $body .= html_writer::div(html_writer::span($L('resp_st_' . $x->status), 'tt-chk tt-chk-warn') . ' ' . nl2br(s($x->response)), 'tt-fb-reply');
                } else {
                    $body .= html_writer::div($L('resp_st_open'), 'tt-sub');
                }
                if ($x && $x->status !== 'open') {
                    if ($ver_mode) {
                        $sel = html_writer::tag('select', html_writer::tag('option', '-', ['value' => 0]) .
                            html_writer::tag('option', $L('ver_ok'), ['value' => 1] + ((int)$x->verified === 1 ? ['selected' => 'selected'] : [])) .
                            html_writer::tag('option', $L('ver_more'), ['value' => 2] + ((int)$x->verified === 2 ? ['selected' => 'selected'] : [])),
                            ['name' => "ver[{$r->id}][$no]", 'class' => 'custom-select d-inline-block w-auto']);
                        $body .= html_writer::div($sel . ' ' . html_writer::empty_tag('input', ['type' => 'text', 'name' => "vnote[{$r->id}][$no]", 'value' => $x->vernote,
                            'class' => 'form-control d-inline-block w-50', 'placeholder' => $L('ver_note')]), 'mt-1');
                    } else if ((int)$x->verified) {
                        $body .= html_writer::div(html_writer::span($L((int)$x->verified === 1 ? 'ver_ok' : 'ver_more'),
                            'tt-chk ' . ((int)$x->verified === 1 ? 'tt-chk-pass' : 'tt-chk-fail')) . ' ' . s($x->vernote), 'mt-1');
                    } else {
                        $body .= html_writer::div($L('ver_waiting'), 'tt-sub');
                    }
                }
                echo html_writer::div($body, 'tt-fb ' . $cls);
            }
        }
    }
    if ($groups && ($isstudent || $isstaff)) {
        echo html_writer::tag('button', $L($isstudent ? 'resp_save' : 'ver_save'), ['type' => 'submit', 'class' => 'btn btn-primary mt-3']);
        echo html_writer::end_tag('form');
    }
}

// ---------- Tab: permits ----------
if ($tab === 'permits') {
    $rows = $DB->get_records('local_researchcentral_permit', ['projid' => $id], 'expiry');
    $t = new html_table();
    $t->attributes['class'] = 'tt-table';
    $t->head = [$L('permit_type'), $L('permit_reference'), $L('permit_authority'), $L('permit_status'), $L('permit_expiry'), ''];
    foreach ($rows as $p) {
        $st = research::permit_state($p);
        $cls = $st === 'approved' ? 'tt-chk-pass' : ($st === 'expired' || $st === 'rejected' ? 'tt-chk-fail' : 'tt-chk-warn');
        $soon = $st === 'approved' && $p->expiry && $p->expiry - time() < 30 * DAYSECS;
        $actions = html_writer::link(research::url($id, 'permits', ['edit' => $p->id]), $L('edit'), ['class' => 'mr-2']) .
            html_writer::link(research::url($id, 'permits', ['edit' => $p->id, 'do' => 'delete', 'sesskey' => sesskey()]), $L('delete'));
        $t->data[] = [$L('permit_type_' . $p->type), s($p->reference), s($p->authority),
            html_writer::span($L('permit_status_' . $st), 'tt-chk ' . $cls) . ($soon ? ' ' . html_writer::span($L('permit_expiring'), 'tt-chk tt-chk-warn') : ''),
            $p->expiry ? userdate($p->expiry, $dfs) : '-', $actions];
        if ($edit === (int)$p->id && !$form->is_submitted()) {
            $form->set_data((object)['type' => $p->type, 'reference' => $p->reference, 'authority' => $p->authority, 'status' => $p->status === 'expired' ? 'approved' : $p->status,
                'issued' => $p->issued, 'expiry' => $p->expiry, 'note' => $p->note, 'edit' => $p->id]);
        }
    }
    echo html_writer::div($L('permit_intro'), 'tt-sub mb-2 mt-2');
    echo $rows ? html_writer::div(html_writer::table($t), 'tt-tablewrap') : html_writer::div($L('permit_none'), 'tt-empty');
    echo html_writer::tag('h5', $L($edit ? 'permit_edit' : 'permit_add'), ['class' => 'mt-4']);
    $form->display();
}

// ---------- Tab: publications ----------
if ($tab === 'pubs') {
    $rows = $DB->get_records('local_researchcentral_pub', ['projid' => $id], 'year DESC, id DESC');
    $t = new html_table();
    $t->attributes['class'] = 'tt-table';
    $t->head = [$L('pub_title'), $L('pub_type'), $L('pub_venue'), $L('pub_status'), $L('pub_year'), ''];
    foreach ($rows as $p) {
        $doi = trim($p->doi);
        $doilink = $doi !== '' ? html_writer::link('https://doi.org/' . s(preg_replace('~^https?://(dx\.)?doi\.org/~i', '', $doi)), 'DOI', ['target' => '_blank', 'rel' => 'noopener']) : '';
        $t->data[] = [s($p->title) . ($doilink ? html_writer::div($doilink, 'tt-sub') : ''), $L('pub_type_' . $p->type), s($p->venue),
            html_writer::span($L('pub_status_' . $p->status), 'tt-chk ' . ($p->status === 'published' || $p->status === 'accepted' ? 'tt-chk-pass' : 'tt-chk-warn')),
            $p->year ?: '-', html_writer::link(research::url($id, 'pubs', ['edit' => $p->id]), $L('edit'), ['class' => 'mr-2']) .
            html_writer::link(research::url($id, 'pubs', ['edit' => $p->id, 'do' => 'delete', 'sesskey' => sesskey()]), $L('delete'))];
        if ($edit === (int)$p->id && !$form->is_submitted()) {
            $form->set_data((object)['title' => $p->title, 'type' => $p->type, 'venue' => $p->venue, 'status' => $p->status, 'year' => $p->year, 'doi' => $p->doi, 'edit' => $p->id]);
        }
    }
    echo html_writer::div($L('pub_intro'), 'tt-sub mb-2 mt-2');
    echo $rows ? html_writer::div(html_writer::table($t), 'tt-tablewrap') : html_writer::div($L('pub_none'), 'tt-empty');
    echo html_writer::tag('h5', $L($edit ? 'pub_edit' : 'pub_add'), ['class' => 'mt-4']);
    $form->display();
}

// ---------- Tab: check-ins ----------
if ($tab === 'checkins') {
    $period = research::period();
    $done = research::checkin_done($id, $period);
    $rows = $DB->get_records('local_researchcentral_checkin', ['projid' => $id], 'period DESC');
    echo html_writer::div($L('checkin_intro'), 'tt-sub mb-2 mt-2');
    if ($isstudent) {
        if ($done) {
            echo html_writer::div($L('checkin_done', $period), 'tt-banner');
        } else {
            echo html_writer::tag('h5', $L('checkin_new'), ['class' => 'mt-3']);
            $form->display();
        }
    }
    if (!$rows) {
        echo html_writer::div($L('checkin_none'), 'tt-empty');
    }
    foreach ($rows as $c) {
        $body = html_writer::div(html_writer::span($c->period, 'tt-strong') . ' · ' . html_writer::span($L('checkin_p' . $c->progress), 'tt-chk ' . ($c->progress >= 4 ? 'tt-chk-pass' : ($c->progress == 3 ? 'tt-chk-warn' : 'tt-chk-fail'))) .
            ($c->needsupport ? ' ' . html_writer::span('⚑ ' . $L('checkin_needsupport'), 'tt-chk tt-chk-fail') : '') .
            ' ' . html_writer::span(userdate($c->timecreated, $dts), 'tt-sub'));
        if (trim($c->plans) !== '') {
            $body .= html_writer::div(html_writer::span($L('checkin_plans') . ': ', 'tt-label') . nl2br(s($c->plans)), 'mt-1');
        }
        if (trim($c->blockers) !== '') {
            $body .= html_writer::div(html_writer::span($L('checkin_blockers') . ': ', 'tt-label') . nl2br(s($c->blockers)), 'mt-1');
        }
        if ($isstaff) {
            $body .= html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'class' => 'mt-2']) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'comment']) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cid', 'value' => $c->id]) .
                html_writer::tag('textarea', s($c->supcomment), ['name' => 'supcomment', 'rows' => 2, 'class' => 'form-control', 'placeholder' => $L('checkin_reply')]) .
                html_writer::tag('button', $L('checkin_reply_send'), ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-primary mt-1']) .
                html_writer::end_tag('form');
            if (!$c->supseen) {
                $DB->set_field('local_researchcentral_checkin', 'supseen', 1, ['id' => $c->id]);
            }
        } else if (trim($c->supcomment) !== '') {
            $body .= html_writer::div(html_writer::span($L('checkin_supervisor_said') . ': ', 'tt-label') . nl2br(s($c->supcomment)), 'tt-feedback mt-1');
        } else if ($c->supseen) {
            $body .= html_writer::div('✓ ' . $L('checkin_seen'), 'tt-flag tt-flag-yes mt-1');
        }
        echo html_writer::div($body, 'tt-card mb-2');
    }
}

// ---------- Tab: viva and corrections ----------
if ($tab === 'viva') {
    $viva = $DB->get_records('local_researchcentral_viva', ['projid' => $id], 'id DESC', '*', 0, 1);
    $viva = $viva ? reset($viva) : null;
    echo html_writer::div($L('viva_intro'), 'tt-sub mb-2 mt-2');
    if ($viva) {
        $when = userdate($viva->starttime, $dts) . ' – ' . userdate($viva->endtime, '%H:%M');
        $link = trim($viva->joinurl) !== '' && preg_match('~^https?://~i', $viva->joinurl) ? ' ' . html_writer::link($viva->joinurl, $L('appt_join'), ['class' => 'btn btn-sm btn-primary', 'target' => '_blank', 'rel' => 'noopener']) : '';
        echo html_writer::div(html_writer::div($L('viva_scheduled'), 'tt-label') . html_writer::div($when . ($viva->venue ? ' · ' . s($viva->venue) : '') . $link, 'tt-strong'), 'tt-card mb-3');
        if ($isstaff) {
            echo html_writer::link(new moodle_url('/local/researchcentral/examiners.php', ['id' => $id]), $L('exam_manage'), ['class' => 'btn btn-outline-primary btn-sm mb-3']);
            $panel = $DB->get_records('local_researchcentral_vivapanel', ['vivaid' => $viva->id], 'id');
            echo html_writer::tag('h5', $L('viva_panel'));
            if ($panel) {
                $t = new html_table();
                $t->attributes['class'] = 'tt-table';
                $t->head = [$L('viva_examiner'), $L('viva_role'), $L('viva_recommendation'), $L('viva_report'), ''];
                foreach ($panel as $p) {
                    $t->data[] = [s($p->name), $L('viva_role_' . $p->role), $p->recommendation ? $L('viva_out_' . $p->recommendation) : '-', nl2br(s($p->report)),
                        html_writer::link(research::url($id, 'viva', ['edit' => $p->id]), $L('edit'), ['class' => 'mr-2']) .
                        html_writer::link(research::url($id, 'viva', ['edit' => $p->id, 'do' => 'delpanel', 'sesskey' => sesskey()]), $L('delete'))];
                    if ($edit === (int)$p->id && !$pform->is_submitted()) {
                        $pform->set_data((object)['name' => $p->name, 'role' => $p->role, 'recommendation' => $p->recommendation, 'report' => $p->report, 'edit' => $p->id]);
                    }
                }
                echo html_writer::div(html_writer::table($t), 'tt-tablewrap');
            } else {
                echo html_writer::div($L('viva_nopanel'), 'tt-empty');
            }
            echo html_writer::tag('h5', $L('viva_addexaminer'), ['class' => 'mt-3']);
            $pform->display();
            if (!$oform->is_submitted()) {
                $oform->set_data((object)['outcome' => $viva->outcome, 'corrections' => $viva->corrections, 'corrdeadline' => $viva->corrdeadline]);
            }
            echo html_writer::tag('h5', $L('viva_outcome'), ['class' => 'mt-4']);
            $oform->display();
        }
        if ($viva->outcome) {
            echo html_writer::tag('h5', $L('viva_result'), ['class' => 'mt-4']);
            echo html_writer::div(html_writer::span($L('viva_out_' . $viva->outcome), 'tt-chk ' . ($viva->outcome === 'pass' ? 'tt-chk-pass' : 'tt-chk-warn')), 'mb-2');
            if (trim($viva->corrections) !== '') {
                echo html_writer::div(html_writer::div($L('viva_corrections'), 'tt-label') . nl2br(s($viva->corrections)) .
                    ($viva->corrdeadline ? html_writer::div($L('viva_corrdeadline') . ': ' . userdate($viva->corrdeadline, $dfs), 'tt-sub mt-1') : ''), 'tt-card mb-2');
                if ($viva->corrdone) {
                    echo html_writer::div('✓ ' . $L('viva_corr_submitted') . ($viva->corrnote ? ': ' . s($viva->corrnote) : ''), 'tt-flag tt-flag-yes');
                } else if ($isstudent) {
                    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]) .
                        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'corrdone']) .
                        html_writer::tag('textarea', '', ['name' => 'corrnote', 'rows' => 2, 'class' => 'form-control', 'placeholder' => $L('viva_corrnote')]) .
                        html_writer::tag('button', $L('viva_corr_mark'), ['type' => 'submit', 'class' => 'btn btn-primary mt-2']) . html_writer::end_tag('form');
                }
            }
            if ($viva->signedby) {
                echo html_writer::div('✓ ' . $L('viva_signed') . ': ' . s($names((int)$viva->signedby)) . ' · ' . userdate($viva->signedtime, $dfs), 'tt-flag tt-flag-yes mt-2');
            } else if (($viva->corrdone || trim($viva->corrections) === '') && ($iscoord || (int)$proj->supervisorid === (int)$USER->id)) {
                echo html_writer::link(research::url($id, 'viva', ['do' => 'signoff', 'sesskey' => sesskey()]), $L('viva_signoff'), ['class' => 'btn btn-success mt-2']);
            }
        }
    } else if (!$isstaff) {
        echo html_writer::div($L('viva_notscheduled'), 'tt-empty');
    } else {
        echo html_writer::tag('h5', $L('viva_schedule'), ['class' => 'mt-3']);
        $vform->display();
    }
    if ($viva && $isstaff) {
        echo html_writer::tag('h5', $L('viva_reschedule'), ['class' => 'mt-4']);
        $vform->display();
    }
}
echo $OUTPUT->footer();
