<?php
namespace local_researchcentral\form;

defined("MOODLE_INTERNAL") || die();

require_once($CFG->libdir . "/formslib.php");

/** A supervisor's saved personal meeting links, reused when creating online slots. */
class links_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'action', 'savelinks');
        $mform->setType('action', PARAM_ALPHA);
        foreach (['zoom', 'meet', 'teams'] as $p) {
            $mform->addElement('text', 'link_' . $p, get_string('platform_' . $p, 'local_researchcentral'), ['size' => 60]);
            $mform->setType('link_' . $p, PARAM_URL);
        }
        $this->add_action_buttons(false, get_string('savechanges'));
    }
}
