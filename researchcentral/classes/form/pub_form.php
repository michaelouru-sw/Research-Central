<?php
namespace local_researchcentral\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class pub_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $L = fn($k) => get_string($k, 'local_researchcentral');
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'tab', $this->_customdata['tab']);
        $mform->setType('tab', PARAM_ALPHA);
        $mform->addElement('hidden', 'edit', $this->_customdata['edit'] ?? 0);
        $mform->setType('edit', PARAM_INT);
        $types = $status = [];
        foreach (\local_researchcentral\local\research::PUB_TYPES as $t) {
            $types[$t] = $L('pub_type_' . $t);
        }
        foreach (\local_researchcentral\local\research::PUB_STATUS as $s) {
            $status[$s] = $L('pub_status_' . $s);
        }
        $mform->addElement('text', 'title', $L('pub_title'), ['size' => 70]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addElement('select', 'type', $L('pub_type'), $types);
        $mform->addElement('text', 'venue', $L('pub_venue'), ['size' => 50]);
        $mform->setType('venue', PARAM_TEXT);
        $mform->addElement('select', 'status', $L('pub_status'), $status);
        $mform->addElement('text', 'year', $L('pub_year'), ['size' => 6]);
        $mform->setType('year', PARAM_INT);
        $mform->addElement('text', 'doi', $L('pub_doi'), ['size' => 50]);
        $mform->setType('doi', PARAM_TEXT);
        $this->add_action_buttons(!empty($this->_customdata['edit']), $L('save'));
    }
}
