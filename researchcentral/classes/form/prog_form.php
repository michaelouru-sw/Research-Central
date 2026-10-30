<?php
namespace local_researchcentral\form;

defined("MOODLE_INTERNAL") || die();

require_once($CFG->libdir . "/formslib.php");

class prog_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('text', 'name', get_string('prog_name', 'local_researchcentral'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $schools = \local_researchcentral\local\portal::schools(true);
        $mform->addElement('select', 'schoolid', get_string('school', 'local_researchcentral'), $schools);
        $mform->addRule('schoolid', null, 'required', null, 'client');
        $mform->addElement('select', 'level', get_string('stage_level', 'local_researchcentral'),
            \local_researchcentral\local\tracker::levels());
        $mform->setDefault('level', 'masters');
        $mform->addElement('advcheckbox', 'usetemplate', get_string('prog_template', 'local_researchcentral'));
        $mform->setDefault('usetemplate', 1);
        $mform->addElement('static', 'tnote', '', get_string('prog_template_note', 'local_researchcentral'));
        $this->add_action_buttons(true, get_string('prog_create', 'local_researchcentral'));
    }
}
