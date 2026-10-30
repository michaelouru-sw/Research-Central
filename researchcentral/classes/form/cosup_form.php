<?php
namespace local_researchcentral\form;

defined("MOODLE_INTERNAL") || die();

require_once($CFG->libdir . "/formslib.php");

class cosup_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'addcosup');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('autocomplete', 'cosupid', get_string('co_supervisor', 'local_researchcentral'), [], [
            'ajax' => 'core_user/form_user_selector', 'multiple' => false, 'noselectionstring' => get_string('choose'),
            'valuehtmlcallback' => function ($userid) {
                global $DB;
                $u = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
                return $u ? s(fullname($u) . ' (' . $u->email . ')') : '';
            },
        ]);
        $mform->addRule('cosupid', null, 'required', null, 'client');
        $this->add_action_buttons(false, get_string('cosup_add', 'local_researchcentral'));
    }
}
