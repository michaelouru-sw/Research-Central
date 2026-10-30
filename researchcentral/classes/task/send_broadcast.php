<?php
namespace local_researchcentral\task;

defined('MOODLE_INTERNAL') || die();

/** Delivers a queued broadcast in batches, re-queueing itself until every recipient has been handled. */
class send_broadcast extends \core\task\adhoc_task {
    const BATCH = 200;

    public function execute(): void {
        global $DB;
        $d = $this->get_custom_data();
        if (empty($d->bcastid)) {
            return;
        }
        \local_researchcentral\local\comms::process((int)$d->bcastid, self::BATCH);
        if ($DB->record_exists('local_researchcentral_bcastu', ['bcastid' => $d->bcastid, 'status' => 'pending'])) {
            $next = new self();
            $next->set_custom_data(['bcastid' => (int)$d->bcastid]);
            \core\task\manager::queue_adhoc_task($next);
        }
    }
}
