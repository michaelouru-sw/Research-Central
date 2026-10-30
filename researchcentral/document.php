<?php
// Generate a PDF (viva invitation, progress report, supervision certificate, completion letter) with a verification QR code.
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_researchcentral\local\docs;
use local_researchcentral\local\portal;

$id = required_param('id', PARAM_INT);
$kind = required_param('kind', PARAM_ALPHA);
$who = optional_param('who', 0, PARAM_INT);

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
require_sesskey();
$proj = $DB->get_record('local_researchcentral_proj', ['id' => $id], '*', MUST_EXIST);
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/researchcentral/document.php', ['id' => $id, 'kind' => $kind]));
portal::require_view($proj);
if (!in_array($kind, docs::allowed($proj), true)) {
    throw new moodle_exception('nopermissions', 'error', '', 'document');
}
$detail = '';
if ($kind === 'supcert') {
    $team = portal::supervisor_ids($proj);
    $who = in_array($who, $team, true) ? $who : (int)$proj->supervisorid;
    // A supervisor may only issue their own certificate; coordinators any.
    if (!portal::can_manage_project($proj) && $who !== (int)$USER->id) {
        throw new moodle_exception('nopermissions', 'error', '', 'document');
    }
    $detail = 'sup:' . $who;
}
$doc = docs::issue($proj, $kind, $detail);
$pdf = docs::pdf($proj, $doc);
$tmp = make_request_directory() . '/' . clean_filename(get_string('doc_' . $kind, 'local_researchcentral') . '_' . $doc->code) . '.pdf';
file_put_contents($tmp, $pdf);
send_temp_file($tmp, basename($tmp));
