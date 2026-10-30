<?php
namespace local_researchcentral\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_researchcentral\local\tracker;

class stage_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $courseid = $this->_customdata['courseid'];

        $mform->addElement('hidden', 'id', $courseid);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'addstage');
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement('text', 'name', get_string('stage_name', 'local_researchcentral'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        // Only activities with completion tracking can drive a milestone automatically.
        $options = [0 => get_string('stage_manual', 'local_researchcentral')];
        foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
            if ($cm->completion != COMPLETION_TRACKING_NONE && !$cm->deletioninprogress) {
                $options[$cm->id] = get_string('modulename', $cm->modname) . ': ' . $cm->get_formatted_name();
            }
        }
        $mform->addElement('select', 'cmid', get_string('stage_activity', 'local_researchcentral'), $options);
        $mform->addHelpButton('cmid', 'stage_activity', 'local_researchcentral');

        $mform->addElement('select', 'level', get_string('stage_level', 'local_researchcentral'), tracker::levels(true));
        $mform->addElement('text', 'defaultdays', get_string('stage_defaultdays', 'local_researchcentral'), ['size' => 5]);
        $mform->setType('defaultdays', PARAM_INT);
        $mform->setDefault('defaultdays', 30);
        $mform->addElement('text', 'sortorder', get_string('stage_sortorder', 'local_researchcentral'), ['size' => 5]);
        $mform->setType('sortorder', PARAM_INT);
        $mform->setDefault('sortorder', 10);

        $this->add_action_buttons(false, get_string('stage_add', 'local_researchcentral'));
    }
}
