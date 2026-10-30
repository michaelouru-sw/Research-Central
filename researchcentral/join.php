<?php
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\appt;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$a = $DB->get_record('local_researchcentral_appt', ['id' => required_param('id', PARAM_INT)], '*', MUST_EXIST);
if ((int)$a->studentid !== (int)$USER->id && (int)$a->supervisorid !== (int)$USER->id) {
    throw new moodle_exception('nopermissions', 'error', '', 'join');
}
$link = clean_param(trim((string)$a->joinurl), PARAM_URL);
if ($link === '' || !preg_match('~^https?://~i', $link) || $a->status === 'cancelled') {
    redirect(new moodle_url('/local/researchcentral/appointments.php'), get_string('appt_nolink', 'local_researchcentral'), null,
        \core\output\notification::NOTIFY_WARNING);
}
appt::record_join($a, (int)$USER->id);   // Evidence that this person opened the meeting.
redirect($link);
