<?php
// Student submission: the thesis text as .docx, plus figures and diagrams in their own image slots.
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\figures;
use local_researchcentral\local\portal;
use local_researchcentral\local\uploads;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$pmsid = required_param('pms', PARAM_INT);
$m = $DB->get_record_sql("SELECT m.*, s.name AS stagename, s.needsupload, s.maxmb FROM {local_researchcentral_pms} m
                            JOIN {local_researchcentral_stage} s ON s.id = m.stageid WHERE m.id = ?", [$pmsid]);
if (!$m) {
    throw new moodle_exception('invalidrecord', 'error');
}
$proj = $DB->get_record('local_researchcentral_proj', ['id' => $m->projid], '*', MUST_EXIST);
portal::require_view($proj);
if ((int)$proj->userid !== (int)$USER->id || !$m->needsupload || !in_array($m->status, [portal::PENDING, portal::REVISION], true)) {
    throw new moodle_exception('nopermissions', 'error', '', 'submit');
}
$context = context_system::instance();
$url = new moodle_url('/local/researchcentral/submit.php', ['pms' => $pmsid]);
$back = new moodle_url('/local/researchcentral/stage.php', ['id' => $pmsid]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('sub_title', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));
$L = fn(string $k, $a = null) => get_string($k, 'local_researchcentral', $a);

$stagerec = (object)['maxmb' => $m->maxmb];
$limit = uploads::limit_mb($proj, $stagerec);
$source = uploads::limit_source($proj, $stagerec);
$cap = uploads::server_cap_mb();
$prevver = $DB->get_records('local_researchcentral_ver', ['pmsid' => $pmsid], 'version DESC', '*', 0, 1);
$prevver = $prevver ? reset($prevver) : null;
$prevfigs = $prevver ? figures::for_version((int)$prevver->id) : [];

$errors = [];
$keep = [];
$note = '';
$posted = ['cap' => [], 'alt' => [], 'loc' => []];
$isajax = optional_param('ajax', 0, PARAM_INT) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // The whole request was larger than the server accepts (PHP drops the form data in that case).
    $errors[] = $L('up_err_request', $cap ?: '?');
} else if (data_submitted()) {
    require_sesskey();
    $note = trim(optional_param('note', '', PARAM_TEXT));
    $docs = uploads::files('thesis');
    $doc = $docs[0] ?? null;
    if (!$doc || $doc['error'] === UPLOAD_ERR_NO_FILE || $doc['name'] === '') {
        $errors[] = $L('up_err_nodoc');
    } else if ($doc['error'] !== UPLOAD_ERR_OK) {
        $errors[] = uploads::upload_error_text((int)$doc['error'], $doc['name']);
    } else if (!is_uploaded_file($doc['tmp'])) {
        $errors[] = $L('up_err_failed', s($doc['name']));
    } else if ($e = uploads::check_docx($doc['tmp'], $doc['name'], $doc['size'], $limit)) {
        $errors[] = $e;
    }

    // Figures: kept from the previous version (optionally with a replacement file) and new slots.
    $items = [];
    $keepids = optional_param_array('figkeep', [], PARAM_INT);
    $caps = optional_param_array('figcap', [], PARAM_TEXT);
    $alts = optional_param_array('figalt', [], PARAM_TEXT);
    $locs = optional_param_array('figloc', [], PARAM_TEXT);
    $newfiles = uploads::files('figfile');
    $repl = uploads::files('figrepl');
    foreach ($prevfigs as $pf) {
        if (empty($keepids[$pf->id])) {
            continue;
        }
        $keep[(int)$pf->id] = true;
        $it = ['caption' => $caps['p' . $pf->id] ?? $pf->caption, 'alt' => $alts['p' . $pf->id] ?? $pf->alt, 'location' => $locs['p' . $pf->id] ?? $pf->location, 'copyfrom' => (int)$pf->id];
        $r = $repl[$pf->id] ?? null;
        if ($r && $r['error'] === UPLOAD_ERR_OK && $r['name'] !== '') {
            if ($e = uploads::check_image($r['tmp'], $r['name'], $r['size'])) {
                $errors[] = $e;
            } else {
                $it += ['tmp' => $r['tmp'], 'name' => $r['name']];
            }
        } else if ($r && !in_array($r['error'], [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE], true)) {
            $errors[] = uploads::upload_error_text((int)$r['error'], $r['name']);
        }
        $items[] = $it;
        $posted['cap']['p' . $pf->id] = $it['caption'];
        $posted['alt']['p' . $pf->id] = $it['alt'];
        $posted['loc']['p' . $pf->id] = $it['location'];
    }
    foreach ($newfiles as $i => $f) {
        $caption = trim($caps['n' . $i] ?? '');
        $posted['cap']['n' . $i] = $caption;
        $posted['alt']['n' . $i] = $alts['n' . $i] ?? '';
        $posted['loc']['n' . $i] = $locs['n' . $i] ?? '';
        if ($f['error'] === UPLOAD_ERR_NO_FILE || $f['name'] === '') {
            continue;
        }
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = uploads::upload_error_text((int)$f['error'], $f['name']);
            continue;
        }
        if ($e = uploads::check_image($f['tmp'], $f['name'], $f['size'])) {
            $errors[] = $e;
            continue;
        }
        if ($caption === '') {
            $errors[] = $L('up_err_nocaption', s($f['name']));
            continue;
        }
        $items[] = ['caption' => $caption, 'alt' => $alts['n' . $i] ?? '', 'location' => $locs['n' . $i] ?? '', 'tmp' => $f['tmp'], 'name' => $f['name']];
    }
    if (count($items) > uploads::max_figures()) {
        $errors[] = $L('up_err_toomany', uploads::max_figures());
    }

    if (!$errors) {
        portal::submit($proj, $m, $doc['tmp'], $doc['name'], $note, $items);
        \core\notification::success($L('submitted_ok'));
        if ($isajax) {
            @header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'redirect' => $back->out(false)]);
            exit;
        }
        redirect($back);
    }
    if ($isajax) {
        @header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'errors' => array_map(fn($e) => html_entity_decode(strip_tags($e)), $errors)]);
        exit;
    }
}

