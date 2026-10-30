<?php
namespace local_researchcentral\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class viva_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $L = fn($k) => get_string($k, 'local_researchcentral');
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'tab', $this->_customdata['tab']);
        $mform->setType('tab', PARAM_ALPHA);
        $mform->addElement('hidden', 'edit', $this->_customdata['edit'] ?? 0);
        $mform->setType('edit', PARAM_INT);
        $mform->addElement('date_time_selector', 'starttime', $L('viva_start'));
        $mform->addElement('select', 'duration', $L('viva_duration'), [60 => '60 min', 90 => '90 min', 120 => '2 h', 180 => '3 h']);
        $mform->setDefault('duration', 120);
        $mform->addElement('text', 'venue', $L('viva_venue'), ['size' => 50]);
        $mform->setType('venue', PARAM_TEXT);
        $mform->addElement('text', 'joinurl', $L('slot_joinurl'), ['size' => 60]);
        $mform->setType('joinurl', PARAM_URL);
        $this->add_action_buttons(!empty($this->_customdata['edit']), $L('save'));
    }
}
