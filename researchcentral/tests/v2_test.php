<?php
namespace local_researchcentral;

use local_researchcentral\local\annotate;
use local_researchcentral\local\docs;
use local_researchcentral\local\ethics;
use local_researchcentral\local\examiner;
use local_researchcentral\local\leadership;
use local_researchcentral\local\meetings;
use local_researchcentral\local\portal;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the v2.0 features: annotation placement, meetings, generated documents, ethics, external examiners and leadership figures.
 *
 * @package local_researchcentral
 * @covers \local_researchcentral\local\annotate
 * @covers \local_researchcentral\local\meetings
 * @covers \local_researchcentral\local\docs
 * @covers \local_researchcentral\local\ethics
 * @covers \local_researchcentral\local\examiner
 * @covers \local_researchcentral\local\leadership
 */
final class v2_test extends \advanced_testcase {
    /** @return array [project record, student, supervisor] */
    private function project(): array {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $stu = $gen->create_user();
        $sup = $gen->create_user();
        $sid = $DB->insert_record('local_researchcentral_school', (object)['name' => 'School', 'timecreated' => time()]);
        $pid = $DB->insert_record('local_researchcentral_prog', (object)['name' => 'MSc', 'school' => 'School', 'schoolid' => $sid, 'level' => 'masters', 'timecreated' => time()]);
        portal::seed_stages($pid, 'masters');
        $projid = portal::create_project((int)$stu->id, (int)$sup->id, $pid, 'A thesis', time() - DAYSECS);
        return [$DB->get_record('local_researchcentral_proj', ['id' => $projid]), $stu, $sup];
    }

    public function test_paragraph_hash_ignores_case_and_spacing(): void {
        $this->assertSame(annotate::hash("Hello   World"), annotate::hash(" hello world "));
        $this->assertNotSame(annotate::hash('a'), annotate::hash('b'));
    }

    public function test_comment_follows_unchanged_text_and_orphans_when_text_changes(): void {
        $c = (object)['id' => 1, 'verid' => 1, 'paraidx' => 0, 'parahash' => annotate::hash('Aim of study'), 'status' => 'open'];
        [$by, $orph] = annotate::place(['Intro', 'Aim of study'], [1 => $c], 2);
        $this->assertArrayHasKey(1, $by);
        $this->assertSame([], $orph);
        [$by, $orph] = annotate::place(['Intro', 'A different aim'], [1 => $c], 2);
        $this->assertSame([], $by);
        $this->assertCount(1, $orph);
        $c->status = 'resolved';
        [$by, $orph] = annotate::place(['Intro'], [1 => $c], 2);
        $this->assertSame([], $orph, 'Resolved threads are not carried forward');
    }

    public function test_only_the_student_or_the_creator_may_edit_a_meeting_and_students_cannot_write_minutes(): void {
        global $DB;
        [$proj, $stu, $sup] = $this->project();
        $this->setUser($sup);
        $id = meetings::save($proj, 0, time(), 'online', 'Agenda', 'Minutes', 'held');
        $this->setUser($stu);
        try {
            meetings::save($proj, $id, time(), 'online', 'Changed', 'Forged', 'held');
            $this->fail('Student edited a meeting they did not create');
        } catch (\moodle_exception $e) {
            $this->assertSame('Minutes', $DB->get_field('local_researchcentral_meet', 'minutes', ['id' => $id]));
        }
        $mine = meetings::save($proj, 0, time(), 'phone', 'My agenda', 'Ignored minutes', 'held');
        $row = $DB->get_record('local_researchcentral_meet', ['id' => $mine]);
        $this->assertSame('', $row->minutes);
        $this->assertSame('planned', $row->status);
    }

    public function test_overdue_actions_are_counted_and_done_actions_are_not(): void {
        [$proj, $stu, $sup] = $this->project();
        $this->setUser($sup);
        $mid = meetings::save($proj, 0, time(), 'online', '', 'Notes', 'held');
        $aid = meetings::add_action($proj, $mid, (int)$stu->id, 'Rewrite section', time() - DAYSECS);
        $this->assertSame([(int)$proj->id => 1], meetings::overdue_counts([(int)$proj->id]));
        $this->setUser($stu);
        $this->assertTrue(meetings::set_action($proj, $aid, true));
        $this->assertSame([], meetings::overdue_counts([(int)$proj->id]));
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(meetings::set_action($proj, $aid, false), 'Outsiders cannot change actions');
    }

