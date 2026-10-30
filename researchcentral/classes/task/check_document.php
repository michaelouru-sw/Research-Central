<?php
namespace local_researchcentral\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Ad hoc task queued when a thesis student saves a file in a linked Assignment.
 */
class check_document extends \core\task\adhoc_task {
    public function execute(): void {
        $d = $this->get_custom_data();
        if (empty($d->courseid) || empty($d->userid) || empty($d->cmid)) {
            return;
        }
        \local_researchcentral\checker\runner::check_for((int)$d->courseid, (int)$d->userid, (int)$d->cmid, true);
    }
}
