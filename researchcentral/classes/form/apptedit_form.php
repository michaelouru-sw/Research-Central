<?php
namespace local_researchcentral\form;

defined("MOODLE_INTERNAL") || die();

require_once($CFG->libdir . "/formslib.php");

/** Supervisor edits one appointment: meeting details, agenda, private notes, minutes, allocation. */
class apptedit_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $L = fn($k) => get_string($k, 'local_researchcentral');
        $mform->addElement('hidden', 'slot', $this->_customdata['slot']);
        $mform->setType('slot', PARAM_INT);
        $mform->addElement('hidden', 'action', 'edit');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('static', 'when', $L('slot_when'), $this->_customdata['when']);
        $mform->addElement('select', 'platform', $L('slot_platform'), \local_researchcentral\local\appt::platforms());
        $mform->addElement('text', 'joinurl', $L('slot_joinurl'), ['size' => 60]);
        $mform->setType('joinurl', PARAM_URL);
        $mform->addElement('text', 'location', $L('slot_location'), ['size' => 50]);
        $mform->setType('location', PARAM_TEXT);
        if (!empty($this->_customdata['students'])) {
            $mform->addElement('select', 'projid', $L('slot_reserve'), ['' => $L('slot_reserve_none')] + $this->_customdata['students']);
        }
        $mform->addElement('textarea', 'agenda', $L('appt_agenda'), ['rows' => 3, 'cols' => 50]);
        $mform->setType('agenda', PARAM_TEXT);
        $mform->addElement('textarea', 'supnote', $L('appt_supnote'), ['rows' => 3, 'cols' => 50]);
        $mform->setType('supnote', PARAM_TEXT);
        $mform->addElement('textarea', 'minutes', $L('appt_minutes'), ['rows' => 5, 'cols' => 50]);
        $mform->setType('minutes', PARAM_TEXT);
        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
