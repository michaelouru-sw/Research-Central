<?php
namespace local_researchcentral\local;

use local_researchcentral\checker\docx_reader;
use local_researchcentral\checker\pdf_reader;

defined('MOODLE_INTERNAL') || die();

/**
 * Inline comments on submitted documents. A comment is anchored to a paragraph of one version (with a hash of
 * the paragraph text, so open comments can follow unchanged text into the next version). Staff start threads,
 * the student replies and marks them addressed, staff resolve them.
 */
class annotate {
    const OPEN = 'open';
    const ADDRESSED = 'addressed';
    const RESOLVED = 'resolved';

    /** Staff = anyone supervising the project, or managing it. */
    public static function is_staff(\stdClass $proj, ?int $userid = null): bool {
        return portal::is_project_supervisor($proj, $userid) || portal::can_manage_project($proj, $userid);
    }

    public static function hash(string $text): string {
        return sha1(\core_text::strtolower(preg_replace('/\s+/u', ' ', trim($text))));
    }

    /** Non-empty paragraph texts of a stored submission, or null when the format cannot be read. */
    public static function paragraphs(int $verid): ?array {
        $ctx = \context_system::instance();
        $files = get_file_storage()->get_area_files($ctx->id, 'local_researchcentral', 'submission', $verid, 'filename', false);
        $f = $files ? reset($files) : null;
        if (!$f || $f->get_filesize() > (int)(get_config('local_researchcentral', 'maxfilemb') ?: 30) * 1048576) {
            return null;
        }
        $ext = strtolower(pathinfo($f->get_filename(), PATHINFO_EXTENSION));
        if ($ext === 'txt') {
            $raw = $f->get_content();
            $out = [];
            foreach (preg_split('/\R{2,}/u', (string)$raw) as $p) {
                $p = trim($p);
                if ($p !== '') {
                    $out[] = $p;
                }
            }
            return $out;
        }
        $tmp = $f->copy_content_to_temp('researchcentral');
        if (!$tmp) {
            return null;
        }
        try {
            $doc = $ext === 'docx' ? docx_reader::read($tmp)
                : ($ext === 'pdf' ? pdf_reader::read($tmp, (string)get_config('local_researchcentral', 'pdftotextpath')) : null);
            if (!$doc) {
                return null;
            }
            $out = [];
            foreach ($doc->paragraphs as $p) {
                $t = trim(is_array($p) ? ($p['text'] ?? '') : (string)($p->text ?? ''));
                if ($t !== '') {
                    $out[] = $t;
                }
            }
            return $out;
        } finally {
            @unlink($tmp);
        }
    }

    /** All comments of a stage the viewer may see, oldest first. Private ones are staff-only. */
    public static function for_stage(\stdClass $proj, int $pmsid, bool $staff): array {
        global $DB;
        $sql = 'pmsid = :p' . ($staff ? '' : ' AND private = 0');
        return $DB->get_records_select('local_researchcentral_ann', $sql, ['p' => $pmsid], 'timecreated, id');
    }


    /**
     * Where each root comment shows in a version: [paragraph index => roots], plus roots from earlier versions whose
     * paragraph text has changed or gone. Resolved threads from earlier versions are not carried forward.
     */
    public static function place(?array $paras, array $roots, int $verid): array {
        $hashidx = [];
        foreach ($paras ?? [] as $i => $t) {
            $hashidx[self::hash($t)] ??= $i;
        }
        $by = [];
        $orphans = [];
        foreach ($roots as $c) {
            if ((int)($c->figid ?? 0)) {
                continue;
            }
            if ((int)$c->verid === $verid) {
                $by[(int)$c->paraidx][] = $c;
            } else if ($c->status !== self::RESOLVED && (int)$c->verid < $verid) {
                if (isset($hashidx[$c->parahash])) {
                    $by[$hashidx[$c->parahash]][] = $c;
                } else {
                    $orphans[] = $c;
                }
            }
        }
        return [$by, $orphans];
    }

    /** Open (not resolved) root comments per version for the viewer: [verid => count]. */
    public static function open_counts(array $pmsids, bool $staff): array {
        global $DB;
        $out = [];
        if (!$pmsids) {
            return $out;
        }
        [$in, $p] = $DB->get_in_or_equal(array_map('intval', $pmsids), SQL_PARAMS_NAMED);
        $sql = "SELECT verid, COUNT(id) AS n FROM {local_researchcentral_ann}
                 WHERE pmsid $in AND parentid = 0 AND status <> 'resolved'" . ($staff ? '' : ' AND private = 0') . ' GROUP BY verid';
        foreach ($DB->get_records_sql($sql, $p) as $r) {
            $out[(int)$r->verid] = (int)$r->n;
        }
        return $out;
    }

