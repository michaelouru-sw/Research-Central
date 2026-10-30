<?php
namespace local_researchcentral\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class scoord_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'action', 'addcoord');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('select', 'schoolid', get_string('school', 'local_researchcentral'),
            \local_researchcentral\local\portal::schools());
        $mform->addElement('autocomplete', 'userid', get_string('coordinator', 'local_researchcentral'), [], [
            'ajax' => 'core_user/form_user_selector', 'multiple' => false, 'noselectionstring' => get_string('choose'),
            'valuehtmlcallback' => function ($userid) {
                global $DB;
                $u = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
                return $u ? s(fullname($u) . ' (' . $u->email . ')') : '';
            },
        ]);
        $mform->addRule('userid', null, 'required', null, 'client');
        $this->add_action_buttons(false, get_string('coord_assign', 'local_researchcentral'));
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        if (!empty($data['userid']) && $DB->record_exists('local_researchcentral_scoord',
                ['schoolid' => $data['schoolid'], 'userid' => $data['userid']])) {
            $errors['userid'] = get_string('coord_exists', 'local_researchcentral');
        }
        return $errors;
    }
}
