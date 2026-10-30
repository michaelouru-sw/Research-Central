<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Generated documents (PDF): viva invitation, progress report, supervision certificate, completion letter.
 * Each carries a verification code and a QR code that opens the public verification page.
 */
class docs {
    const KINDS = ['vivainvite', 'progress', 'supcert', 'completion', 'ethicsletter'];

    /** Which kinds may this person generate for this project? */
    public static function allowed(\stdClass $proj): array {
        global $DB, $USER;
        $student = (int)$proj->userid === (int)$USER->id;
        $staff = annotate::is_staff($proj);
        $coord = portal::can_manage_project($proj);
        $out = [];
        if ($staff && $DB->record_exists('local_researchcentral_viva', ['projid' => $proj->id])) {
            $out[] = 'vivainvite';
        }
        if ($student || $staff) {
            $out[] = 'progress';
        }
        if ($coord || portal::is_project_supervisor($proj)) {
            $out[] = 'supcert';
        }
        if (($coord || $student || $staff) && $DB->record_exists_select('local_researchcentral_eth', "projid = ? AND status IN ('approved','conditional')", [$proj->id])) {
            $out[] = 'ethicsletter';
        }
        if (($coord || $student) && self::is_complete($proj)) {
            $out[] = 'completion';
        }
        return $out;
    }

    public static function is_complete(\stdClass $proj): bool {
        global $DB;
        $tot = $DB->count_records('local_researchcentral_pms', ['projid' => $proj->id]);
        return $tot > 0 && $tot === $DB->count_records('local_researchcentral_pms', ['projid' => $proj->id, 'status' => portal::APPROVED]);
    }

