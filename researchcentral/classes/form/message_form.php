<?php
namespace local_researchcentral\form;

defined("MOODLE_INTERNAL") || die();

require_once($CFG->libdir . "/formslib.php");

class message_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'message');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('textarea', 'body', get_string('message_new', 'local_researchcentral'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('body', PARAM_TEXT);
        $mform->addRule('body', null, 'required', null, 'client');
        $this->add_action_buttons(false, get_string('send_message', 'local_researchcentral'));
    }
}
