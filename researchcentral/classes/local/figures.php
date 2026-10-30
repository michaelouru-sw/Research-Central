<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/** Figures and diagrams: stored per submitted version, separate from the thesis text, each with its own comment thread. */
class figures {
    public static function for_version(int $verid): array {
        global $DB;
        return $DB->get_records('local_researchcentral_fig', ['verid' => $verid], 'slot, id');
    }

    public static function file(\stdClass $fig): ?\stored_file {
        $fs = get_file_storage();
        $files = $fs->get_area_files(\context_system::instance()->id, 'local_researchcentral', 'figure', $fig->id, 'filename', false);
        return $files ? reset($files) : null;
    }

    public static function url(\stdClass $fig, bool $download = false): ?\moodle_url {
        $f = self::file($fig);
        return $f ? \moodle_url::make_pluginfile_url($f->get_contextid(), 'local_researchcentral', 'figure', $fig->id, '/', $f->get_filename(), $download) : null;
    }

    /**
     * Create the figure records for a new version. $items: list of
     * ['caption'=>, 'alt'=>, 'location'=>, 'tmp'=> new file path or '', 'name'=>, 'copyfrom'=> existing figure id or 0].
     */
    public static function save_all(\stdClass $proj, \stdClass $m, int $verid, array $items): int {
        global $DB, $USER;
        $fs = get_file_storage();
        $ctx = \context_system::instance();
        $slot = 0;
        $n = 0;
        foreach ($items as $it) {
            $src = null;
            if (!empty($it['copyfrom'])) {
                $old = $DB->get_record('local_researchcentral_fig', ['id' => (int)$it['copyfrom'], 'projid' => $proj->id, 'pmsid' => $m->id]);
                $src = $old ? self::file($old) : null;
            }
            if (empty($it['tmp']) && !$src) {
                continue;
            }
            $slot++;
            $name = $src ? $src->get_filename() : clean_param($it['name'], PARAM_FILE);
            $id = $DB->insert_record('local_researchcentral_fig', (object)['projid' => $proj->id, 'pmsid' => $m->id, 'verid' => $verid, 'slot' => $slot,
                'caption' => \core_text::substr(trim($it['caption'] ?? ''), 0, 250), 'alt' => \core_text::substr(trim($it['alt'] ?? ''), 0, 250),
                'location' => \core_text::substr(trim($it['location'] ?? ''), 0, 250), 'filename' => $name, 'userid' => $USER->id, 'timecreated' => time()]);
            $rec = ['contextid' => $ctx->id, 'component' => 'local_researchcentral', 'filearea' => 'figure', 'itemid' => $id, 'filepath' => '/', 'filename' => $name];
            if (!empty($it['tmp'])) {
                $fs->create_file_from_pathname($rec, $it['tmp']);
            } else {
                $fs->create_file_from_storedfile($rec, $src);
            }
            $n++;
        }
        return $n;
    }

    /** Root comments on figures of a version, grouped by figure id. */
    public static function comments(array $roots): array {
        $by = [];
        foreach ($roots as $c) {
            if ((int)$c->figid) {
                $by[(int)$c->figid][] = $c;
            }
        }
        return $by;
    }
}