    /** A verification record; the same document is reused so its code stays stable. */
    public static function issue(\stdClass $proj, string $kind, string $detail = ''): \stdClass {
        global $DB, $USER;
        if (!in_array($kind, self::KINDS, true)) {
            throw new \moodle_exception('invalidparameter', 'debug');
        }
        if ($kind !== 'progress') {
            $old = $DB->get_record_select('local_researchcentral_doc', 'projid = ? AND kind = ? AND detail = ? AND revoked = 0', [$proj->id, $kind, $detail], '*', IGNORE_MULTIPLE);
            if ($old) {
                return $old;
            }
        }
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
        } while ($DB->record_exists('local_researchcentral_doc', ['code' => $code]));
        $row = (object)['projid' => $proj->id, 'kind' => $kind, 'code' => $code, 'detail' => \core_text::substr($detail, 0, 250), 'issuedby' => $USER->id,
            'timecreated' => time(), 'revoked' => 0];
        $row->id = $DB->insert_record('local_researchcentral_doc', $row);
        portal::log((int)$proj->id, 'document', get_string('doc_' . $kind, 'local_researchcentral') . ' ' . $code);
        return $row;
    }

    public static function verify_url(string $code): \moodle_url {
        return new \moodle_url('/local/researchcentral/verify.php', ['c' => $code]);
    }

    /** Build the PDF. $detail is the supervisor id for supervision certificates. */
    public static function pdf(\stdClass $proj, \stdClass $doc): string {
        global $CFG, $DB, $SITE;
        require_once($CFG->libdir . '/pdflib.php');
        $L = fn(string $k, $a = null) => get_string($k, 'local_researchcentral', $a);
        $e = fn($s) => s((string)$s);
        $dfs = get_string('strftimedate', 'langconfig');
        $dts = get_string('strftimedatetimeshort', 'langconfig');
        $student = \core_user::get_user($proj->userid);
        $prog = $DB->get_record('local_researchcentral_prog', ['id' => $proj->progid]);
        $school = $prog && $prog->schoolid ? (string)$DB->get_field('local_researchcentral_school', 'name', ['id' => $prog->schoolid]) : '';
        $ms = portal::milestones([$proj->id])[$proj->id] ?? [];
        $kind = $doc->kind;
        $title = $L('doc_' . $kind);
        $body = '';
        $subject = '<b>' . $e(fullname($student)) . '</b>, ' . $e($prog ? format_string($prog->name) : '') . ($school !== '' ? ', ' . $e($school) : '');
        if ($kind === 'vivainvite') {
            $v = $DB->get_records('local_researchcentral_viva', ['projid' => $proj->id], 'id DESC', '*', 0, 1);
            $v = $v ? reset($v) : null;
            $panel = $v ? $DB->get_records('local_researchcentral_vivapanel', ['vivaid' => $v->id], 'id') : [];
            $body .= '<p>' . $L('doc_viva_p1', (object)['name' => $e(fullname($student)), 'title' => $e(format_string($proj->title))]) . '</p>';
            if ($v) {
                $body .= '<table cellpadding="4" border="0"><tr><td width="25%"><b>' . $L('doc_when') . '</b></td><td>' . $e(userdate($v->starttime, $dfs) . ', ' . userdate($v->starttime, '%H:%M') . ' – ' . userdate($v->endtime, '%H:%M')) . '</td></tr>' .
                    '<tr><td><b>' . $L('doc_where') . '</b></td><td>' . $e($v->venue ?: '-') . '</td></tr>' .
                    (preg_match('~^https?://~i', (string)$v->joinurl) ? '<tr><td><b>' . $L('doc_link') . '</b></td><td>' . $e($v->joinurl) . '</td></tr>' : '') . '</table>';
                if ($panel) {
                    $body .= '<p><b>' . $L('viva_panel') . '</b></p><ul>';
                    foreach ($panel as $p) {
                        $body .= '<li>' . $e($p->name) . ' (' . $e($L('viva_role_' . $p->role)) . ')</li>';
                    }
                    $body .= '</ul>';
                }
            }
            $body .= '<p>' . $L('doc_viva_p2') . '</p>';
        } else if ($kind === 'progress') {
            $rows = '';
            foreach ($ms as $m) {
                $rows .= '<tr><td>' . $e(format_string($m->stagename)) . '</td><td>' . $e($L('js_' . $m->status)) . '</td><td>' .
                    ($m->duedate ? $e(userdate($m->duedate, get_string('strftimedatefullshort', 'langconfig'))) : '-') . '</td><td>' .
                    ($m->grade !== null && (int)$m->maxgrade ? $e(format_float($m->grade, 1) . ' / ' . (int)$m->maxgrade) : '-') . '</td></tr>';
            }
            $ov = portal::overall($ms);
            $held = $DB->count_records('local_researchcentral_meet', ['projid' => $proj->id, 'status' => 'held']);
            $openact = $DB->count_records('local_researchcentral_mact', ['projid' => $proj->id, 'status' => 'open']);
            $body .= '<p>' . $L('doc_progress_p1', $e(format_string($proj->title))) . '</p>' .
                '<table cellpadding="4" border="1"><tr bgcolor="#e8eef7"><th><b>' . $L('stage') . '</b></th><th><b>' . $L('status') . '</b></th><th><b>' . $L('due') . '</b></th><th><b>' . $L('mark') . '</b></th></tr>' . $rows . '</table>' .
                '<p>' . ($ov ? $L('overall_mark') . ': <b>' . $e(format_float($ov['percent'], 1)) . '%</b><br/>' : '') .
                $L('doc_meetings_held', $held) . '<br/>' . $L('doc_open_actions', $openact) . '</p>';
        } else if ($kind === 'ethicsletter') {
            $eth = $DB->get_records_select('local_researchcentral_eth', "projid = ? AND status IN ('approved','conditional')", [$proj->id], 'timedecided DESC', '*', 0, 1);
            $eth = $eth ? reset($eth) : null;
            if ($eth) {
                $permit = $eth->permitid ? $DB->get_record('local_researchcentral_permit', ['id' => $eth->permitid]) : null;
                $body .= '<p>' . $L('doc_eth_p1', (object)['name' => $e(fullname($student)), 'ref' => $e($eth->ref), 'title' => $e($eth->title),
                    'decision' => $e($L('eth_st_' . $eth->status)), 'date' => $e(userdate($eth->timedecided, $dfs))]) . '</p>' .
                    ($permit && $permit->expiry ? '<p>' . $L('permit_expiry') . ': <b>' . $e(userdate($permit->expiry, $dfs)) . '</b></p>' : '') .
                    (trim((string)$eth->decisionnote) !== '' ? '<p><b>' . $L('eth_conditions') . '</b><br/>' . nl2br($e($eth->decisionnote)) . '</p>' : '');
            }
        } else if ($kind === 'supcert') {
            $sup = \core_user::get_user((int)preg_replace('/\D/', '', $doc->detail) ?: (int)$proj->supervisorid);
            $lead = $sup && (int)$sup->id === (int)$proj->supervisorid;
            $body .= '<p>' . $L('doc_cert_p1', (object)['sup' => $e($sup ? fullname($sup) : '-'), 'role' => $e($L($lead ? 'lead_supervisor' : 'co_supervisor')),
                'student' => $e(fullname($student)), 'title' => $e(format_string($proj->title)), 'start' => $e(userdate($proj->startdate ?: $proj->timecreated, $dfs))]) . '</p>';
            $subject = '<b>' . $e($sup ? fullname($sup) : '-') . '</b>';
        } else {
            $last = 0;
            foreach ($ms as $m) {
                $last = max($last, (int)$m->timedecided);
            }
            $ov = portal::overall($ms);
            $v = $DB->get_records('local_researchcentral_viva', ['projid' => $proj->id], 'id DESC', '*', 0, 1);
            $v = $v ? reset($v) : null;
            $body .= '<p>' . $L('doc_comp_p1', (object)['name' => $e(fullname($student)), 'prog' => $e($prog ? format_string($prog->name) : ''), 'title' => $e(format_string($proj->title)),
                'date' => $e($last ? userdate($last, $dfs) : '-')]) . '</p>' .
                ($ov ? '<p>' . $L('overall_mark') . ': <b>' . $e(format_float($ov['percent'], 1)) . '%</b></p>' : '') .
                ($v && $v->outcome ? '<p>' . $L('viva_result') . ': <b>' . $e($L('viva_out_' . $v->outcome)) . '</b></p>' : '') . '<p>' . $L('doc_comp_p2') . '</p>';
        }

        $pdf = new \pdf();
        $pdf->SetCreator('Research Central');
        $pdf->SetTitle($title);
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->SetMargins(22, 20, 22);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->SetFont('freeserif', '', 12);
        $pdf->AddPage();
        $html = '<div style="text-align:center"><h3>' . $e(format_string($SITE->fullname)) . '</h3>' . ($school !== '' ? '<b>' . $e($school) . '</b><br/>' : '') . '</div><hr/>' .
            '<h2>' . $e($title) . '</h2><p>' . $L('doc_date') . ': ' . $e(userdate($doc->timecreated, $dfs)) . '<br/>' . $subject . '</p>' . $body;
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Ln(14);
        $pdf->writeHTML('<p>______________________________<br/>' . $e(fullname(\core_user::get_user((int)$doc->issuedby) ?: $student)) . '</p>', true, false, true, false, '');
        $y = $pdf->GetY() + 6;
        if ($y > 240) {
            $pdf->AddPage();
            $y = 20;
        }
        $url = self::verify_url($doc->code)->out(false);
        $pdf->write2DBarcode($url, 'QRCODE,M', 22, $y, 30, 30, ['border' => false, 'padding' => 0], 'N');
        $pdf->SetFont('freeserif', '', 9);
        $pdf->SetXY(56, $y + 4);
        $pdf->MultiCell(120, 5, $L('doc_verify_hint') . "\n" . $url . "\n" . $L('doc_code') . ': ' . $doc->code, 0, 'L');
        return $pdf->Output('', 'S');
    }
}
