<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Hand an approved thesis to the institutional repository as a DSpace Simple Archive Format package
 * (dublin_core.xml, contents, the final document). Most repositories (DSpace, EPrints via import, Figshare bulk) accept SAF or the CSV.
 */
class repo {
    const MAX_BATCH = 40;

    public static function licenses(): array {
        return ['CC BY 4.0' => 'CC BY 4.0', 'CC BY-NC 4.0' => 'CC BY-NC 4.0', 'CC BY-NC-ND 4.0' => 'CC BY-NC-ND 4.0', 'All rights reserved' => get_string('repo_lic_arr', 'local_researchcentral')];
    }

    /** Completed projects in the viewer's scope with their handoff record. */
    public static function candidates(): array {
        global $DB;
        $where = '1 = 1';
        $params = [];
        $managed = portal::managed_schools();
        if ($managed !== null) {
            [$in, $params] = $DB->get_in_or_equal($managed ?: [-1], SQL_PARAMS_NAMED);
            $where = "g.schoolid $in";
        }
        return $DB->get_records_sql("SELECT p.id, p.userid, p.title, p.supervisorid, p.progid, g.name AS progname, g.level, g.schoolid,
                (SELECT MAX(m.timedecided) FROM {local_researchcentral_pms} m WHERE m.projid = p.id) AS completed,
                r.status AS rstatus, r.handle, r.license, r.embargo, r.timeexported
            FROM {local_researchcentral_proj} p JOIN {local_researchcentral_prog} g ON g.id = p.progid
       LEFT JOIN {local_researchcentral_repo} r ON r.projid = p.id
           WHERE $where
             AND (SELECT COUNT(1) FROM {local_researchcentral_pms} m WHERE m.projid = p.id) > 0
             AND (SELECT COUNT(1) FROM {local_researchcentral_pms} m WHERE m.projid = p.id AND m.status <> 'approved') = 0
        ORDER BY CASE WHEN r.status = 'exported' THEN 1 ELSE 0 END, completed DESC", $params, 0, 1000);
    }

    /** The document to deposit: latest version of the last approved stage that takes an upload. */
    public static function final_file(int $projid): ?array {
        global $DB;
        $m = $DB->get_records_sql("SELECT m.id FROM {local_researchcentral_pms} m JOIN {local_researchcentral_stage} s ON s.id = m.stageid
                WHERE m.projid = ? AND s.needsupload = 1 AND m.status = 'approved' ORDER BY s.sortorder DESC, s.id DESC", [$projid], 0, 1);
        if (!$m) {
            return null;
        }
        $v = review::latest_version((int)reset($m)->id);
        if (!$v) {
            return null;
        }
        $files = get_file_storage()->get_area_files(\context_system::instance()->id, 'local_researchcentral', 'submission', $v->id, 'filename', false);
        return $files ? [reset($files), $v] : null;
    }

    public static function save_meta(\stdClass $proj, string $license, int $embargo, string $handle): void {
        global $DB;
        portal::require_manage_project($proj);
        $license = array_key_exists($license, self::licenses()) ? $license : (string)portal::cfg('repo_license', 'CC BY-NC-ND 4.0');
        $now = time();
        $old = $DB->get_record('local_researchcentral_repo', ['projid' => $proj->id]);
        $row = (object)['projid' => $proj->id, 'license' => $license, 'embargo' => $embargo, 'handle' => \core_text::substr(trim($handle), 0, 250), 'timemodified' => $now];
        if ($old) {
            $row->id = $old->id;
            $DB->update_record('local_researchcentral_repo', $row);
        } else {
            $row->status = 'ready';
            $row->note = '';
            $row->exportedby = 0;
            $row->timeexported = 0;
            $row->timecreated = $now;
            $DB->insert_record('local_researchcentral_repo', $row);
        }
    }

    private static function xml(string $schema, array $vals): string {
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<dublin_core schema="' . $schema . '">' . "\n";
        foreach ($vals as [$el, $q, $v, $lang]) {
            if ($v === '' || $v === null) {
                continue;
            }
            $x .= '  <dcvalue element="' . $el . '" qualifier="' . $q . '"' . ($lang ? ' language="' . $lang . '"' : '') . '>' . htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</dcvalue>' . "\n";
        }
        return $x . '</dublin_core>' . "\n";
    }

    /**
     * Build a SAF zip for the given project ids (all must be in the viewer's scope and complete). Returns [path, exported ids, skipped [id => reason]].
     */
    public static function build(array $projids): array {
        global $DB, $SITE, $USER;
        $zipfile = make_request_directory() . '/thesis_repository_export_' . date('Ymd_His') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipfile, \ZipArchive::CREATE);
        $ok = [];
        $skipped = [];
        $csv = [['id', 'dc.title', 'dc.contributor.author', 'dc.contributor.advisor', 'dc.date.issued', 'dc.type', 'dc.rights', 'dc.date.embargo', 'thesis.degree.name', 'file']];
        $tmpfiles = [];
        $cands = self::candidates();
        foreach (array_slice($projids, 0, self::MAX_BATCH) as $pid) {
            $p = $DB->get_record('local_researchcentral_proj', ['id' => $pid]);
            if (!$p || !isset($cands[$pid])) {
                $skipped[$pid] = 'scope';
                continue;
            }
            portal::require_manage_project($p);
            $ff = self::final_file((int)$pid);
            if (!$ff) {
                $skipped[$pid] = 'nofile';
                continue;
            }
            [$file, $ver] = $ff;
            $c = $cands[$pid];
            $meta = $DB->get_record('local_researchcentral_repo', ['projid' => $pid]);
            $lic = $meta->license ?? (string)portal::cfg('repo_license', 'CC BY-NC-ND 4.0');
            $emb = (int)($meta->embargo ?? 0);
            $stu = \core_user::get_user($p->userid);
            $author = $stu ? $stu->lastname . ', ' . $stu->firstname : '-';
            $advisors = [];
            foreach (portal::supervisor_ids($p) as $sid) {
                $u = \core_user::get_user($sid);
                if ($u) {
                    $advisors[] = $u->lastname . ', ' . $u->firstname;
                }
            }
            $school = $c->schoolid ? (string)$DB->get_field('local_researchcentral_school', 'name', ['id' => $c->schoolid]) : '';
            $issued = date('Y-m-d', (int)$c->completed);
            $vals = [['title', 'none', format_string($p->title), 'en'], ['contributor', 'author', $author, ''], ['date', 'issued', $issued, ''],
                ['type', 'none', 'Thesis', 'en'], ['language', 'iso', 'en', ''], ['publisher', 'none', format_string($SITE->fullname) . ($school !== '' ? ', ' . $school : ''), 'en'],
                ['rights', 'none', $lic, 'en']];
            foreach ($advisors as $a) {
                $vals[] = ['contributor', 'advisor', $a, ''];
            }
            if ($emb > time()) {
                $vals[] = ['date', 'embargoed', date('Y-m-d', $emb), ''];
            }
            $dir = 'item_' . str_pad((string)$pid, 5, '0', STR_PAD_LEFT) . '/';
            $zip->addFromString($dir . 'dublin_core.xml', self::xml('dc', $vals));
            $zip->addFromString($dir . 'metadata_thesis.xml', str_replace('<dublin_core schema="dc">', '<dublin_core schema="thesis">', self::xml('thesis', [
                ['degree', 'name', format_string($c->progname), 'en'], ['degree', 'level', get_string('level_' . $c->level, 'local_researchcentral'), 'en'], ['degree', 'grantor', format_string($SITE->fullname), 'en']])));
            $fname = clean_filename($file->get_filename());
            $tmp = $file->copy_content_to_temp('researchcentral');
            $tmpfiles[] = $tmp;
            $zip->addFile($tmp, $dir . $fname);
            $zip->addFromString($dir . 'contents', $fname . "\tbundle:ORIGINAL" . ($emb > time() ? "\tpermissions:-r 'Administrator'" : '') . "\n");
            $csv[] = [$pid, format_string($p->title), $author, implode('||', $advisors), $issued, 'Thesis', $lic, $emb > time() ? date('Y-m-d', $emb) : '', format_string($c->progname), $dir . $fname];
            $ok[] = (int)$pid;
        }
        $h = fopen('php://temp', 'w+');
        foreach ($csv as $row) {
            fputcsv($h, $row);
        }
        rewind($h);
        $zip->addFromString('index.csv', "\xEF\xBB\xBF" . stream_get_contents($h));
        fclose($h);
        $zip->close();
        foreach ($tmpfiles as $t) {
            @unlink($t);
        }
        $now = time();
        foreach ($ok as $pid) {
            $r = $DB->get_record('local_researchcentral_repo', ['projid' => $pid]);
            if ($r) {
                $DB->update_record('local_researchcentral_repo', (object)['id' => $r->id, 'status' => 'exported', 'exportedby' => $USER->id, 'timeexported' => $now, 'timemodified' => $now]);
            } else {
                $DB->insert_record('local_researchcentral_repo', (object)['projid' => $pid, 'status' => 'exported', 'handle' => '', 'license' => (string)portal::cfg('repo_license', 'CC BY-NC-ND 4.0'),
                    'embargo' => 0, 'note' => '', 'exportedby' => $USER->id, 'timeexported' => $now, 'timecreated' => $now, 'timemodified' => $now]);
            }
            portal::log($pid, 'repository', get_string('repo_exported_log', 'local_researchcentral'));
        }
        return [$zipfile, $ok, $skipped];
    }
}
