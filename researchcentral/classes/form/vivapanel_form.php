<?php
namespace local_researchcentral\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class vivapanel_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $L = fn($k) => get_string($k, 'local_researchcentral');
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'tab', $this->_customdata['tab']);
        $mform->setType('tab', PARAM_ALPHA);
        $mform->addElement('hidden', 'edit', $this->_customdata['edit'] ?? 0);
        $mform->setType('edit', PARAM_INT);
        $mform->addElement('hidden', 'vivaid', $this->_customdata['vivaid']);
        $mform->setType('vivaid', PARAM_INT);
        $mform->addElement('text', 'name', $L('viva_examiner'), ['size' => 40]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addElement('select', 'role', $L('viva_role'), ['chair' => $L('viva_role_chair'), 'internal' => $L('viva_role_internal'), 'external' => $L('viva_role_external')]);
        $outs = ['' => '-'];
        foreach (\local_researchcentral\local\research::VIVA_OUTCOMES as $o) {
            $outs[$o] = $L('viva_out_' . $o);
        }
        $mform->addElement('select', 'recommendation', $L('viva_recommendation'), $outs);
        $mform->addElement('textarea', 'report', $L('viva_report'), ['rows' => 6, 'cols' => 60]);
        $mform->setType('report', PARAM_TEXT);
        $this->add_action_buttons(!empty($this->_customdata['edit']), $L('save'));
    }
}