echo $OUTPUT->header();
echo \local_researchcentral\output\ui::project_crumbs($proj, [[format_string($m->stagename), $back], [$L('sub_title'), null]]);
echo $OUTPUT->heading($L('sub_title') . ': ' . format_string($m->stagename), 3);
foreach ($errors as $e) {
    echo $OUTPUT->notification($e, 'error');
}

// Rules, stated up front so nobody finds out after uploading.
echo html_writer::div(
    html_writer::div($L('sub_rules'), 'tt-label') .
    html_writer::tag('ul',
        html_writer::tag('li', $L('sub_rule_docx')) .
        html_writer::tag('li', $L('sub_rule_size', (object)['mb' => $limit, 'src' => $L('limit_src_' . $source)]) . ($cap && $cap < $limit ? ' ' . $L('sub_rule_server', $cap) : '')) .
        html_writer::tag('li', $L('sub_rule_figs', (object)['mb' => uploads::figure_mb(), 'max' => uploads::max_figures()])) .
        html_writer::tag('li', $L('sub_rule_placeholder')), ['class' => 'mb-0 pl-3']), 'tt-card tt-info mb-3');

$form = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'pms', 'value' => $pmsid]);

// 1. The thesis text.
$form .= html_writer::div(
    html_writer::tag('h5', '1. ' . $L('sub_doc_h')) .
    html_writer::div(
        html_writer::div('⬆', 'tt-drop-icon', ['aria-hidden' => 'true']) .
        html_writer::div($L('sub_drop_doc'), 'tt-drop-main') .
        html_writer::div($L('sub_drop_doc_sub', $limit), 'tt-sub') .
        html_writer::empty_tag('input', ['type' => 'file', 'name' => 'thesis[]', 'id' => 'tt-doc', 'accept' => '.docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'class' => 'tt-file-in', 'required' => 'required', 'data-maxmb' => $limit, 'data-kind' => 'docx', 'aria-label' => $L('sub_doc_h')]) .
        html_writer::div('', 'tt-file-chosen', ['aria-live' => 'polite']),
        'tt-drop', ['data-for' => 'tt-doc']), 'tt-card mb-3');

// 2. Figures and diagrams.
$rows = '';
foreach ($prevfigs as $pf) {
    $u = figures::url($pf);
    $k = 'p' . $pf->id;
    $rows .= html_writer::div(
        html_writer::div(($u ? html_writer::empty_tag('img', ['src' => $u->out(false), 'alt' => s($pf->alt ?: $pf->caption), 'class' => 'tt-fig-thumb']) : '') , 'tt-fig-thumbcol') .
        html_writer::div(
            html_writer::tag('label', html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => "figkeep[{$pf->id}]", 'value' => 1, 'checked' => 'checked', 'class' => 'mr-1']) .
                $L('fig_keep', (int)$pf->slot), ['class' => 'tt-strong']) .
            html_writer::empty_tag('input', ['type' => 'text', 'name' => "figcap[$k]", 'value' => $posted['cap'][$k] ?? $pf->caption, 'maxlength' => 250, 'class' => 'form-control mb-1', 'placeholder' => $L('fig_caption'), 'aria-label' => $L('fig_caption')]) .
            html_writer::empty_tag('input', ['type' => 'text', 'name' => "figalt[$k]", 'value' => $posted['alt'][$k] ?? $pf->alt, 'maxlength' => 250, 'class' => 'form-control mb-1', 'placeholder' => $L('fig_alt'), 'aria-label' => $L('fig_alt')]) .
            html_writer::empty_tag('input', ['type' => 'text', 'name' => "figloc[$k]", 'value' => $posted['loc'][$k] ?? $pf->location, 'maxlength' => 250, 'class' => 'form-control mb-1', 'placeholder' => $L('fig_loc'), 'aria-label' => $L('fig_loc')]) .
            html_writer::div($L('fig_replace') . ' ' . html_writer::empty_tag('input', ['type' => 'file', 'name' => "figrepl[{$pf->id}]", 'accept' => 'image/png,image/jpeg,image/gif,image/webp', 'class' => 'tt-file-in', 'data-maxmb' => uploads::figure_mb(), 'data-kind' => 'image']), 'tt-sub'),
            'tt-fig-fields'), 'tt-fig-row');
}
$tpl = fn(string $i) => html_writer::div(
    html_writer::div(html_writer::div('🖼', 'tt-fig-thumb tt-fig-empty', ['aria-hidden' => 'true']), 'tt-fig-thumbcol') .
    html_writer::div(
        html_writer::div($L('fig_new'), 'tt-strong mb-1') .
        html_writer::empty_tag('input', ['type' => 'file', 'name' => 'figfile[' . $i . ']', 'accept' => 'image/png,image/jpeg,image/gif,image/webp', 'class' => 'tt-file-in mb-1 tt-fig-file', 'data-maxmb' => uploads::figure_mb(), 'data-kind' => 'image', 'aria-label' => $L('fig_file')]) .
        html_writer::empty_tag('input', ['type' => 'text', 'name' => "figcap[n$i]", 'maxlength' => 250, 'class' => 'form-control mb-1', 'placeholder' => $L('fig_caption'), 'aria-label' => $L('fig_caption')]) .
        html_writer::empty_tag('input', ['type' => 'text', 'name' => "figalt[n$i]", 'maxlength' => 250, 'class' => 'form-control mb-1', 'placeholder' => $L('fig_alt'), 'aria-label' => $L('fig_alt')]) .
        html_writer::empty_tag('input', ['type' => 'text', 'name' => "figloc[n$i]", 'maxlength' => 250, 'class' => 'form-control mb-1', 'placeholder' => $L('fig_loc'), 'aria-label' => $L('fig_loc')]) .
        html_writer::tag('button', $L('fig_remove'), ['type' => 'button', 'class' => 'btn btn-sm btn-link text-danger tt-fig-remove']),
        'tt-fig-fields'), 'tt-fig-row tt-fig-new');
