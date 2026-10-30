<?php
namespace local_researchcentral\task;

defined('MOODLE_INTERNAL') || die();

/** Ad hoc: run the document checker on an uploaded version. */
class check_version extends \core\task\adhoc_task {
    public function execute(): void {
        $d = $this->get_custom_data();
        if (!empty($d->verid)) {
            \local_researchcentral\checker\portal_runner::check_version((int)$d->verid);
        }
    }
}
