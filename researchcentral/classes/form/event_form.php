<?php
namespace local_researchcentral\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class event_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $L = fn($k) => get_string($k, 'local_researchcentral');
        $mform->addElement('hidden', 'edit', $this->_customdata['edit'] ?? 0);
        $mform->setType('edit', PARAM_INT);
        $mform->addElement('text', 'title', $L('event_title'), ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addElement('textarea', 'descr', $L('event_descr'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('descr', PARAM_TEXT);
        $mform->addElement('date_time_selector', 'starttime', $L('slot_start'));
        $mform->addElement('select', 'duration', $L('viva_duration'), [30 => '30 min', 60 => '1 h', 90 => '90 min', 120 => '2 h', 180 => '3 h', 240 => '4 h']);
        $mform->setDefault('duration', 90);
        $mform->addElement('text', 'venue', $L('viva_venue'), ['size' => 50]);
        $mform->setType('venue', PARAM_TEXT);
        $mform->addElement('text', 'joinurl', $L('slot_joinurl'), ['size' => 60]);
        $mform->setType('joinurl', PARAM_URL);
        $mform->addElement('select', 'progid', $L('event_audience'), ['0' => $L('event_all')] + ($this->_customdata['progs'] ?? []));
        $mform->addElement('text', 'capacity', $L('event_capacity'), ['size' => 5]);
        $mform->setType('capacity', PARAM_INT);
        $mform->setDefault('capacity', 0);
        $mform->addHelpButton('capacity', 'event_capacity', 'local_researchcentral');
        $mform->addElement('advcheckbox', 'notify', $L('event_notify'));
        $mform->setDefault('notify', 1);
        $this->add_action_buttons(!empty($this->_customdata['edit']), $L('save'));
    }
}
