<?php
// Compare two submitted versions of a stage: changed paragraphs with word-level insertions and deletions.
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\diff;
use local_researchcentral\local\portal;
use local_researchcentral\checker\docx_reader;
use local_researchcentral\checker\pdf_reader;

$pmsid = required_param('pms', PARAM_INT);
$v1 = optional_param('v1', 0, PARAM_INT);
$v2 = optional_param('v2', 0, PARAM_INT);
$all = optional_param('all', 0, PARAM_INT);

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$m = $DB->get_record('local_researchcentral_pms', ['id' => $pmsid], '*', MUST_EXIST);
$proj = $DB->get_record('local_researchcentral_proj', ['id' => $m->projid], '*', MUST_EXIST);
portal::require_view($proj);
$context = context_system::instance();
$url = new moodle_url('/local/researchcentral/compare.php', ['pms' => $pmsid]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('compare_versions', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));
$L = fn(string $k, $a = null) => get_string($k, 'local_researchcentral', $a);
$stage = $DB->get_record('local_researchcentral_stage', ['id' => $m->stageid]);

$vers = $DB->get_records('local_researchcentral_ver', ['pmsid' => $pmsid], 'version');
$byid = $vers;
$list = array_values($vers);
if (count($list) < 2) {
    echo $OUTPUT->header();
    echo html_writer::link(new moodle_url('/local/researchcentral/stage.php', ['id' => $pmsid]), '← ' . $L('back_project'), ['class' => 'tt-back']);
    echo $OUTPUT->notification($L('compare_need_two'), 'info');
    echo $OUTPUT->footer();
    exit;
}
if (!isset($byid[$v1]) || !isset($byid[$v2]) || $v1 === $v2) {
    $v2 = (int)$list[count($list) - 1]->id;
    $v1 = (int)$list[count($list) - 2]->id;
}
if ($byid[$v1]->version > $byid[$v2]->version) {
    [$v1, $v2] = [$v2, $v1];
}

/** Paragraph texts of a stored submission, or null when the format cannot be read. */
$extract = function (int $verid) use ($context): ?array {
    $files = get_file_storage()->get_area_files($context->id, 'local_researchcentral', 'submission', $verid, 'filename', false);
    $f = $files ? reset($files) : null;
    if (!$f || $f->get_filesize() > (int)(get_config('local_researchcentral', 'maxfilemb') ?: 30) * 1048576) {
        return null;
    }
    $tmp = $f->copy_content_to_temp('researchcentral');
    if (!$tmp) {
        return null;
    }
    try {
        $ext = strtolower(pathinfo($f->get_filename(), PATHINFO_EXTENSION));
        $doc = $ext === 'docx' ? docx_reader::read($tmp) : ($ext === 'pdf' ? pdf_reader::read($tmp, (string)get_config('local_researchcentral', 'pdftotextpath')) : null);
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
};
raise_memory_limit(MEMORY_EXTRA);
core_php_time_limit::raise(120);
$a = $extract($v1);
$b = $extract($v2);
$ops = ($a !== null && $b !== null) ? diff::paragraphs($a, $b) : null;

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/stage.php', ['id' => $pmsid]), '← ' . $L('back_project'), ['class' => 'tt-back']);
echo $OUTPUT->heading($L('compare_versions') . ': ' . format_string($stage->name), 3);
$opts = [];
foreach ($list as $v) {
    $opts[$v->id] = $L('submission_v', $v->version) . ' · ' . userdate($v->timecreated, get_string('strftimedatetimeshort', 'langconfig'));
}
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out(false), 'class' => 'tt-filters mb-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'pms', 'value' => $pmsid]);
echo html_writer::select($opts, 'v1', $v1, false, ['class' => 'custom-select w-auto']) . ' → ' . html_writer::select($opts, 'v2', $v2, false, ['class' => 'custom-select w-auto']);
echo html_writer::tag('label', html_writer::checkbox('all', 1, (bool)$all) . ' ' . $L('compare_showall'), ['class' => 'mb-0 ml-2']);
echo html_writer::tag('button', $L('compare_go'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

if ($a === null || $b === null) {
    echo $OUTPUT->notification($L('compare_unreadable'), 'warning');
} else if ($ops === null) {
    echo $OUTPUT->notification($L('compare_toodifferent'), 'warning');
} else {
    $st = diff::stats($ops);
    echo \local_researchcentral\output\ui::stats([
        \local_researchcentral\output\ui::stat('+' . $st['added'], $L('compare_added'), 'tt-good'),
        \local_researchcentral\output\ui::stat('-' . $st['removed'], $L('compare_removed'), $st['removed'] ? 'tt-hot' : ''),
        \local_researchcentral\output\ui::stat((string)$st['changed'], $L('compare_changed')),
        \local_researchcentral\output\ui::stat((string)count($b), $L('compare_paras')),
    ]);
    if (!$st['changed']) {
        echo html_writer::div($L('compare_identical'), 'tt-banner');
    }
    $n = count($ops);
    $show = array_fill(0, $n, (bool)$all);
    if (!$all) {
        foreach ($ops as $i => $op) {
            if ($op['type'] !== 'eq') {
                for ($j = max(0, $i - 1); $j <= min($n - 1, $i + 1); $j++) {
                    $show[$j] = true;
                }
            }
        }
    }
    echo html_writer::start_div('tt-diff');
    $gap = false;
    foreach ($ops as $i => $op) {
        if (!$show[$i]) {
            if (!$gap) {
                echo html_writer::div('⋯', 'tt-diff-gap');
                $gap = true;
            }
            continue;
        }
        $gap = false;
        if ($op['type'] === 'eq') {
            echo html_writer::div(s($op['a']), 'tt-diff-p tt-diff-eq');
        } else if ($op['type'] === 'del') {
            echo html_writer::div(html_writer::tag('del', s($op['a'])), 'tt-diff-p tt-diff-del');
        } else if ($op['type'] === 'ins') {
            echo html_writer::div(html_writer::tag('ins', s($op['b'])), 'tt-diff-p tt-diff-ins');
        } else {
            $h = '';
            foreach ($op['words'] as [$t, $w]) {
                $h .= $t === 'eq' ? s($w) . ' ' : html_writer::tag($t === 'ins' ? 'ins' : 'del', s($w)) . ' ';
            }
            echo html_writer::div($h, 'tt-diff-p tt-diff-mod');
        }
    }
    echo html_writer::end_div();
}
echo $OUTPUT->footer();
