<?php
namespace local_researchcentral\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class sim_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $L = fn($k) => get_string($k, 'local_researchcentral');
        $mform->addElement('hidden', 'pms', $this->_customdata['pms']);
        $mform->setType('pms', PARAM_INT);
        $mform->addElement('hidden', 'verid', $this->_customdata['verid']);
        $mform->setType('verid', PARAM_INT);
        $mform->addElement('hidden', 'action', 'similarity');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('text', 'simpct', $L('sim_pct'), ['size' => 4]);
        $mform->setType('simpct', PARAM_INT);
        $mform->addElement('text', 'simnote', $L('sim_note'), ['size' => 50]);
        $mform->setType('simnote', PARAM_TEXT);
        $this->add_action_buttons(false, $L('sim_save'));
    }
    public function validation($data, $files) {
        $e = parent::validation($data, $files);
        if ($data['simpct'] !== '' && ((int)$data['simpct'] < 0 || (int)$data['simpct'] > 100)) {
            $e['simpct'] = get_string('sim_range', 'local_researchcentral');
        }
        return $e;
    }
}
