<?php
namespace local_researchcentral\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class snip_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $L = fn($k) => get_string($k, 'local_researchcentral');
        $mform->addElement('hidden', 'edit', $this->_customdata['edit'] ?? 0);
        $mform->setType('edit', PARAM_INT);
        $mform->addElement('text', 'title', $L('snip_title'), ['size' => 50]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addElement('textarea', 'body', $L('snip_body'), ['rows' => 5, 'cols' => 60]);
        $mform->setType('body', PARAM_TEXT);
        $mform->addRule('body', null, 'required', null, 'client');
        $this->add_action_buttons(!empty($this->_customdata['edit']), $L('save'));
    }
}
