<?php
namespace local_researchcentral;

defined('MOODLE_INTERNAL') || die();

/**
 * Receipts: first view is recorded once, later views only count.
 *
 * @package local_researchcentral
 * @covers \local_researchcentral\local\receipts
 */
final class receipts_test extends \advanced_testcase {
    public function test_first_mark_is_true_then_false(): void {
        $this->resetAfterTest();
        $u = $this->getDataGenerator()->create_user();
        $this->assertTrue(local\receipts::mark('msg', 5, 1, (int)$u->id));
        $this->assertFalse(local\receipts::mark('msg', 5, 1, (int)$u->id));
        $rec = local\receipts::for_items('msg', [5])[5][(int)$u->id];
        $this->assertEquals(2, $rec->hits);
    }
}
