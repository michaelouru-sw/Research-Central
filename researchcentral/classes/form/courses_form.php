<?php
namespace local_researchcentral\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class courses_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('course', 'courseids', get_string('selectcourses', 'local_researchcentral'),
            ['multiple' => true, 'includefrontpage' => false]);
        $mform->addRule('courseids', null, 'required', null, 'client');
        $this->add_action_buttons(false, get_string('enablecourses', 'local_researchcentral'));
    }
}
