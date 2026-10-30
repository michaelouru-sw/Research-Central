<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds the thesis course structure: sections, linked Assignments and tracker stages.
 */
class template {
    /** @return array[] key, name, days, level, kind (assign|manual) for the given level. */
    public static function definition(string $level = 'masters'): array {
        $diploma = [
            ['concept', 'Topic and concept note', 14, 'assign'],
            ['proposal', 'Project proposal', 35, 'assign'],
            ['defence', 'Proposal presentation', 50, 'manual'],
            ['draft', 'Draft project report', 85, 'assign'],
            ['final', 'Final project report', 110, 'assign'],
            ['presentation', 'Final presentation', 120, 'manual'],
        ];
        $ug = [
            ['concept', 'Topic and concept note', 14, 'assign'],
            ['proposal', 'Proposal', 45, 'assign'],
            ['defence', 'Proposal defence', 60, 'manual'],
            ['ch123', 'Chapters 1 to 3', 90, 'assign'],
            ['ch45', 'Chapters 4 and 5', 140, 'assign'],
            ['final', 'Final project submission', 160, 'assign'],
        ];
        $masters = [
            ['concept', 'Topic and concept note', 21, 'assign'],
            ['proposal', 'Proposal', 60, 'assign'],
            ['defence', 'Proposal defence', 90, 'manual'],
            ['ethics', 'Ethics and research permit', 105, 'assign'],
            ['ch123', 'Chapters 1 to 3', 150, 'assign'],
            ['ch45', 'Chapters 4 and 5', 270, 'assign'],
            ['final', 'Final submission', 330, 'assign'],
            ['viva', 'Viva', 360, 'manual'],
            ['corrections', 'Corrections', 390, 'assign'],
        ];
        $phd = [
            ['concept', 'Topic and concept paper', 30, 'assign'],
            ['proposal', 'Proposal', 120, 'assign'],
            ['defence', 'Proposal defence', 150, 'manual'],
            ['ethics', 'Ethics and research permit', 180, 'assign'],
            ['litrev', 'Literature review', 270, 'assign'],
            ['ch123', 'Chapters 1 to 3', 330, 'assign'],
            ['review1', 'Annual progress review: year 1', 365, 'manual'],
            ['pubs', 'Publications log', 540, 'assign'],
            ['ch45', 'Chapters 4 and 5', 720, 'assign'],
            ['review2', 'Annual progress review: year 2', 730, 'manual'],
            ['predefence', 'Pre-submission seminar', 900, 'manual'],
            ['final', 'Final submission', 1000, 'assign'],
            ['viva', 'Viva', 1060, 'manual'],
            ['corrections', 'Corrections', 1120, 'assign'],
        ];
        $set = ['diploma' => $diploma, 'ug' => $ug, 'phd' => $phd][$level] ?? $masters;
        return array_map(fn($r) => [$r[0], $r[1], $r[2], 'all', $r[3]], $set);
    }

    /**
     * Create sections, (best effort) Assignments and stages; enable tracker. Safe to call once per course.
     * @return array summary: sections, activities, stages, errors
     */
    public static function build(int $courseid, bool $activities = true, string $level = 'masters'): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $out = ['sections' => 0, 'activities' => 0, 'stages' => 0, 'errors' => []];
        tracker::enable_course($courseid);
        $DB->set_field('local_researchcentral_course', 'level', $level, ['courseid' => $courseid]);
        if ($DB->record_exists('local_researchcentral_stage', ['courseid' => $courseid])) {
            return $out; // Already has stages: never duplicate.
        }
        $defs = self::definition($level);

        // Section 0: information and supervisor channel.
        try {
            $general = $DB->get_record('course_sections', ['course' => $courseid, 'section' => 0]);
            if ($general) {
                course_update_section($courseid, $general, [
                    'name' => 'Start here: research project information and supervisor channel',
                    'summary' => '<p>Guidelines, templates, and your supervisor discussion space.</p>',
                    'summaryformat' => FORMAT_HTML,
                ]);
            }
        } catch (\Throwable $e) {
            $out['errors'][] = 'general: ' . $e->getMessage();
        }

        $sortorder = 0;
        foreach ($defs as $i => [$key, $name, $days, $level, $kind]) {
            $sortorder++;
            $sectionnum = $i + 1;
            $cmid = 0;
            try {
                course_create_sections_if_missing($courseid, [$sectionnum]);
                $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionnum], '*', MUST_EXIST);
                course_update_section($courseid, $section, ['name' => $sectionnum . '. ' . $name]);
                $out['sections']++;
            } catch (\Throwable $e) {
                $out['errors'][] = "section $name: " . $e->getMessage();
                continue;
            }
            if ($activities && $kind === 'assign') {
                try {
                    $cmid = self::create_assignment($courseid, $sectionnum, $name);
                    $out['activities']++;
                } catch (\Throwable $e) {
                    $out['errors'][] = "assignment $name: " . $e->getMessage();
                }
            }
            $DB->insert_record('local_researchcentral_stage', (object)[
                'courseid' => $courseid, 'name' => $name, 'cmid' => $cmid, 'level' => $level,
                'sortorder' => $sortorder, 'defaultdays' => $days, 'timecreated' => time(),
            ]);
            $out['stages']++;
        }
        rebuild_course_cache($courseid, true);
        return $out;
    }

    /** @return int course module id */
    protected static function create_assignment(int $courseid, int $section, string $name): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');
        $course = get_course($courseid);
        $mi = (object)[
            'modulename' => 'assign', 'module' => $DB->get_field('modules', 'id', ['name' => 'assign'], MUST_EXIST),
            'course' => $courseid, 'section' => $section, 'visible' => 1, 'visibleoncoursepage' => 1,
            'name' => $name, 'intro' => '<p>Upload your ' . s(\core_text::strtolower($name)) . ' here.</p>',
            'introformat' => FORMAT_HTML,
            'introeditor' => ['text' => '<p>Upload your ' . s(\core_text::strtolower($name)) . ' here.</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
            'alwaysshowdescription' => 1, 'cmidnumber' => '',
            'submissiondrafts' => 0, 'requiresubmissionstatement' => 0, 'sendnotifications' => 0,
            'sendlatenotifications' => 0, 'sendstudentnotifications' => 1, 'duedate' => 0,
            'cutoffdate' => 0, 'gradingduedate' => 0, 'allowsubmissionsfromdate' => 0,
            'teamsubmission' => 0, 'requireallteammemberssubmit' => 0, 'teamsubmissiongroupingid' => 0,
            'blindmarking' => 0, 'hidegrader' => 0, 'markingworkflow' => 0, 'markingallocation' => 0,
            'attemptreopenmethod' => 'manual', 'maxattempts' => -1, 'preventsubmissionnotingroup' => 0,
            'grade' => 100, 'gradepass' => 50, 'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 1, 'assignsubmission_file_maxsizebytes' => 0,
            'assignsubmission_onlinetext_enabled' => 0, 'assignfeedback_comments_enabled' => 1,
            'assignfeedback_file_enabled' => 1, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1, 'completionpassgrade' => 1, 'completionview' => 0,
            'completionexpected' => 0, 'availabilityconditionsjson' => '{"op":"&","c":[],"showc":[]}',
            'groupmode' => 0, 'groupingid' => 0, 'showdescription' => 0,
        ];
        $cm = create_module($mi);
        return (int)$cm->coursemodule;
    }
}
