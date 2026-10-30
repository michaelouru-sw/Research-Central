<?php
namespace local_researchcentral\form;

defined("MOODLE_INTERNAL") || die();

require_once($CFG->libdir . "/formslib.php");

/** Extension decision step: customdata step = endorse (supervisor, first eye) | confirm (coordinator, second eye). */
class extdecision_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $L = fn($k) => get_string($k, 'local_researchcentral');
        $step = $this->_customdata['step'];
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'ext', $this->_customdata['ext']);
        $mform->setType('ext', PARAM_INT);
        $mform->addElement('hidden', 'action', 'extdecide');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('hidden', 'step', $step);
        $mform->setType('step', PARAM_ALPHA);
        $mform->addElement('static', 'summary', $L('ext_request_summary'), $this->_customdata['summary']);
        $mform->addElement('select', 'verdict', $L($step === 'endorse' ? 'ext_verdict_endorse' : 'ext_verdict_confirm'), [
            1 => $L($step === 'endorse' ? 'ext_endorse' : 'ext_confirm'), 0 => $L('ext_decline')]);
        $mform->addElement('textarea', 'note', $L('ext_note'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('note', PARAM_TEXT);
        $this->add_action_buttons(true, $L('ext_submit_decision'));
    }
}
