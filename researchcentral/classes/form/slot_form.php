<?php
namespace local_researchcentral\form;

defined("MOODLE_INTERNAL") || die();

require_once($CFG->libdir . "/formslib.php");

/** Flexible slot creation: one day or a date range, chosen weekdays, up to three time windows a day. */
class slot_form extends \moodleform {
    public static function time_options(bool $none = false): array {
        $o = $none ? ['' => get_string('slot_nowindow', 'local_researchcentral')] : [];
        for ($m = 6 * 60; $m <= 22 * 60; $m += 15) {
            $o[$m] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }
        return $o;
    }

    protected function definition(): void {
        $mform = $this->_form;
        $L = fn($k) => get_string($k, 'local_researchcentral');
        $mform->addElement('hidden', 'action', 'addslots');
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement('date_selector', 'fromdate', $L('slot_date'));
        $mform->addElement('advcheckbox', 'userange', $L('slot_userange'));
        $mform->addElement('date_selector', 'todate', $L('slot_until'));
        $mform->hideIf('todate', 'userange', 'notchecked');
        $cbs = [];
        foreach ([1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 0 => 'sunday'] as $n => $key) {
            $cbs[] = $mform->createElement('advcheckbox', 'day' . $n, '', get_string($key, 'calendar'));
            $mform->setDefault('day' . $n, $n >= 1 && $n <= 5 ? 1 : 0);
        }
        $mform->addGroup($cbs, 'dayset', $L('slot_days'), ' ', false);
        $mform->hideIf('dayset', 'userange', 'notchecked');

        $opts = self::time_options();
        $optsn = self::time_options(true);
        for ($i = 1; $i <= 3; $i++) {
            $grp = [$mform->createElement('select', 'ws' . $i, '', $i === 1 ? $opts : $optsn),
                $mform->createElement('static', 'to' . $i, '', $L('slot_to')),
                $mform->createElement('select', 'we' . $i, '', $i === 1 ? $opts : $optsn)];
            $mform->addGroup($grp, 'win' . $i, $L('slot_window') . ' ' . $i, ' ', false);
        }
        $mform->setDefault('ws1', 9 * 60);
        $mform->setDefault('we1', 12 * 60);
        $mform->setDefault('ws2', 14 * 60);
        $mform->setDefault('we2', 17 * 60);
        $mform->setDefault('ws2', '');
        $mform->setDefault('we2', '');

        $mform->addElement('select', 'duration', $L('slot_duration'), [15 => '15 min', 20 => '20 min', 30 => '30 min', 40 => '40 min',
            45 => '45 min', 60 => '60 min', 90 => '90 min', 120 => '120 min']);
        $mform->setDefault('duration', 30);
        $mform->addElement('select', 'gap', $L('slot_gap'), [0 => '0 min', 5 => '5 min', 10 => '10 min', 15 => '15 min', 30 => '30 min']);

        $mform->addElement('select', 'platform', $L('slot_platform'), \local_researchcentral\local\appt::platforms());
        $acts = $this->_customdata['activities'] ?? [];
        if ($acts) {
            $mform->addElement('select', 'activity', $L('slot_activity'), ['' => $L('slot_noactivity')] + $acts);
        }
        $mform->addElement('text', 'joinurl', $L('slot_joinurl'), ['size' => 60]);
        $mform->setType('joinurl', PARAM_URL);
        $mform->addHelpButton('joinurl', 'slot_joinurl', 'local_researchcentral');
        $mform->addElement('advcheckbox', 'savelink', $L('slot_savelink'));
        $mform->addElement('text', 'location', $L('slot_location'), ['size' => 50]);
        $mform->setType('location', PARAM_TEXT);

        $students = $this->_customdata['students'] ?? [];
        $mform->addElement('select', 'projid', $L('slot_reserve'), ['' => $L('slot_reserve_none')] + $students);
        $mform->addElement('textarea', 'agenda', $L('appt_agenda'), ['rows' => 2, 'cols' => 50]);
        $mform->setType('agenda', PARAM_TEXT);
        $mform->addElement('textarea', 'supnote', $L('appt_supnote'), ['rows' => 2, 'cols' => 50]);
        $mform->setType('supnote', PARAM_TEXT);
        $this->add_action_buttons(false, $L('slot_add'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $any = false;
        for ($i = 1; $i <= 3; $i++) {
            $s = $data['ws' . $i] ?? '';
            $e = $data['we' . $i] ?? '';
            if ($s === '' && $e === '') {
                continue;
            }
            if ($s === '' || $e === '' || (int)$e <= (int)$s) {
                $errors['win' . $i] = get_string('slot_err_window', 'local_researchcentral');
            } else {
                $any = true;
            }
        }
        if (!$any && empty($errors['win1'])) {
            $errors['win1'] = get_string('slot_err_window', 'local_researchcentral');
        }
        if (!empty($data['userange']) && $data['todate'] < $data['fromdate']) {
            $errors['todate'] = get_string('slot_err_range', 'local_researchcentral');
        }
        if (!empty($data['userange'])) {
            $picked = false;
            for ($d = 0; $d <= 6; $d++) {
                $picked = $picked || !empty($data['day' . $d]);
            }
            if (!$picked) {
                $errors['dayset'] = get_string('slot_err_days', 'local_researchcentral');
            }
        }
        $plat = $data['platform'] ?? 'inperson';
        $link = trim($data['joinurl'] ?? '');
        if ($plat !== 'inperson' && $link === '' && empty($data['activity'])
                && \local_researchcentral\local\appt::default_link($GLOBALS['USER']->id, $plat) === '') {
            $errors['joinurl'] = get_string('slot_err_link', 'local_researchcentral');
        }
        return $errors;
    }
}
