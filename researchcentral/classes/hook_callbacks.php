<?php
namespace local_researchcentral;

defined('MOODLE_INTERNAL') || die();

/**
 * Adds a "Research Central" item to the primary navigation, only for people who have a thesis role.
 */
class hook_callbacks {
    public static function primary_extend(\core\hook\navigation\primary_extend $hook): void {
        global $USER, $DB;
        if (!isloggedin() || isguestuser()) {
            return;
        }
        if (!\local_researchcentral\local\portal::roles()) {
            return;
        }
        $hook->get_primaryview()->add(
            get_string('pluginname', 'local_researchcentral'),
            new \moodle_url('/local/researchcentral/portal.php'),
            \navigation_node::TYPE_CUSTOM,
            null,
            'researchcentral'
        );
    }

    /** Plugin pages only: load the full stylesheet (kept out of the site-wide theme CSS for speed). */
    public static function plugin_css(\core\hook\output\before_standard_head_html_generation $hook): void {
        global $PAGE, $CFG;
        try {
            $local = $PAGE->has_set_url() ? $PAGE->url->out_as_local_url(false) : '';
        } catch (\Throwable $e) {
            return;
        }
        if (strpos($local, '/local/researchcentral/') !== 0) {
            return;
        }
        $v = (int)get_config('local_researchcentral', 'version');
        $hook->add_html(\html_writer::empty_tag('link', ['rel' => 'stylesheet',
            'href' => $CFG->wwwroot . '/local/researchcentral/css/tt.css?v=' . $v]));
    }

    /** Plugin pages only: skip link, quick search and the shared interaction layer (js/tt.js). */
    public static function ux_toolbar(\core\hook\output\before_standard_top_of_body_html_generation $hook): void {
        global $PAGE;
        if (!isloggedin() || isguestuser() || $PAGE->pagelayout === 'embedded') {
            return;
        }
        try {
            $local = $PAGE->url->out_as_local_url(false);
        } catch (\Throwable $e) {
            return;
        }
        if (strpos($local, '/local/researchcentral/') !== 0) {
            return;
        }
                $L = fn($k, $a = null) => get_string($k, 'local_researchcentral', $a);
        $strings = [
            'cancel' => get_string('cancel'), 'ok' => $L('ux_ok'), 'confirm_generic' => $L('ux_confirm_generic'),
            'draft_restored' => $L('ux_saved_draft'), 'discard' => $L('ux_discard'),
            'days_left' => $L('ux_countdown_days', '{n}'), 'due_today' => $L('ux_countdown_today'), 'days_late' => $L('ux_countdown_late', '{n}'),
            'filter_rows' => $L('ux_filter_rows'), 'reader_keys' => $L('ux_reader_keys'),
            'err_type' => $L('up_err_type', '{name}'), 'err_imgtype' => $L('up_err_imgtype', '{name}'),
            'err_size' => $L('up_err_size', (object)['name' => '{name}', 'size' => '{size}', 'limit' => '{limit}']),
            'err_nodoc' => $L('up_err_nodoc'), 'err_nocaption' => $L('up_err_nocaption', '{name}'), 'err_toomany' => $L('up_err_toomany', '{n}'),
            'err_failed' => $L('ux_err_failed'), 'err_network' => $L('ux_err_network'),
        ];
        $PAGE->requires->js(new \moodle_url('/local/researchcentral/js/tt.js'));
        $hook->add_html(\html_writer::tag('script', json_encode($strings, JSON_HEX_TAG | JSON_HEX_AMP), ['type' => 'application/json', 'id' => 'tt-strings']));
    }

    protected static function can_create(): bool {
        global $DB;
        $catid = optional_param('category', 0, PARAM_INT);
        $context = ($catid && $DB->record_exists('course_categories', ['id' => $catid]))
            ? \context_coursecat::instance($catid) : \context_system::instance();
        return has_capability('local/researchcentral:createcourse', $context);
    }

    /** Adds the "Thesis course" checkbox to the course creation form (new courses only). */
    public static function course_form(\core_course\hook\after_form_definition $hook): void {
        if (optional_param('id', 0, PARAM_INT) || !self::can_create()) {
            return;
        }
        $mform = $hook->mform;
        $mform->addElement('header', 'thesisheader', get_string('thesiscourse', 'local_researchcentral'));
        $mform->addElement('advcheckbox', 'thesiscourse', get_string('thesiscourse_make', 'local_researchcentral'));
        $mform->addElement('select', 'thesislevel', get_string('thesislevel', 'local_researchcentral'),
            \local_researchcentral\local\tracker::levels());
        $mform->setDefault('thesislevel', 'masters');
        $mform->hideIf('thesislevel', 'thesiscourse', 'notchecked');
        $mform->addHelpButton('thesiscourse', 'thesiscourse_make', 'local_researchcentral');
        $mform->setDefault('thesiscourse', 0);
    }

    /** Builds the thesis structure after a new course is saved with the box ticked. */
    public static function course_saved(\core_course\hook\after_form_submission $hook): void {
        global $DB;
        $data = $hook->get_data();
        if (empty($hook->isnewcourse) || empty($data->thesiscourse)) {
            return;
        }
        $courseid = !empty($data->id) ? (int)$data->id
            : (int)$DB->get_field('course', 'id', ['shortname' => $data->shortname]);
        if (!$courseid) {
            return;
        }
        $res = \local_researchcentral\local\template::build($courseid, (bool)get_config('local_researchcentral', 'createactivities'),
            in_array($data->thesislevel ?? '', ['diploma', 'ug', 'masters', 'phd']) ? $data->thesislevel : 'masters');
        if ($res['errors']) {
            debugging('local_researchcentral template: ' . implode(' | ', $res['errors']), DEBUG_DEVELOPER);
        }
    }
}
