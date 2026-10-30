<?php
namespace local_researchcentral\form;

defined("MOODLE_INTERNAL") || die();

require_once($CFG->libdir . "/formslib.php");

class decision_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'pms', $this->_customdata['pms']);
        $mform->setType('pms', PARAM_INT);
        $mform->addElement('hidden', 'action', 'decide');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('select', 'decision', get_string('decision', 'local_researchcentral'), [
            'approve' => get_string('decision_approve', 'local_researchcentral'),
            'revision' => get_string('decision_revision', 'local_researchcentral'),
        ]);
        $max = (int)($this->_customdata['maxgrade'] ?? 0);
        if ($max > 0) {
            $mform->addElement('text', 'grade', get_string('mark_out_of', 'local_researchcentral', $max), ['size' => 6]);
            $mform->setType('grade', PARAM_RAW);
        }
        $mform->addElement('textarea', 'note', get_string('feedback', 'local_researchcentral'), ['rows' => 5, 'cols' => 50]);
        $mform->setType('note', PARAM_TEXT);
        $this->add_action_buttons(true, get_string('save_decision', 'local_researchcentral'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ($data['decision'] === 'revision' && trim($data['note']) === '') {
            $errors['note'] = get_string('feedback_required', 'local_researchcentral');
        }
        $max = (int)($this->_customdata['maxgrade'] ?? 0);
        if ($max > 0) {
            $g = trim((string)($data['grade'] ?? ''));
            if ($g === '') {
                if ($data['decision'] === 'approve') {
                    $errors['grade'] = get_string('mark_required', 'local_researchcentral');
                }
            } else if (!is_numeric(str_replace(',', '.', $g)) || (float)str_replace(',', '.', $g) < 0
                    || (float)str_replace(',', '.', $g) > $max) {
                $errors['grade'] = get_string('mark_invalid', 'local_researchcentral', $max);
            }
        }
        return $errors;
    }
}
