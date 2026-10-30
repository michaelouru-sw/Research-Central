<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_researchcentral\local\cal;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$a = $DB->get_record('local_researchcentral_appt', ['id' => required_param('id', PARAM_INT)], '*', MUST_EXIST);
if ((int)$a->studentid !== (int)$USER->id && (int)$a->supervisorid !== (int)$USER->id) {
    throw new moodle_exception('nopermissions', 'error', '', 'ical');
}
send_file(cal::ics($a), 'supervision-meeting-' . $a->id . '.ics', 0, 0, true, true, 'text/calendar');
