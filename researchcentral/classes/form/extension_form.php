<?php
namespace local_researchcentral\form;

defined("MOODLE_INTERNAL") || die();

require_once($CFG->libdir . "/formslib.php");

class extension_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'pms', $this->_customdata['pms']);
        $mform->setType('pms', PARAM_INT);
        $mform->addElement('hidden', 'action', 'extend');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('date_selector', 'requestdate', get_string('ext_newdate', 'local_researchcentral'));
        $mform->addElement('textarea', 'reason', get_string('ext_reason', 'local_researchcentral'), ['rows' => 4, 'cols' => 50]);
        $mform->setType('reason', PARAM_TEXT);
        $mform->addRule('reason', null, 'required', null, 'client');
        $this->add_action_buttons(true, get_string('ext_send', 'local_researchcentral'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ($data['requestdate'] <= time()) {
            $errors['requestdate'] = get_string('ext_future', 'local_researchcentral');
        }
        return $errors;
    }
}
