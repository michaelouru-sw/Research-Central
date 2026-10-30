<?php
namespace local_researchcentral\form;

defined("MOODLE_INTERNAL") || die();

require_once($CFG->libdir . "/formslib.php");

class book_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'slot', $this->_customdata['slot']);
        $mform->setType('slot', PARAM_INT);
        $mform->addElement('hidden', 'action', 'book');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('static', 'when', get_string('slot_when', 'local_researchcentral'), $this->_customdata['when']);
        if (!empty($this->_customdata['where'])) {
            $mform->addElement('static', 'where', get_string('slot_location', 'local_researchcentral'), s($this->_customdata['where']));
        }
        if (!empty($this->_customdata['agenda'])) {
            $mform->addElement('static', 'agendainfo', get_string('appt_agenda', 'local_researchcentral'), s($this->_customdata['agenda']));
        }
        $mform->addElement('text', 'topic', get_string('book_topic', 'local_researchcentral'), ['size' => 60]);
        $mform->setType('topic', PARAM_TEXT);
        $mform->addRule('topic', null, 'required', null, 'client');
        $this->add_action_buttons(true, get_string('book_confirm', 'local_researchcentral'));
    }
}
