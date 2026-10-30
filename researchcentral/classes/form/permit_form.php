<?php
namespace local_researchcentral\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class permit_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $L = fn($k) => get_string($k, 'local_researchcentral');
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'tab', $this->_customdata['tab']);
        $mform->setType('tab', PARAM_ALPHA);
        $mform->addElement('hidden', 'edit', $this->_customdata['edit'] ?? 0);
        $mform->setType('edit', PARAM_INT);
        $types = [];
        foreach (\local_researchcentral\local\research::PERMIT_TYPES as $t) {
            $types[$t] = $L('permit_type_' . $t);
        }
        $status = [];
        foreach (['pending', 'approved', 'rejected'] as $s) {
            $status[$s] = $L('permit_status_' . $s);
        }
        $mform->addElement('select', 'type', $L('permit_type'), $types);
        $mform->addElement('text', 'reference', $L('permit_reference'), ['size' => 40]);
        $mform->setType('reference', PARAM_TEXT);
        $mform->addElement('text', 'authority', $L('permit_authority'), ['size' => 40]);
        $mform->setType('authority', PARAM_TEXT);
        $mform->addElement('select', 'status', $L('permit_status'), $status);
        $mform->addElement('date_selector', 'issued', $L('permit_issued'), ['optional' => true]);
        $mform->addElement('date_selector', 'expiry', $L('permit_expiry'), ['optional' => true, 'startyear' => date('Y'), 'stopyear' => date('Y') + 8]);
        $mform->addElement('textarea', 'note', $L('permit_note'), ['rows' => 2, 'cols' => 50]);
        $mform->setType('note', PARAM_TEXT);
        $this->add_action_buttons(!empty($this->_customdata['edit']), $L('save'));
    }
}
