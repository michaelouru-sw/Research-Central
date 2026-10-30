<?php
namespace local_researchcentral\task;

defined('MOODLE_INTERNAL') || die();

/** Every 15 minutes: 24h and 1h meeting reminders, and requests to confirm that a meeting took place. */
class appointment_reminders extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task_appt_reminders', 'local_researchcentral');
    }

    public function execute(): void {
        \local_researchcentral\local\appt::run_reminders();
    }
}
