<?php
namespace local_researchcentral\form;

defined("MOODLE_INTERNAL") || die();

require_once($CFG->libdir . "/formslib.php");

class project_form extends \moodleform {
    protected function definition(): void {
        global $DB;
        $mform = $this->_form;
        $userfn = function ($userid) {
            global $DB;
            $u = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
            return $u ? fullname($u) . ' (' . $u->email . ')' : '';
        };
        $opts = fn() => [
            'ajax' => 'core_user/form_user_selector', 'multiple' => false, 'noselectionstring' => get_string('choose'),
            'valuehtmlcallback' => function ($userid) use ($userfn) {
                return s($userfn($userid));
            },
        ];
        $mform->addElement('autocomplete', 'userid', get_string('student', 'local_researchcentral'), [], $opts());
        $mform->addRule('userid', null, 'required', null, 'client');
        $mform->addElement('autocomplete', 'supervisorid', get_string('supervisor', 'local_researchcentral'), [], $opts());
        $mform->addRule('supervisorid', null, 'required', null, 'client');
        $progs = [];
        $schools = \local_researchcentral\local\portal::schools(true);
        foreach ($DB->get_records('local_researchcentral_prog', null, 'name') as $p) {
            if (!\local_researchcentral\local\portal::can_manage_school((int)$p->schoolid)) {
                continue;
            }
            $progs[$p->id] = format_string($p->name) . (!empty($schools[$p->schoolid]) ? ' – ' . s($schools[$p->schoolid]) : '');
        }
        $mform->addElement('select', 'progid', get_string('programme', 'local_researchcentral'), $progs);
        $mform->addElement('text', 'title', get_string('thesistitle', 'local_researchcentral'), ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addElement('date_selector', 'startdate', get_string('startdate', 'local_researchcentral'));
        $this->add_action_buttons(true, get_string('project_create', 'local_researchcentral'));
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        if (!empty($data['userid']) && $data['userid'] == $data['supervisorid']) {
            $errors['supervisorid'] = get_string('err_samesupervisor', 'local_researchcentral');
        }
        if (!empty($data['userid']) && $DB->record_exists('local_researchcentral_proj',
                ['userid' => $data['userid'], 'progid' => $data['progid']])) {
            $errors['userid'] = get_string('err_projectexists', 'local_researchcentral');
        }
        if (!$DB->record_exists('local_researchcentral_stage', ['progid' => $data['progid']])) {
            $errors['progid'] = get_string('err_nostages', 'local_researchcentral');
        }
        return $errors;
    }
}
