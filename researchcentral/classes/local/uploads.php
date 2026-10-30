<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Upload rules. Thesis text is accepted only as .docx (the format the reader, checker and comment export can process);
 * figures and diagrams go in separate image slots. The size limit is set per stage by coordinators and can be
 * overridden per project by the supervisor or a coordinator, always inside the administrator's ceiling.
 */
class uploads {
    const IMAGE_EXTS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    const IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public static function site_default_mb(): int {
        return max(1, (int)(get_config('local_researchcentral', 'uploadmb') ?: 30));
    }

    public static function ceiling_mb(): int {
        return max(self::site_default_mb(), (int)(get_config('local_researchcentral', 'uploadmb_ceiling') ?: 100));
    }

    public static function figure_mb(): int {
        return max(1, (int)(get_config('local_researchcentral', 'figmb') ?: 5));
    }

    public static function max_figures(): int {
        return max(1, (int)(get_config('local_researchcentral', 'maxfigures') ?: 40));
    }

    /** Effective size limit (MB) for a document: project override, else stage setting, else site default; never above the ceiling. */
    public static function limit_mb(\stdClass $proj, \stdClass $stage): int {
        $mb = (int)($proj->maxmb ?? 0) > 0 ? (int)$proj->maxmb : ((int)($stage->maxmb ?? 0) > 0 ? (int)$stage->maxmb : self::site_default_mb());
        return max(1, min($mb, self::ceiling_mb()));
    }

    /** Where the effective limit comes from: project, stage or site. */
    public static function limit_source(\stdClass $proj, \stdClass $stage): string {
        return (int)($proj->maxmb ?? 0) > 0 ? 'project' : ((int)($stage->maxmb ?? 0) > 0 ? 'stage' : 'site');
    }

    /** The server's own upload cap in MB (PHP / Moodle); 0 when unlimited. */
    public static function server_cap_mb(): int {
        global $CFG;
        $b = get_max_upload_file_size($CFG->maxbytes ?? 0);
        return ($b > 0 && $b < PHP_INT_MAX / 2) ? (int)floor($b / 1048576) : 0;
    }

    public static function can_set_limit(\stdClass $proj): bool {
        return portal::is_project_supervisor($proj) || portal::can_manage_project($proj);
    }

    /** Set (or clear with 0) the project's own limit. */
    public static function set_project_limit(\stdClass $proj, int $mb): int {
        global $DB;
        if (!self::can_set_limit($proj)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'limit');
        }
        $mb = $mb <= 0 ? 0 : max(1, min($mb, self::ceiling_mb()));
        $DB->set_field('local_researchcentral_proj', 'maxmb', $mb, ['id' => $proj->id]);
        portal::log((int)$proj->id, 'sizelimit', $mb ? $mb . ' MB' : get_string('limit_default', 'local_researchcentral'));
        return $mb;
    }

    /** Returns an error string, or '' when the file is a genuine .docx within the limit. */
    public static function check_docx(string $path, string $name, int $size, int $limitmb): string {
        $L = fn($k, $a = null) => get_string($k, 'local_researchcentral', $a);
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'docx') {
            return $L('up_err_type', s($name));
        }
        if ($size <= 0) {
            return $L('up_err_empty', s($name));
        }
        if ($size > $limitmb * 1048576) {
            return $L('up_err_size', (object)['name' => s($name), 'size' => display_size($size), 'limit' => $limitmb]);
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return $L('up_err_notdocx', s($name));
        }
        $ok = $zip->locateName('word/document.xml') !== false && $zip->locateName('[Content_Types].xml') !== false;
        $total = 0;
        for ($i = 0; $i < min($zip->numFiles, 20000); $i++) {
            $total += (int)($zip->statIndex($i)['size'] ?? 0);
        }
        $zip->close();
        if (!$ok) {
            return $L('up_err_notdocx', s($name));
        }
        if ($total > 1024 * 1048576) {
            return $L('up_err_bomb', s($name));
        }
        return '';
    }

    /** Returns an error string, or '' when the file is a genuine image within the figure limit. */
    public static function check_image(string $path, string $name, int $size): string {
        $L = fn($k, $a = null) => get_string($k, 'local_researchcentral', $a);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::IMAGE_EXTS, true)) {
            return $L('up_err_imgtype', s($name));
        }
        if ($size > self::figure_mb() * 1048576) {
            return $L('up_err_size', (object)['name' => s($name), 'size' => display_size($size), 'limit' => self::figure_mb()]);
        }
        $info = @getimagesize($path);
        if (!$info || !in_array($info['mime'] ?? '', self::IMAGE_MIMES, true) || ($info[0] ?? 0) > 12000 || ($info[1] ?? 0) > 12000) {
            return $L('up_err_notimage', s($name));
        }
        return '';
    }

    /** Normalise a PHP $_FILES entry with name[] arrays into a list of single-file arrays (skipping empty slots). */
    public static function files(string $field): array {
        $out = [];
        if (empty($_FILES[$field]['name'])) {
            return $out;
        }
        foreach ((array)$_FILES[$field]['name'] as $i => $name) {
            $out[$i] = ['name' => (string)$name, 'tmp' => ((array)$_FILES[$field]['tmp_name'])[$i] ?? '', 'error' => ((array)$_FILES[$field]['error'])[$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => (int)(((array)$_FILES[$field]['size'])[$i] ?? 0)];
        }
        return $out;
    }

    public static function upload_error_text(int $code, string $name): string {
        $L = fn($k, $a = null) => get_string($k, 'local_researchcentral', $a);
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            return $L('up_err_server', (object)['name' => s($name), 'cap' => self::server_cap_mb() ?: '?']);
        }
        return $L('up_err_failed', s($name));
    }
}
