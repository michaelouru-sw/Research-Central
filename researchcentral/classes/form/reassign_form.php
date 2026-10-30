<?php
namespace local_researchcentral\form;

defined("MOODLE_INTERNAL") || die();

require_once($CFG->libdir . "/formslib.php");

class reassign_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'reassign');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('autocomplete', 'supervisorid', get_string('supervisor', 'local_researchcentral'), [], [
            'ajax' => 'core_user/form_user_selector', 'multiple' => false, 'noselectionstring' => get_string('choose'),
            'valuehtmlcallback' => function ($userid) {
                global $DB;
                $u = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
                return $u ? s(fullname($u) . ' (' . $u->email . ')') : '';
            },
        ]);
        $mform->addRule('supervisorid', null, 'required', null, 'client');
        $this->add_action_buttons(true, get_string('reassign', 'local_researchcentral'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!empty($data['supervisorid']) && $data['supervisorid'] == $this->_customdata['userid']) {
            $errors['supervisorid'] = get_string('err_samesupervisor', 'local_researchcentral');
        }
        return $errors;
    }
}
