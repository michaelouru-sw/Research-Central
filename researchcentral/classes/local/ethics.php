<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Ethics review: the student applies, the committee decides, a letter is issued and the permit tracker is updated.
 * Committee = people holding local/researchcentral:ethicsreview (system level) plus coordinators of the student's school.
 */
class ethics {
    public static function statuses(): array {
        return ['submitted', 'approved', 'conditional', 'rejected', 'withdrawn'];
    }

    public static function is_committee(?int $userid = null): bool {
        global $USER;
        $userid = $userid ?? (int)$USER->id;
        return is_siteadmin($userid) || has_capability('local/researchcentral:ethicsreview', \context_system::instance(), $userid);
    }

    /** May this user decide on this project's application? */
    public static function can_decide(\stdClass $proj, ?int $userid = null): bool {
        return self::is_committee($userid) || portal::can_manage_project($proj, $userid);
    }

    public static function committee_ids(\stdClass $proj): array {
        $ids = array_keys(get_users_by_capability(\context_system::instance(), 'local/researchcentral:ethicsreview', 'u.id'));
        foreach (portal::coordinator_ids($proj) as $c) {
            $ids[] = (int)$c;
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    const EXTS = ['docx'];

    /** Store uploaded files from a plain multipart input (name[]) in a plugin file area; returns the number saved. */
    public static function save_uploads(string $field, string $filearea, int $itemid, int $max = 5): int {
        $fs = get_file_storage();
        $ctx = \context_system::instance();
        $limit = uploads::site_default_mb() * 1048576;
        $saved = 0;
        if (empty($_FILES[$field]['name'])) {
            return 0;
        }
        $names = (array)$_FILES[$field]['name'];
        foreach ($names as $i => $orig) {
            $tmp = (array)$_FILES[$field]['tmp_name'];
            $err = (array)$_FILES[$field]['error'];
            $size = (array)$_FILES[$field]['size'];
            if ($saved >= $max || $orig === '' || ($err[$i] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp[$i])) {
                continue;
            }
            $name = clean_param($orig, PARAM_FILE);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($name === '' || !in_array($ext, self::EXTS, true) || $size[$i] > $limit || uploads::check_docx($tmp[$i], $name, (int)$size[$i], (int)ceil($limit / 1048576)) !== '') {
                continue;
            }
            $rec = ['contextid' => $ctx->id, 'component' => 'local_researchcentral', 'filearea' => $filearea, 'itemid' => $itemid, 'filepath' => '/', 'filename' => $name];
            if ($fs->file_exists($ctx->id, 'local_researchcentral', $filearea, $itemid, '/', $name)) {
                $fs->get_file($ctx->id, 'local_researchcentral', $filearea, $itemid, '/', $name)->delete();
            }
            $fs->create_file_from_pathname($rec, $tmp[$i]);
            $saved++;
        }
        return $saved;
    }

    /** @return \stored_file[] */
    public static function files(string $filearea, int $itemid): array {
        return array_values(get_file_storage()->get_area_files(\context_system::instance()->id, 'local_researchcentral', $filearea, $itemid, 'filename', false));
    }

    public static function file_url(\stored_file $f): \moodle_url {
        return \moodle_url::make_pluginfile_url($f->get_contextid(), 'local_researchcentral', $f->get_filearea(), $f->get_itemid(), $f->get_filepath(), $f->get_filename(), true);
    }

    /** Download links for a file area, as html. */
    public static function file_links(string $filearea, int $itemid): string {
        $out = [];
        foreach (self::files($filearea, $itemid) as $f) {
            $out[] = \html_writer::link(self::file_url($f), '⬇ ' . s($f->get_filename()) . ' (' . display_size($f->get_filesize()) . ')');
        }
        return implode('<br>', $out);
    }

    public static function delete_file(string $filearea, int $itemid, string $filename): void {
        $f = get_file_storage()->get_file(\context_system::instance()->id, 'local_researchcentral', $filearea, $itemid, '/', $filename);
        if ($f) {
            $f->delete();
        }
    }

    public static function for_project(int $projid): array {
        global $DB;
        return $DB->get_records('local_researchcentral_eth', ['projid' => $projid], 'timecreated DESC, id DESC');
    }

    public static function submit(\stdClass $proj, string $title, string $summary, string $risk): int {
        global $DB, $USER;
        if ((int)$proj->userid !== (int)$USER->id) {
            throw new \moodle_exception('nopermissions', 'error', '', 'ethics');
        }
        $title = \core_text::substr(trim($title), 0, 250);
        if ($title === '' || trim($summary) === '') {
            return 0;
        }
        $risk = in_array($risk, ['low', 'medium', 'high'], true) ? $risk : 'low';
        $now = time();
        $id = $DB->insert_record('local_researchcentral_eth', (object)['projid' => $proj->id, 'permitid' => 0, 'title' => $title, 'summary' => trim($summary),
            'risk' => $risk, 'ref' => '', 'status' => 'submitted', 'decisionnote' => '', 'decidedby' => 0, 'timesubmitted' => $now, 'timedecided' => 0,
            'timecreated' => $now, 'timemodified' => $now]);
        self::save_uploads('eattach', 'ethics_app', (int)$id);
        $DB->set_field('local_researchcentral_eth', 'ref', 'ETH-' . date('Y') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT), ['id' => $id]);
        portal::log((int)$proj->id, 'ethics', $title);
        $a = (object)['student' => fullname($USER), 'title' => $title];
        foreach (array_unique(array_merge(self::committee_ids($proj), portal::supervisor_ids($proj))) as $u) {
            portal::notify((int)$u, get_string('msg_eth_new_subject', 'local_researchcentral', $a), get_string('msg_eth_new_body', 'local_researchcentral', $a),
                new \moodle_url('/local/researchcentral/ethics.php', ['id' => $id]), 'workflow');
        }
        return $id;
    }

    public static function withdraw(\stdClass $proj, int $id): void {
        global $DB, $USER;
        $e = $DB->get_record('local_researchcentral_eth', ['id' => $id, 'projid' => $proj->id], '*', MUST_EXIST);
        if ((int)$proj->userid === (int)$USER->id && $e->status === 'submitted') {
            $DB->update_record('local_researchcentral_eth', (object)['id' => $id, 'status' => 'withdrawn', 'timemodified' => time()]);
        }
    }

    /** Record the committee decision, update the permit tracker, tell the student and the supervisors. */
    public static function decide(\stdClass $proj, int $id, string $decision, string $note, int $expiry): bool {
        global $DB, $USER;
        if (!self::can_decide($proj) || !in_array($decision, ['approved', 'conditional', 'rejected'], true)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'ethics');
        }
        $e = $DB->get_record('local_researchcentral_eth', ['id' => $id, 'projid' => $proj->id], '*', MUST_EXIST);
        if ($e->status !== 'submitted') {
            return false;
        }
        $now = time();
        $tx = $DB->start_delegated_transaction();
        $permitid = (int)$e->permitid;
        if ($decision !== 'rejected') {
            $row = (object)['projid' => $proj->id, 'type' => 'ethics', 'reference' => $e->ref, 'authority' => get_string('eth_committee', 'local_researchcentral'),
                'status' => 'approved', 'issued' => $now, 'expiry' => $expiry, 'note' => $decision === 'conditional' ? $note : '', 'remind' => 0, 'timemodified' => $now];
            if ($permitid && $DB->record_exists('local_researchcentral_permit', ['id' => $permitid])) {
                $row->id = $permitid;
                $DB->update_record('local_researchcentral_permit', $row);
            } else {
                $row->timecreated = $now;
                $permitid = $DB->insert_record('local_researchcentral_permit', $row);
            }
        }
        $DB->update_record('local_researchcentral_eth', (object)['id' => $id, 'status' => $decision, 'decisionnote' => $note, 'decidedby' => $USER->id,
            'timedecided' => $now, 'permitid' => $permitid, 'timemodified' => $now]);
        $tx->allow_commit();
        portal::log((int)$proj->id, 'ethics', get_string('eth_st_' . $decision, 'local_researchcentral') . ': ' . $e->ref);
        $a = (object)['title' => $e->title, 'decision' => get_string('eth_st_' . $decision, 'local_researchcentral'), 'ref' => $e->ref, 'note' => $note];
        foreach (array_unique(array_merge([(int)$proj->userid], portal::supervisor_ids($proj))) as $u) {
            portal::notify((int)$u, get_string('msg_eth_dec_subject', 'local_researchcentral', $a), get_string('msg_eth_dec_body', 'local_researchcentral', $a),
                research::url((int)$proj->id, 'ethics'), 'workflow');
        }
        return true;
    }

    /** Applications waiting for the committee (for a committee member's queue). */
    public static function queue(): array {
        global $DB;
        $rows = $DB->get_records_sql("SELECT e.*, p.userid, p.progid, p.title AS projtitle FROM {local_researchcentral_eth} e
                                        JOIN {local_researchcentral_proj} p ON p.id = e.projid
                                    ORDER BY CASE WHEN e.status = 'submitted' THEN 0 ELSE 1 END, e.timesubmitted DESC", [], 0, 500);
        $out = [];
        foreach ($rows as $r) {
            if (self::can_decide((object)['id' => $r->projid, 'progid' => $r->progid, 'userid' => $r->userid])) {
                $out[] = $r;
            }
        }
        return $out;
    }
}