$form .= html_writer::div(
    html_writer::tag('h5', '2. ' . $L('fig_h')) .
    html_writer::div($L('fig_help'), 'tt-sub mb-2') .
    html_writer::div($rows . $tpl('0'), 'tt-fig-list', ['data-max' => uploads::max_figures(), 'data-count' => count($prevfigs)]) .
    html_writer::tag('template', $tpl('__i__'), ['id' => 'tt-fig-tpl']) .
    html_writer::tag('button', '+ ' . $L('fig_add'), ['type' => 'button', 'class' => 'btn btn-outline-primary btn-sm mt-2 tt-fig-add']), 'tt-card mb-3');

// 3. Note and submit.
$form .= html_writer::div(
    html_writer::tag('h5', '3. ' . $L('sub_note_h')) .
    html_writer::tag('textarea', s($note), ['name' => 'note', 'rows' => 3, 'class' => 'form-control mb-3 tt-draft', 'data-draft' => 'sub' . $pmsid, 'placeholder' => $L('coverNote'), 'aria-label' => $L('coverNote')]) .
    html_writer::div(html_writer::div('', 'tt-progress-bar'), 'tt-progress', ['hidden' => 'hidden', 'role' => 'progressbar', 'aria-valuemin' => 0, 'aria-valuemax' => 100]) .
    html_writer::div('', 'tt-sub mb-2 tt-progress-text', ['aria-live' => 'polite']) .
    html_writer::div(
        html_writer::link($back, get_string('cancel'), ['class' => 'btn btn-secondary']) .
        html_writer::tag('button', $L('submit_document'), ['type' => 'submit', 'class' => 'btn btn-primary btn-lg tt-submit', 'data-busy' => $L('sub_sending')]),
        'tt-stickybar'), 'tt-card mb-3');

echo html_writer::tag('form', $form, ['method' => 'post', 'action' => $url->out(false), 'enctype' => 'multipart/form-data', 'class' => 'tt-upload-form', 'data-ajax' => 1,
    'data-confirm' => $L('sub_confirm')]);
echo $OUTPUT->footer();
