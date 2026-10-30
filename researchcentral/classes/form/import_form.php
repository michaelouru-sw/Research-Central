<?php
namespace local_researchcentral\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class import_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('filepicker', 'csvfile', get_string('csvfile', 'local_researchcentral'), null, ['accepted_types' => ['.csv']]);
        $mform->addRule('csvfile', null, 'required', null, 'client');
        $mform->addElement('select', 'delimiter', get_string('csvdelimiter', 'local_researchcentral'),
            \csv_import_reader::get_delimiter_list());
        $mform->setDefault('delimiter', 'comma');
        $mform->addElement('static', 'cols', get_string('csv_columns', 'local_researchcentral'),
            (is_array($this->_customdata) && !empty($this->_customdata['colsdesc']))
                ? $this->_customdata['colsdesc'] : get_string('csv_columns_desc', 'local_researchcentral'));
        $this->add_action_buttons(false, get_string('csv_preview', 'local_researchcentral'));
    }
}
