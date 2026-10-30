<?php
namespace local_researchcentral\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class vivaoutcome_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $L = fn($k) => get_string($k, 'local_researchcentral');
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'tab', $this->_customdata['tab']);
        $mform->setType('tab', PARAM_ALPHA);
        $mform->addElement('hidden', 'edit', $this->_customdata['edit'] ?? 0);
        $mform->setType('edit', PARAM_INT);
        $outs = ['' => '-'];
        foreach (\local_researchcentral\local\research::VIVA_OUTCOMES as $o) {
            $outs[$o] = $L('viva_out_' . $o);
        }
        $mform->addElement('select', 'outcome', $L('viva_outcome'), $outs);
        $mform->addElement('textarea', 'corrections', $L('viva_corrections'), ['rows' => 6, 'cols' => 60]);
        $mform->setType('corrections', PARAM_TEXT);
        $mform->addElement('date_selector', 'corrdeadline', $L('viva_corrdeadline'), ['optional' => true]);
        $this->add_action_buttons(false, $L('save'));
    }
}
