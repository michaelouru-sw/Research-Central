<?php
namespace local_researchcentral\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;

/**
 * Privacy metadata, export and deletion. Academic records (submissions, reviews, decisions, meetings) are retained for
 * institutional and quality-assurance purposes; personal discussion text is erased and receipts are deleted on request.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_researchcentral_thesis', [
            'userid' => 'privacy:metadata:thesis:userid',
            'supervisorid' => 'privacy:metadata:thesis:supervisorid',
            'title' => 'privacy:metadata:thesis:title',
            'level' => 'privacy:metadata:thesis:level',
            'startdate' => 'privacy:metadata:thesis:startdate',
        ], 'privacy:metadata:thesis');
        $collection->add_database_table('local_researchcentral_ms', [
            'duedate' => 'privacy:metadata:ms:duedate',
            'status' => 'privacy:metadata:ms:status',
            'timecompleted' => 'privacy:metadata:ms:timecompleted',
        ], 'privacy:metadata:ms');
        $collection->add_database_table('local_researchcentral_report', [
            'userid' => 'privacy:metadata:report:userid',
            'filename' => 'privacy:metadata:report:filename',
            'results' => 'privacy:metadata:report:results',
            'timecreated' => 'privacy:metadata:report:timecreated',
        ], 'privacy:metadata:report');
        $collection->add_database_table('local_researchcentral_thread', [
            'userid' => 'privacy:metadata:thread:userid', 'subject' => 'privacy:metadata:thread:subject',
            'message' => 'privacy:metadata:thread:message', 'timecreated' => 'privacy:metadata:thread:timecreated',
        ], 'privacy:metadata:thread');
        $collection->add_database_table('local_researchcentral_post', [
            'userid' => 'privacy:metadata:post:userid', 'message' => 'privacy:metadata:post:message',
            'timecreated' => 'privacy:metadata:post:timecreated',
        ], 'privacy:metadata:post');
        $collection->add_database_table('local_researchcentral_bcast', [
            'userid' => 'privacy:metadata:bcast:userid', 'subject' => 'privacy:metadata:bcast:subject',
            'body' => 'privacy:metadata:bcast:body',
        ], 'privacy:metadata:bcast');
        $collection->add_database_table('local_researchcentral_review', [
            'userid' => 'privacy:metadata:review:userid', 'comment' => 'privacy:metadata:review:comment',
            'confidential' => 'privacy:metadata:review:confidential', 'grade' => 'privacy:metadata:review:grade',
            'timecreated' => 'privacy:metadata:review:timecreated',
        ], 'privacy:metadata:review');
        $collection->add_database_table('local_researchcentral_appt', [
            'supervisorid' => 'privacy:metadata:appt:supervisorid', 'studentid' => 'privacy:metadata:appt:studentid',
            'starttime' => 'privacy:metadata:appt:starttime', 'agenda' => 'privacy:metadata:appt:agenda',
            'studentconf' => 'privacy:metadata:appt:attendance',
        ], 'privacy:metadata:appt');
        $collection->add_database_table('local_researchcentral_cosup', [
            'userid' => 'privacy:metadata:cosup:userid',
        ], 'privacy:metadata:cosup');
        $collection->add_database_table('local_researchcentral_receipt', [
            'userid' => 'privacy:metadata:receipt:userid', 'kind' => 'privacy:metadata:receipt:kind',
            'firsttime' => 'privacy:metadata:receipt:firsttime', 'lasttime' => 'privacy:metadata:receipt:lasttime',
        ], 'privacy:metadata:receipt');
        foreach (['checkin' => 'checkin', 'snip' => 'snip', 'eventreg' => 'eventreg'] as $t => $k) {
            $collection->add_database_table('local_researchcentral_' . $t, ['userid' => 'privacy:metadata:' . $k . ':userid'], 'privacy:metadata:' . $k);
        }
        $collection->add_database_table('local_researchcentral_ann', ['userid' => 'privacy:metadata:ann:userid', 'body' => 'privacy:metadata:ann:body'], 'privacy:metadata:ann');
        $collection->add_database_table('local_researchcentral_meet', ['createdby' => 'privacy:metadata:meet:createdby', 'agenda' => 'privacy:metadata:meet:agenda',
            'minutes' => 'privacy:metadata:meet:minutes', 'meetdate' => 'privacy:metadata:meet:meetdate'], 'privacy:metadata:meet');
        $collection->add_database_table('local_researchcentral_mact', ['ownerid' => 'privacy:metadata:mact:ownerid', 'descr' => 'privacy:metadata:mact:descr',
            'duedate' => 'privacy:metadata:mact:duedate'], 'privacy:metadata:mact');
        $collection->add_database_table('local_researchcentral_fig', ['userid' => 'privacy:metadata:fig:userid', 'caption' => 'privacy:metadata:fig:caption'], 'privacy:metadata:fig');
        $collection->add_database_table('local_researchcentral_eth', ['title' => 'privacy:metadata:eth:title', 'summary' => 'privacy:metadata:eth:summary',
            'status' => 'privacy:metadata:eth:status'], 'privacy:metadata:eth');
        $collection->add_database_table('local_researchcentral_exam', ['name' => 'privacy:metadata:exam:name', 'email' => 'privacy:metadata:exam:email',
            'institution' => 'privacy:metadata:exam:institution', 'report' => 'privacy:metadata:exam:report'], 'privacy:metadata:exam');
        $collection->add_database_table('local_researchcentral_doc', ['issuedby' => 'privacy:metadata:doc:issuedby', 'code' => 'privacy:metadata:doc:code'], 'privacy:metadata:doc');
        $collection->add_database_table('local_researchcentral_pub', ['title' => 'privacy:metadata:pub:title'], 'privacy:metadata:pub');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:messages');
        return $collection;
    }

    /** Everything is stored in the system context. */
    public static function get_contexts_for_userid(int $userid): \core_privacy\local\request\contextlist {
        $cl = new \core_privacy\local\request\contextlist();
        global $DB;
        $has = $DB->record_exists('local_researchcentral_proj', ['userid' => $userid]) || $DB->record_exists('local_researchcentral_msg', ['userid' => $userid])
            || $DB->record_exists('local_researchcentral_review', ['userid' => $userid]) || $DB->record_exists('local_researchcentral_receipt', ['userid' => $userid]) || $DB->record_exists('local_researchcentral_ann', ['userid' => $userid]) || $DB->record_exists('local_researchcentral_meet', ['createdby' => $userid])
            || $DB->record_exists('local_researchcentral_mact', ['ownerid' => $userid])
            || $DB->record_exists('local_researchcentral_thread', ['userid' => $userid]) || $DB->record_exists('local_researchcentral_post', ['userid' => $userid])
            || $DB->record_exists_select('local_researchcentral_appt', 'studentid = ? OR supervisorid = ?', [$userid, $userid]);
        if ($has) {
            $cl->add_system_context();
        }
        return $cl;
    }

    public static function get_users_in_context(\core_privacy\local\request\userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_researchcentral_proj}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_researchcentral_msg}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_researchcentral_review}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_researchcentral_receipt}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_researchcentral_ann} WHERE userid > 0', []);
        $userlist->add_from_sql('userid', 'SELECT createdby AS userid FROM {local_researchcentral_meet}', []);
        $userlist->add_from_sql('userid', 'SELECT ownerid AS userid FROM {local_researchcentral_mact}', []);
    }

    public static function export_user_data(\core_privacy\local\request\approved_contextlist $contextlist): void {
        global $DB;
        $ctx = \context_system::instance();
        $uid = (int)$contextlist->get_user()->id;
        if (!in_array($ctx->id, $contextlist->get_contextids())) {
            return;
        }
        $dt = fn($t) => \core_privacy\local\request\transform::datetime((int)$t);
        $w = \core_privacy\local\request\writer::with_context($ctx);
        $sets = [
            'theses' => $DB->get_records('local_researchcentral_proj', ['userid' => $uid], '', 'id, title, progid, supervisorid, timecreated'),
            'messages' => array_map(fn($r) => (object)['project' => $r->projid, 'message' => $r->body, 'time' => $dt($r->timecreated)],
                $DB->get_records('local_researchcentral_msg', ['userid' => $uid])),
            'reviews' => array_map(fn($r) => (object)['project' => $r->projid, 'recommendation' => $r->recommendation, 'comment' => $r->comment,
                'confidential' => $r->confidential, 'time' => $dt($r->timecreated)], $DB->get_records('local_researchcentral_review', ['userid' => $uid])),
            'meetings' => array_map(fn($r) => (object)['start' => $dt($r->starttime), 'end' => $dt($r->endtime), 'status' => $r->status,
                'agenda' => $r->agenda], $DB->get_records_select('local_researchcentral_appt', 'studentid = ? OR supervisorid = ?', [$uid, $uid])),
            'forum_questions' => array_map(fn($r) => (object)['subject' => $r->subject, 'message' => $r->message, 'time' => $dt($r->timecreated)],
                $DB->get_records('local_researchcentral_thread', ['userid' => $uid])),
            'forum_replies' => array_map(fn($r) => (object)['message' => $r->message, 'time' => $dt($r->timecreated)],
                $DB->get_records('local_researchcentral_post', ['userid' => $uid])),
            'checkins' => array_map(fn($r) => (object)['period' => $r->period, 'progress' => $r->progress, 'plans' => $r->plans, 'blockers' => $r->blockers],
                $DB->get_records('local_researchcentral_checkin', ['userid' => $uid])),
            'comment_bank' => array_map(fn($r) => (object)['title' => $r->title, 'body' => $r->body], $DB->get_records('local_researchcentral_snip', ['userid' => $uid])),
            'event_registrations' => array_map(fn($r) => (object)['event' => $r->eventid, 'status' => $r->status], $DB->get_records('local_researchcentral_eventreg', ['userid' => $uid])),
            'document_comments' => array_map(fn($r) => (object)['version' => $r->verid, 'quote' => $r->quote, 'comment' => $r->body, 'status' => $r->status, 'time' => $dt($r->timecreated)],
                $DB->get_records('local_researchcentral_ann', ['userid' => $uid])),
            'supervision_meetings' => array_map(fn($r) => (object)['project' => $r->projid, 'date' => $dt($r->meetdate), 'agenda' => $r->agenda, 'minutes' => $r->minutes],
                $DB->get_records_sql('SELECT m.* FROM {local_researchcentral_meet} m JOIN {local_researchcentral_proj} p ON p.id = m.projid WHERE p.userid = ? OR m.createdby = ?', [$uid, $uid])),
            'meeting_actions' => array_map(fn($r) => (object)['project' => $r->projid, 'action' => $r->descr, 'due' => $dt($r->duedate), 'status' => $r->status],
                $DB->get_records('local_researchcentral_mact', ['ownerid' => $uid])),
            'ethics_applications' => array_map(fn($r) => (object)['reference' => $r->ref, 'title' => $r->title, 'summary' => $r->summary, 'status' => $r->status, 'decision_note' => $r->decisionnote],
                $DB->get_records_sql('SELECT e.* FROM {local_researchcentral_eth} e JOIN {local_researchcentral_proj} p ON p.id = e.projid WHERE p.userid = ?', [$uid])),
            'receipts' => array_map(fn($r) => (object)['kind' => $r->kind, 'item' => $r->itemid, 'first' => $dt($r->firsttime), 'last' => $dt($r->lasttime),
                'times' => $r->hits], $DB->get_records('local_researchcentral_receipt', ['userid' => $uid])),
        ];
        foreach ($sets as $name => $rows) {
            if ($rows) {
                $w->export_data([get_string('pluginname', 'local_researchcentral'), $name], (object)['records' => array_values((array)$rows)]);
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        // Academic records are retained; only discussion text and receipts are erased.
        global $DB;
        if ($context instanceof \context_system) {
            $DB->delete_records('local_researchcentral_receipt');
            $DB->set_field('local_researchcentral_msg', 'body', '[deleted]');
            $DB->set_field('local_researchcentral_ann', 'body', '[deleted]');
            $DB->set_field('local_researchcentral_ann', 'quote', '');
            $DB->set_field('local_researchcentral_meet', 'agenda', '[deleted]');
        }
    }

    public static function delete_data_for_user(\core_privacy\local\request\approved_contextlist $contextlist): void {
        global $DB;
        if (!in_array(\context_system::instance()->id, $contextlist->get_contextids())) {
            return;
        }
        $uid = (int)$contextlist->get_user()->id;
        $DB->delete_records('local_researchcentral_receipt', ['userid' => $uid]);
        $DB->delete_records('local_researchcentral_snip', ['userid' => $uid]);
        $DB->delete_records('local_researchcentral_eventreg', ['userid' => $uid]);
        $DB->set_field('local_researchcentral_msg', 'body', '[deleted]', ['userid' => $uid]);
        $DB->set_field('local_researchcentral_ann', 'body', '[deleted]', ['userid' => $uid]);
        $DB->set_field('local_researchcentral_ann', 'quote', '', ['userid' => $uid]);
        $DB->set_field('local_researchcentral_meet', 'agenda', '[deleted]', ['createdby' => $uid]);
        $DB->set_field('local_researchcentral_thread', 'message', '[deleted]', ['userid' => $uid]);
        $DB->set_field('local_researchcentral_post', 'message', '[deleted]', ['userid' => $uid]);
    }

    public static function delete_data_for_users(\core_privacy\local\request\approved_userlist $userlist): void {
        global $DB;
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        [$in, $p] = $DB->get_in_or_equal($userlist->get_userids());
        $DB->delete_records_select('local_researchcentral_receipt', "userid $in", $p);
        $DB->set_field_select('local_researchcentral_msg', 'body', '[deleted]', "userid $in", $p);
        $DB->set_field_select('local_researchcentral_thread', 'message', '[deleted]', "userid $in", $p);
        $DB->set_field_select('local_researchcentral_post', 'message', '[deleted]', "userid $in", $p);
        $DB->set_field_select('local_researchcentral_ann', 'body', '[deleted]', "userid $in", $p);
        $DB->set_field_select('local_researchcentral_ann', 'quote', '', "userid $in", $p);
        $DB->set_field_select('local_researchcentral_meet', 'agenda', '[deleted]', "createdby $in", $p);
    }
}