    public static function add_root(\stdClass $proj, \stdClass $ver, int $idx, string $hash, string $quote, string $body, bool $private, int $figid = 0): int {
        global $DB, $USER;
        if (!self::is_staff($proj)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'comment');
        }
        $body = trim($body);
        if ($body === '') {
            return 0;
        }
        $now = time();
        $id = $DB->insert_record('local_researchcentral_ann', (object)['projid' => $proj->id, 'pmsid' => $ver->pmsid, 'verid' => $ver->id,
            'parentid' => 0, 'paraidx' => $idx, 'parahash' => $hash, 'quote' => \core_text::substr(trim($quote), 0, 250),
            'userid' => $USER->id, 'body' => $body, 'private' => $private ? 1 : 0, 'status' => self::OPEN,
            'timecreated' => $now, 'timemodified' => $now, 'figid' => $figid]);
        self::tell($proj, (int)$ver->pmsid, (int)$ver->id, $private);
        portal::log((int)$proj->id, 'comment', get_string('submission_v', 'local_researchcentral', $ver->version));
        return $id;
    }

    public static function add_reply(\stdClass $proj, \stdClass $root, string $body): int {
        global $DB, $USER;
        $staff = self::is_staff($proj);
        if (!$staff && (int)$proj->userid !== (int)$USER->id) {
            throw new \moodle_exception('nopermissions', 'error', '', 'comment');
        }
        if (!$staff && $root->private) {
            throw new \moodle_exception('nopermissions', 'error', '', 'comment');
        }
        $body = trim($body);
        if ($body === '' || $root->parentid) {
            return 0;
        }
        $now = time();
        $id = $DB->insert_record('local_researchcentral_ann', (object)['projid' => $proj->id, 'pmsid' => $root->pmsid, 'verid' => $root->verid,
            'parentid' => $root->id, 'paraidx' => $root->paraidx, 'parahash' => $root->parahash, 'quote' => '',
            'userid' => $USER->id, 'body' => $body, 'private' => $root->private, 'status' => self::OPEN,
            'timecreated' => $now, 'timemodified' => $now]);
        $DB->set_field('local_researchcentral_ann', 'timemodified', $now, ['id' => $root->id]);
        self::tell($proj, (int)$root->pmsid, (int)$root->verid, (bool)$root->private);
        return $id;
    }

    /** Student: open -> addressed. Staff: any -> open / addressed / resolved. */
    public static function set_status(\stdClass $proj, \stdClass $root, string $to): bool {
        global $DB, $USER;
        if ($root->parentid || !in_array($to, [self::OPEN, self::ADDRESSED, self::RESOLVED], true)) {
            return false;
        }
        $staff = self::is_staff($proj);
        $student = (int)$proj->userid === (int)$USER->id && !$root->private;
        if (!$staff && !($student && $to === self::ADDRESSED && $root->status === self::OPEN)) {
            return false;
        }
        $DB->update_record('local_researchcentral_ann', (object)['id' => $root->id, 'status' => $to, 'timemodified' => time()]);
        if ($to === self::ADDRESSED && !$staff) {
            self::tell($proj, (int)$root->pmsid, (int)$root->verid, false);
        }
        return true;
    }

    /** Authors delete their own comments; deleting a thread root removes its replies. */
    public static function delete(\stdClass $proj, \stdClass $c): bool {
        global $DB, $USER;
        if ((int)$c->userid !== (int)$USER->id && !portal::can_manage_project($proj)) {
            return false;
        }
        $DB->delete_records('local_researchcentral_receipt', ['kind' => 'ann', 'itemid' => $c->id]);
        if (!$c->parentid) {
            $kids = $DB->get_fieldset_select('local_researchcentral_ann', 'id', 'parentid = ?', [$c->id]);
            foreach ($kids as $k) {
                $DB->delete_records('local_researchcentral_receipt', ['kind' => 'ann', 'itemid' => $k]);
            }
            $DB->delete_records('local_researchcentral_ann', ['parentid' => $c->id]);
        }
        $DB->delete_records('local_researchcentral_ann', ['id' => $c->id]);
        return true;
    }

    /** Notify the other side, at most once per 15 minutes per author and version, so a review pass is one message. */
    private static function tell(\stdClass $proj, int $pmsid, int $verid, bool $private): void {
        global $DB, $USER;
        $recent = $DB->count_records_select('local_researchcentral_ann', 'verid = ? AND userid = ? AND timecreated > ?', [$verid, $USER->id, time() - 900]);
        if ($recent > 1) {
            return;
        }
        $to = array_values(array_filter(array_merge([(int)$proj->userid], portal::supervisor_ids($proj)), function ($u) use ($USER, $private, $proj) {
            return (int)$u !== (int)$USER->id && !($private && (int)$u === (int)$proj->userid);
        }));
        $stage = $DB->get_field_sql('SELECT s.name FROM {local_researchcentral_pms} m JOIN {local_researchcentral_stage} s ON s.id = m.stageid WHERE m.id = ?', [$pmsid]);
        $a = (object)['who' => fullname($USER), 'stage' => format_string((string)$stage), 'title' => format_string($proj->title)];
        $url = new \moodle_url('/local/researchcentral/read.php', ['ver' => $verid]);
        foreach ($to as $uid) {
            portal::notify($uid, get_string('msg_ann_subject', 'local_researchcentral', $a), get_string('msg_ann_body', 'local_researchcentral', $a), $url, 'workflow');
        }
    }

    /** Record that the viewer has seen the other people's comments in this list. */
    public static function mark_seen(array $comments, int $projid, int $userid): void {
        global $DB;
        $ids = [];
        foreach ($comments as $c) {
            if ((int)$c->userid !== $userid) {
                $ids[] = (int)$c->id;
            }
        }
        if (!$ids) {
            return;
        }
        [$in, $p] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $have = $DB->get_fieldset_select('local_researchcentral_receipt', 'itemid', "kind = 'ann' AND userid = :u AND itemid $in", ['u' => $userid] + $p);
        foreach (array_diff($ids, array_map('intval', $have)) as $id) {
            receipts::mark('ann', $id, $projid, $userid);
        }
    }

    /** Ticks for a comment you wrote: who has seen it. */
    public static function seen_line(\stdClass $proj, \stdClass $c, array $seen, callable $name): string {
        $dt = fn(int $t) => userdate($t, get_string('strftimedatetimeshort', 'langconfig'));
        $rcpt = receipts::recipients($proj, (int)$c->userid);
        if ($c->private) {
            $rcpt = array_values(array_filter($rcpt, fn($u) => (int)$u !== (int)$proj->userid));
        }
        if (!$rcpt) {
            return '';
        }
        $got = array_values(array_filter($rcpt, fn($u) => isset($seen[$u])));
        $state = !$got ? 'sent' : (count($got) < count($rcpt) ? 'partial' : 'all');
        $parts = [];
        foreach ($rcpt as $u) {
            $parts[] = isset($seen[$u])
                ? get_string('rcpt_seen_by', 'local_researchcentral', (object)['name' => $name($u), 'when' => $dt((int)$seen[$u]->firsttime)])
                : get_string('rcpt_not_yet', 'local_researchcentral', $name($u));
        }
        $title = s(implode("\n", $parts));
        return \html_writer::span(\html_writer::span($state === 'sent' ? '✓' : '✓✓', 'tt-tick tt-tick-' . $state, ['title' => $title, 'aria-label' => $title, 'role' => 'img']), 'tt-rcpt-line');
    }

    // ---- Comment to review item ----

    /** Queue (or unqueue) one of the supervisor's public root comments for their next review of this stage. */
    public static function set_queued(\stdClass $proj, \stdClass $root, bool $on): bool {
        global $DB, $USER;
        if ($root->parentid || $root->private || !self::is_staff($proj) || (int)$root->reviewid) {
            return false;
        }
        $m = $DB->get_record_sql('SELECT m.*, s.needsupload, s.maxgrade FROM {local_researchcentral_pms} m JOIN {local_researchcentral_stage} s ON s.id = m.stageid WHERE m.id = ?', [$root->pmsid]);
        if (!$m || !review::can_review($proj, $m)) {
            return false;
        }
        $DB->set_field('local_researchcentral_ann', 'queued', $on ? (int)$USER->id : 0, ['id' => $root->id]);
        return true;
    }

    /** Queued comments of this reviewer on a stage, in document order. */
    public static function queued(int $pmsid, int $userid): array {
        global $DB;
        return $DB->get_records_select('local_researchcentral_ann', 'pmsid = ? AND queued = ? AND parentid = 0 AND reviewid = 0', [$pmsid, $userid], 'verid, paraidx, id');
    }

    /** One line per queued comment, ready to append to a review comment (numbered after the points already there). */
    public static function queued_text(int $pmsid, int $userid, string $existing): string {
        $q = self::queued($pmsid, $userid);
        if (!$q) {
            return $existing;
        }
        $n = count(research::items($existing));
        $out = rtrim($existing);
        foreach ($q as $c) {
            $line = trim(preg_replace('/\s+/u', ' ', $c->body));
            if ($out !== '' && strpos($out, $line) !== false) {
                continue;
            }
            $n++;
            $out .= ($out === '' ? '' : "\n") . $n . '. ' . '(' . get_string('ann_para_ref', 'local_researchcentral', (int)$c->paraidx + 1) . ') ' . $line;
        }
        return $out;
    }

    /** After a review is saved: link queued comments to the numbered point they became, then clear the queue. */
    public static function link_queued(int $reviewid, int $pmsid, int $userid, string $comment): void {
        global $DB;
        $items = research::items($comment);
        foreach (self::queued($pmsid, $userid) as $c) {
            $line = trim(preg_replace('/\s+/u', ' ', $c->body));
            foreach ($items as $i => $it) {
                if ($line !== '' && strpos(preg_replace('/\s+/u', ' ', $it), $line) !== false) {
                    $DB->update_record('local_researchcentral_ann', (object)['id' => $c->id, 'reviewid' => $reviewid, 'itemno' => $i + 1, 'timemodified' => time()]);
                    break;
                }
            }
        }
        $DB->set_field_select('local_researchcentral_ann', 'queued', 0, 'pmsid = ? AND queued = ?', [$pmsid, $userid]);
    }
}
