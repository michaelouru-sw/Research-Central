<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Adds "Research Central" to the course navigation for students, supervisors and managers.
 */
function local_researchcentral_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context) {
    global $USER;
    if (!\local_researchcentral\local\tracker::is_enabled((int)$course->id)) {
        return;
    }
    $roles = \local_researchcentral\local\tracker::get_roles((int)$USER->id, (int)$course->id);
    if (!$roles['manager'] && !$roles['supervisor'] && !$roles['student']) {
        return;
    }
    $navigation->add(
        get_string('pluginname', 'local_researchcentral'),
        new moodle_url('/local/researchcentral/index.php', ['id' => $course->id]),
        navigation_node::TYPE_CUSTOM,
        null,
        'researchcentral',
        new pix_icon('i/report', '')
    );
}

/**
 * Serves thesis submission files to the student, their supervisor and coordinators only.
 */
function local_researchcentral_pluginfile($course, $cm, $context, string $filearea, array $args, bool $forcedownload, array $options = []) {
    global $DB, $USER;
    if ($context->contextlevel != CONTEXT_SYSTEM || !in_array($filearea, ['submission', 'feedback', 'ethics_app', 'ethics_template', 'figure'], true)) {
        return false;
    }
    require_login(null, false);
    $itemid = (int)array_shift($args);
    if ($filearea === 'ethics_template') {
        // Committee templates: any signed-in thesis user may download.
        $proj = null;
        if (isguestuser()) {
            return false;
        }
        $filename = array_pop($args);
        $file = get_file_storage()->get_file($context->id, 'local_researchcentral', $filearea, 0, '/', $filename);
        if (!$file) {
            return false;
        }
        send_stored_file($file, 0, 0, true, $options);
    }
    if ($filearea === 'ethics_app') {
        $e = $DB->get_record('local_researchcentral_eth', ['id' => $itemid]);
        $proj = $e ? $DB->get_record('local_researchcentral_proj', ['id' => $e->projid]) : null;
        if (!$proj || !\local_researchcentral\local\portal::can_view($proj)) {
            return false;
        }
        $filename = array_pop($args);
        $file = get_file_storage()->get_file($context->id, 'local_researchcentral', $filearea, $itemid, '/', $filename);
        if (!$file) {
            return false;
        }
        send_stored_file($file, 0, 0, true, $options);
    }
    if ($filearea === 'figure') {
        $fig = $DB->get_record('local_researchcentral_fig', ['id' => $itemid]);
        $proj = $fig ? $DB->get_record('local_researchcentral_proj', ['id' => $fig->projid]) : null;
        if (!$proj || !\local_researchcentral\local\portal::can_view($proj)) {
            return false;
        }
        $filename = array_pop($args);
        $file = get_file_storage()->get_file($context->id, 'local_researchcentral', $filearea, $itemid, '/', $filename);
        if (!$file) {
            return false;
        }
        send_stored_file($file, 0, 0, $forcedownload, ['immutable' => false] + $options);
    }
    if ($filearea === 'submission') {
        $ver = $DB->get_record('local_researchcentral_ver', ['id' => $itemid]);
        $m = $ver ? $DB->get_record('local_researchcentral_pms', ['id' => $ver->pmsid]) : null;
        $proj = $m ? $DB->get_record('local_researchcentral_proj', ['id' => $m->projid]) : null;
        if (!$proj || !\local_researchcentral\local\portal::can_view($proj)) {
            return false;
        }
    } else {
        // Supervisor feedback files: staff always; the student only once the round's decision has been issued.
        $rev = $DB->get_record('local_researchcentral_review', ['id' => $itemid]);
        $proj = $rev ? $DB->get_record('local_researchcentral_proj', ['id' => $rev->projid]) : null;
        if (!$proj || !\local_researchcentral\local\portal::can_view($proj)) {
            return false;
        }
        $staff = \local_researchcentral\local\portal::is_project_supervisor($proj) || \local_researchcentral\local\portal::can_manage_project($proj);
        if (!$staff && !$DB->record_exists('local_researchcentral_decision', ['pmsid' => $rev->pmsid, 'round' => $rev->round])) {
            return false;
        }
    }
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, 'local_researchcentral', $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        return false;
    }
    // Download receipt: lets the other side see who has opened the file (and audit trail for staff downloads of student work).
    $first = \local_researchcentral\local\receipts::mark($filearea, $itemid, (int)$proj->id, (int)$USER->id);
    if ($first && $filearea === 'submission' && (int)$USER->id !== (int)$proj->userid) {
        \local_researchcentral\local\portal::log((int)$proj->id, 'download', $filename, (int)$USER->id);
    }
    send_stored_file($file, 0, 0, true, $options);
}
