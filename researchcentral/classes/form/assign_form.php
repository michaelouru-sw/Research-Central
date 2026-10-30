<?php
namespace local_researchcentral\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_researchcentral\local\tracker;

class assign_form extends \moodleform {
    protected function definition(): void {
        global $DB;
        $mform = $this->_form;
        $courseid = $this->_customdata['courseid'];
        $context = \context_course::instance($courseid);

        $mform->addElement('hidden', 'id', $courseid);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'assign');
        $mform->setType('action', PARAM_ALPHA);

        $existing = $GLOBALS['DB']->get_fieldset_select('local_researchcentral_thesis', 'userid', 'courseid = ?', [$courseid]);
        $students = [];
        foreach (get_enrolled_users($context, 'local/researchcentral:viewown', 0, 'u.*', 'u.lastname, u.firstname') as $u) {
            if (!in_array($u->id, $existing)) {
                $students[$u->id] = fullname($u);
            }
        }
        $supervisors = [];
        foreach (get_enrolled_users($context, 'local/researchcentral:supervise', 0, 'u.*', 'u.lastname, u.firstname') as $u) {
            $supervisors[$u->id] = fullname($u);
        }

        $mform->addElement('autocomplete', 'userid', get_string('student', 'local_researchcentral'), $students);
        $mform->addRule('userid', null, 'required', null, 'client');
        $mform->addElement('autocomplete', 'supervisorid', get_string('supervisor', 'local_researchcentral'), $supervisors);
        $mform->addRule('supervisorid', null, 'required', null, 'client');
        $mform->addElement('select', 'level', get_string('stage_level', 'local_researchcentral'), tracker::levels());
        $deflevel = $DB->get_field('local_researchcentral_course', 'level', ['courseid' => $this->_customdata['courseid']]);
        $mform->setDefault('level', $deflevel ?: 'masters');
        $mform->addElement('text', 'title', get_string('thesistitle', 'local_researchcentral'), ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addElement('date_selector', 'startdate', get_string('startdate', 'local_researchcentral'));

        $this->add_action_buttons(false, get_string('assign_submit', 'local_researchcentral'));
    }
}
