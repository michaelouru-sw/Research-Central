<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/** Calendar integration: iCal download, "add to Google Calendar" link and Moodle calendar events. */
class cal {
    public static function title(\stdClass $a): string {
        $stu = $a->studentid ? \core_user::get_user($a->studentid) : null;
        return get_string('appt_event_title', 'local_researchcentral', $stu ? fullname($stu) : get_string('appt_open', 'local_researchcentral'));
    }

    public static function where(\stdClass $a): string {
        return trim((string)$a->joinurl) !== '' ? (string)$a->joinurl : (string)$a->location;
    }

    public static function description(\stdClass $a): string {
        $bits = [];
        if (trim((string)$a->topic) !== '') {
            $bits[] = get_string('book_topic', 'local_researchcentral') . ': ' . $a->topic;
        }
        if (trim((string)$a->agenda) !== '') {
            $bits[] = get_string('appt_agenda', 'local_researchcentral') . ': ' . $a->agenda;
        }
        if (trim((string)$a->joinurl) !== '') {
            $bits[] = get_string('appt_join', 'local_researchcentral') . ': ' . $a->joinurl;
        }
        return implode("\n", $bits);
    }

    private static function esc(string $t): string {
        return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\;', '\,', '\n', '\n', '\n'], $t);
    }

    public static function ics(\stdClass $a): string {
        global $CFG;
        $fmt = fn($t) => gmdate('Ymd\THis\Z', (int)$t);
        $uid = 'researchcentral-' . $a->id . '@' . (parse_url($CFG->wwwroot, PHP_URL_HOST) ?: 'moodle');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Research Central//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT', 'UID:' . $uid, 'DTSTAMP:' . $fmt(time()), 'DTSTART:' . $fmt($a->starttime), 'DTEND:' . $fmt($a->endtime),
            'SUMMARY:' . self::esc(self::title($a)), 'DESCRIPTION:' . self::esc(self::description($a)),
            'LOCATION:' . self::esc(self::where($a)), 'URL:' . (new \moodle_url('/local/researchcentral/appointments.php'))->out(false),
            'BEGIN:VALARM', 'TRIGGER:-PT30M', 'ACTION:DISPLAY', 'DESCRIPTION:' . self::esc(self::title($a)), 'END:VALARM',
            'END:VEVENT', 'END:VCALENDAR'];
        return implode("\r\n", $lines) . "\r\n";
    }

    public static function google_url(\stdClass $a): string {
        $fmt = fn($t) => gmdate('Ymd\THis\Z', (int)$t);
        return 'https://calendar.google.com/calendar/render?' . http_build_query([
            'action' => 'TEMPLATE', 'text' => self::title($a), 'dates' => $fmt($a->starttime) . '/' . $fmt($a->endtime),
            'details' => self::description($a), 'location' => self::where($a)], '', '&', PHP_QUERY_RFC3986);
    }

    /** Keep the Moodle calendar of both people in step with a booked appointment (best effort). */
    public static function sync_events(\stdClass $a): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/calendar/lib.php');
        if ($a->status !== 'booked' || !$a->studentid) {
            self::drop_events($a);
            return;
        }
        foreach (['eventstu' => (int)$a->studentid, 'eventsup' => (int)$a->supervisorid] as $field => $uid) {
            try {
                $props = (object)['name' => self::title($a), 'description' => nl2br(s(self::description($a))), 'format' => FORMAT_HTML,
                    'courseid' => 0, 'groupid' => 0, 'userid' => $uid, 'modulename' => '', 'instance' => 0, 'eventtype' => 'user',
                    'timestart' => (int)$a->starttime, 'timeduration' => (int)$a->endtime - (int)$a->starttime, 'visible' => 1,
                    'component' => 'local_researchcentral', 'type' => CALENDAR_EVENT_TYPE_STANDARD];
                if (!empty($a->$field) && $DB->record_exists('event', ['id' => $a->$field])) {
                    \calendar_event::load((int)$a->$field)->update($props, false);
                } else {
                    $ev = \calendar_event::create($props, false);
                    if ($ev) {
                        $DB->set_field('local_researchcentral_appt', $field, $ev->id, ['id' => $a->id]);
                        $a->$field = $ev->id;
                    }
                }
            } catch (\Throwable $e) {
                debugging('Research Central calendar sync: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    public static function drop_events(\stdClass $a): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/calendar/lib.php');
        foreach (['eventstu', 'eventsup'] as $field) {
            if (empty($a->$field)) {
                continue;
            }
            try {
                if ($DB->record_exists('event', ['id' => $a->$field])) {
                    \calendar_event::load((int)$a->$field)->delete(true);
                }
            } catch (\Throwable $e) {
                debugging('Research Central calendar drop: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            $DB->set_field('local_researchcentral_appt', $field, 0, ['id' => $a->id]);
            $a->$field = 0;
        }
    }
}
