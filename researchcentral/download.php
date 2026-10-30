<?php
// Download a submitted document together with its comments: Word comments in the .docx, or a PDF report.
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_researchcentral\local\annotate;
use local_researchcentral\local\commented;
use local_researchcentral\local\portal;

$verid = required_param('ver', PARAM_INT);
$fmt = optional_param('fmt', 'pdf', PARAM_ALPHA);
$inc = optional_param('inc', 'all', PARAM_ALPHA);
$content = optional_param('content', 'whole', PARAM_ALPHA);

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/researchcentral/download.php', ['ver' => $verid]));
$ver = $DB->get_record('local_researchcentral_ver', ['id' => $verid], '*', MUST_EXIST);
$m = $DB->get_record('local_researchcentral_pms', ['id' => $ver->pmsid], '*', MUST_EXIST);
$proj = $DB->get_record('local_researchcentral_proj', ['id' => $m->projid], '*', MUST_EXIST);
portal::require_view($proj);   // Same rule as reading: student, their supervisors, coordinators.
$staff = annotate::is_staff($proj);
$stage = $DB->get_record('local_researchcentral_stage', ['id' => $m->stageid]);
$student = core_user::get_user($proj->userid);
$file = commented::file($verid);
if (!$file) {
    throw new moodle_exception('compare_unreadable', 'local_researchcentral');
}
$isdocx = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)) === 'docx';
raise_memory_limit(MEMORY_EXTRA);
core_php_time_limit::raise(180);

// Only comments this person may see (supervisors-only ones are staff-only).
$all = annotate::for_stage($proj, (int)$m->id, $staff);
$roots = [];
$replies = [];
foreach ($all as $c) {
    if ($c->parentid) {
        $replies[(int)$c->parentid][] = $c;
    } else if ($inc !== 'open' || $c->status !== annotate::RESOLVED) {
        $roots[(int)$c->id] = $c;
    }
}
$paras = annotate::paragraphs($verid);
[$byPara, $orphans] = annotate::place($paras, $roots, $verid);
foreach ($roots as $c) {
    if ((int)$c->figid) {
        $orphans[] = $c;
    }
}

$names = [];
$nameof = fn(int $u): string => \local_researchcentral\local\examiner::author_name($u);
$base = clean_filename(format_string($stage->name) . '_' . fullname($student) . '_v' . $ver->version . '_comments');
portal::log((int)$proj->id, 'download', get_string('dl_title', 'local_researchcentral') . ' ' . strtoupper($fmt));

if ($fmt === 'docx' && ($isdocx || $paras)) {
    // A .docx keeps its own layout; PDF and text files are rebuilt from their extracted text.
    $src = $isdocx ? $file->copy_content_to_temp('researchcentral') : commented::plain_docx($paras);
    $tmp = commented::docx($src, $byPara, $orphans, $replies, $nameof);
    if ($isdocx) {
        @unlink($src);
    }
    send_temp_file($tmp, $base . '.docx');
}
$pdf = commented::pdf((object)['title' => format_string($proj->title ?: $stage->name), 'student' => fullname($student), 'stage' => format_string($stage->name),
    'version' => get_string('submission_v', 'local_researchcentral', $ver->version), 'who' => fullname($USER), 'staffcopy' => $staff],
    $paras, $byPara, $orphans, $replies, $nameof, $content === 'commented');
$tmp = make_request_directory() . '/' . $base . '.pdf';
file_put_contents($tmp, $pdf);
send_temp_file($tmp, $base . '.pdf');
