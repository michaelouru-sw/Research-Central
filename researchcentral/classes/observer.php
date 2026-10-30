<?php
namespace local_researchcentral;

defined('MOODLE_INTERNAL') || die();

/**
 * Keeps milestones in step with activity completion, and queues document checks on file submissions.
 */
class observer {
    public static function completion_updated(\core\event\course_module_completion_updated $event): void {
        if (empty($event->relateduserid) || empty($event->courseid)) {
            return;
        }
        if (!\local_researchcentral\local\tracker::is_enabled((int)$event->courseid)) {
            return;
        }
        \local_researchcentral\local\tracker::sync_for_user((int)$event->courseid, (int)$event->relateduserid);
    }

    /** A student saved a file in an Assignment: queue a document check if the stage has rules. */
    public static function submission_changed(\core\event\base $event): void {
        global $DB;
        $context = $event->get_context();
        if (!$context || $context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $sub = $DB->get_record('assign_submission', ['id' => $event->objectid], 'id, userid');
        if (!$sub || empty($sub->userid)) {
            return;
        }
        $courseid = (int)$event->courseid;
        if (!\local_researchcentral\local\tracker::is_enabled($courseid)) {
            return;
        }
        $cmid = (int)$context->instanceid;
        if (!$DB->record_exists('local_researchcentral_thesis', ['courseid' => $courseid, 'userid' => $sub->userid])) {
            return;
        }
        $sql = "SELECT 1 FROM {local_researchcentral_stage} s
                  JOIN {local_researchcentral_rules} r ON r.stageid = s.id
                 WHERE s.courseid = :c AND s.cmid = :cm";
        if (!$DB->record_exists_sql($sql, ['c' => $courseid, 'cm' => $cmid])) {
            return;
        }
        $task = new \local_researchcentral\task\check_document();
        $task->set_component('local_researchcentral');
        $task->set_custom_data(['courseid' => $courseid, 'userid' => (int)$sub->userid, 'cmid' => $cmid]);
        $task->set_next_run_time(time() + 120); // Let the student finish uploading.
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