    public function test_actions_can_only_go_to_people_on_the_project(): void {
        [$proj, , $sup] = $this->project();
        $this->setUser($sup);
        $mid = meetings::save($proj, 0, time(), 'online', '', '', 'planned');
        $stranger = $this->getDataGenerator()->create_user();
        $this->assertSame(0, meetings::add_action($proj, $mid, (int)$stranger->id, 'x', 0));
    }

    public function test_document_codes_are_stable_except_for_progress_reports(): void {
        [$proj, , $sup] = $this->project();
        $this->setUser($sup);
        $a = docs::issue($proj, 'supcert', 'sup:' . $sup->id);
        $b = docs::issue($proj, 'supcert', 'sup:' . $sup->id);
        $this->assertSame($a->code, $b->code);
        $this->assertNotSame(docs::issue($proj, 'progress')->code, docs::issue($proj, 'progress')->code);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{12}$/', $a->code);
    }

    public function test_ethics_approval_creates_a_permit_and_is_final(): void {
        global $DB;
        [$proj, $stu] = $this->project();
        $this->setUser($stu);
        $id = ethics::submit($proj, 'Study', 'Summary of study', 'low');
        $this->assertGreaterThan(0, $id);
        $this->setAdminUser();
        $this->assertTrue(ethics::decide($proj, $id, 'approved', 'Fine', time() + YEARSECS));
        $this->assertSame(1, $DB->count_records('local_researchcentral_permit', ['projid' => $proj->id, 'type' => 'ethics', 'status' => 'approved']));
        $this->assertFalse(ethics::decide($proj, $id, 'rejected', 'Changed my mind', 0), 'A decided application cannot be decided again');
    }

    public function test_only_the_student_can_apply_for_ethics(): void {
        [$proj, , $sup] = $this->project();
        $this->setUser($sup);
        $this->expectException(\moodle_exception::class);
        ethics::submit($proj, 'Study', 'Summary', 'low');
    }

    public function test_examiner_link_validity(): void {
        global $DB;
        [$proj, , $sup] = $this->project();
        $this->setUser($sup);
        $pms = (int)$DB->get_field_sql('SELECT MIN(id) FROM {local_researchcentral_pms} WHERE projid = ?', [$proj->id]);
        $id = examiner::invite($proj, $pms, 'Prof X', 'x@example.org', 'Univ', 7);
        $this->assertGreaterThan(0, $id);
        $ex = $DB->get_record('local_researchcentral_exam', ['id' => $id]);
        $this->assertSame(48, strlen($ex->token));
        $this->assertNotNull(examiner::by_token($ex->token));
        $this->assertNull(examiner::by_token('nonsense'));
        $DB->set_field('local_researchcentral_exam', 'expiry', time() - 1, ['id' => $id]);
        $this->assertNull(examiner::by_token($ex->token), 'Expired links stop working');
        $DB->set_field('local_researchcentral_exam', 'expiry', time() + 100, ['id' => $id]);
        examiner::revoke($proj, $id);
        $this->assertNull(examiner::by_token($ex->token), 'Revoked links stop working');
        $this->assertStringContainsString('Prof X', examiner::author_name(-$id));
    }

    public function test_examiner_invitation_rejects_bad_email_and_other_projects_stages(): void {
        global $DB;
        [$proj, , $sup] = $this->project();
        $this->setUser($sup);
        $pms = (int)$DB->get_field_sql('SELECT MIN(id) FROM {local_researchcentral_pms} WHERE projid = ?', [$proj->id]);
        $this->assertSame(0, examiner::invite($proj, $pms, 'Prof X', 'not-an-email', '', 7));
        $this->expectException(\dml_missing_record_exception::class);
        examiner::invite($proj, $pms + 99999, 'Prof X', 'x@example.org', '', 7);
    }

    public function test_leadership_periods_and_median(): void {
        [$from, $to] = leadership::period('2026-Q2');
        $this->assertSame('2026-04-01', date('Y-m-d', $from));
        $this->assertSame('2026-06-30', date('Y-m-d', $to));
        [$from, $to] = leadership::period('2025');
        $this->assertSame('2025-01-01', date('Y-m-d', $from));
        $this->assertSame('2025-12-31', date('Y-m-d', $to));
        $m = new \ReflectionMethod(leadership::class, 'median');
        $m->setAccessible(true);
        $this->assertSame(2.0, $m->invoke(null, [3, 1, 2]));
        $this->assertSame(2.5, $m->invoke(null, [1, 2, 3, 4]));
        $this->assertNull($m->invoke(null, []));
    }
}
