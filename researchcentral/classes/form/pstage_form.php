<?php
namespace local_researchcentral\form;

defined("MOODLE_INTERNAL") || die();

require_once($CFG->libdir . "/formslib.php");

class pstage_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'addstage');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('text', 'name', get_string('stage_name', 'local_researchcentral'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addElement('text', 'defaultdays', get_string('stage_days', 'local_researchcentral'), ['size' => 5]);
        $mform->setType('defaultdays', PARAM_INT);
        $mform->setDefault('defaultdays', 30);
        $mform->addElement('advcheckbox', 'needsupload', get_string('stage_needsupload', 'local_researchcentral'));
        $mform->setDefault('needsupload', 1);
        $mform->addElement('text', 'maxmb', get_string('stage_maxmb', 'local_researchcentral'), ['size' => 5]);
        $mform->setType('maxmb', PARAM_INT);
        $mform->setDefault('maxmb', 0);
        $mform->addElement('text', 'maxgrade', get_string('stage_maxgrade', 'local_researchcentral'), ['size' => 5]);
        $mform->setType('maxgrade', PARAM_INT);
        $mform->setDefault('maxgrade', 100);
        $mform->addElement('text', 'weight', get_string('stage_weight', 'local_researchcentral'), ['size' => 5]);
        $mform->setType('weight', PARAM_INT);
        $mform->setDefault('weight', 0);
        $mform->addElement('static', 'wnote', '', get_string('stage_weight_note', 'local_researchcentral'));
        $this->add_action_buttons(false, get_string('stage_add', 'local_researchcentral'));
    }
}
