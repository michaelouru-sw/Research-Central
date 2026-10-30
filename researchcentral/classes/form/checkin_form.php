<?php
namespace local_researchcentral\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class checkin_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $L = fn($k) => get_string($k, 'local_researchcentral');
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'tab', $this->_customdata['tab']);
        $mform->setType('tab', PARAM_ALPHA);
        $mform->addElement('hidden', 'edit', $this->_customdata['edit'] ?? 0);
        $mform->setType('edit', PARAM_INT);
        $mform->addElement('static', 'periodinfo', $L('checkin_period'), s($this->_customdata['period']));
        $mform->addElement('select', 'progress', $L('checkin_progress'), [5 => $L('checkin_p5'), 4 => $L('checkin_p4'), 3 => $L('checkin_p3'), 2 => $L('checkin_p2'), 1 => $L('checkin_p1')]);
        $mform->setDefault('progress', 3);
        $mform->addElement('textarea', 'plans', $L('checkin_plans'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('plans', PARAM_TEXT);
        $mform->addElement('textarea', 'blockers', $L('checkin_blockers'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('blockers', PARAM_TEXT);
        $mform->addElement('advcheckbox', 'needsupport', $L('checkin_needsupport'));
        $this->add_action_buttons(false, $L('checkin_submit'));
    }
}
