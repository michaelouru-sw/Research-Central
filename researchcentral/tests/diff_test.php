<?php
namespace local_researchcentral;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for the version comparison and feedback point splitting.
 *
 * @package local_researchcentral
 * @covers \local_researchcentral\local\diff
 * @covers \local_researchcentral\local\research
 */
final class diff_test extends \basic_testcase {
    public function test_identical_texts_have_no_changes(): void {
        $ops = local\diff::paragraphs(['A', 'B'], ['A', 'B']);
        $this->assertSame(['added' => 0, 'removed' => 0, 'changed' => 0], local\diff::stats($ops));
    }

    public function test_modified_paragraph_gets_word_level_changes(): void {
        $ops = local\diff::paragraphs(['One', 'The cat sat'], ['One', 'The dog sat', 'New']);
        $stats = local\diff::stats($ops);
        $this->assertSame(2, $stats['changed']);
        $this->assertSame(2, $stats['added']);     // "dog" and the new paragraph word.
        $this->assertSame(1, $stats['removed']);   // "cat".
    }

    public function test_too_large_middle_returns_null(): void {
        $a = array_map(fn($i) => "a$i", range(1, local\diff::MAX_MIDDLE + 1));
        $b = array_map(fn($i) => "b$i", range(1, local\diff::MAX_MIDDLE + 1));
        $this->assertNull(local\diff::paragraphs($a, $b));
    }

    public function test_feedback_points_split_on_numbers_bullets_and_blank_lines(): void {
        $c = "1. Fix the aim\n2) Clarify method\n- Add references\n\nImprove the abstract";
        $this->assertCount(4, local\research::items($c));
        $this->assertSame([], local\research::items("  \n"));
    }

    public function test_risk_levels(): void {
        $this->assertSame('low', local\risk::level(2));
        $this->assertSame('medium', local\risk::level(3));
        $this->assertSame('high', local\risk::level(5));
    }
}
