<?php
namespace local_researchcentral\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class rules_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', $this->_customdata['courseid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'stageid', $this->_customdata['stageid']);
        $mform->setType('stageid', PARAM_INT);

        $int = function (string $name, string $label, string $help = '') use ($mform) {
            $mform->addElement('text', $name, get_string($label, 'local_researchcentral'), ['size' => 6]);
            $mform->setType($name, PARAM_INT);
            $mform->setDefault($name, 0);
            if ($help) {
                $mform->addElement('static', $name . '_note', '', get_string($help, 'local_researchcentral'));
            }
        };

        $mform->addElement('header', 'h1', get_string('rules_length', 'local_researchcentral'));
        $int('minwords', 'rule_minwords', 'rule_zero_off');
        $int('maxwords', 'rule_maxwords');
        $int('minpages', 'rule_minpages');
        $int('maxpages', 'rule_maxpages');
        $mform->addElement('advcheckbox', 'excluderefs', get_string('rule_excluderefs', 'local_researchcentral'));
        $mform->setDefault('excluderefs', 1);

        $mform->addElement('header', 'h2', get_string('rules_structure', 'local_researchcentral'));
        $mform->addElement('textarea', 'headings', get_string('rule_headings', 'local_researchcentral'), ['rows' => 5, 'cols' => 40]);
        $mform->setType('headings', PARAM_TEXT);
        $mform->addElement('static', 'headings_note', '', get_string('rule_headings_note', 'local_researchcentral'));

        $mform->addElement('header', 'h3', get_string('rules_format', 'local_researchcentral'));
        $mform->addElement('text', 'font', get_string('rule_font', 'local_researchcentral'), ['size' => 24]);
        $mform->setType('font', PARAM_TEXT);
        $int('fontsize', 'rule_fontsize');
        $int('linespacing', 'rule_linespacing', 'rule_linespacing_note');
        $int('minmargin', 'rule_minmargin', 'rule_minmargin_note');

        $mform->addElement('header', 'h4', get_string('rules_refs', 'local_researchcentral'));
        $mform->addElement('select', 'refstyle', get_string('rule_refstyle', 'local_researchcentral'), [
            'none' => get_string('refstyle_none', 'local_researchcentral'),
            'apa' => 'APA',
            'harvard' => 'Harvard',
        ]);
        $int('minrefs', 'rule_minrefs');
        $int('recentyears', 'rule_recentyears');
        $int('minrecent', 'rule_minrecent', 'rule_minrecent_note');

        $mform->addElement('header', 'h5', get_string('rules_outcome', 'local_researchcentral'));
        $mform->addElement('select', 'severity', get_string('rule_severity', 'local_researchcentral'), [
            'warn' => get_string('severity_warn', 'local_researchcentral'),
            'fail' => get_string('severity_fail', 'local_researchcentral'),
        ]);

        $this->add_action_buttons(true, get_string('rules_save', 'local_researchcentral'));
    }
}
