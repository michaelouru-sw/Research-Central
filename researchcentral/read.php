<?php
// Read a submitted document online and comment on it paragraph by paragraph.
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\annotate;
use local_researchcentral\local\portal;
use local_researchcentral\local\receipts;

$verid = required_param('ver', PARAM_INT);
$filter = optional_param('f', 'all', PARAM_ALPHA);
if (!in_array($filter, ['all', 'commented', 'open'], true)) {
    $filter = 'all';
}

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$ver = $DB->get_record('local_researchcentral_ver', ['id' => $verid], '*', MUST_EXIST);
$m = $DB->get_record('local_researchcentral_pms', ['id' => $ver->pmsid], '*', MUST_EXIST);
$proj = $DB->get_record('local_researchcentral_proj', ['id' => $m->projid], '*', MUST_EXIST);
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/researchcentral/read.php', ['ver' => $verid]));
portal::require_view($proj);
$staff = annotate::is_staff($proj);
$isstudent = (int)$proj->userid === (int)$USER->id;
$context = context_system::instance();
$url = new moodle_url('/local/researchcentral/read.php', ['ver' => $verid, 'f' => $filter]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('read_title', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));
$L = fn(string $k, $a = null) => get_string($k, 'local_researchcentral', $a);
$stage = $DB->get_record('local_researchcentral_stage', ['id' => $m->stageid]);
$m->needsupload = $stage->needsupload;
$m->maxgrade = $stage->maxgrade;
$dts = get_string('strftimedatetimeshort', 'langconfig');

raise_memory_limit(MEMORY_EXTRA);
core_php_time_limit::raise(120);
$paras = annotate::paragraphs($verid);

// ---------- Actions (all before any output) ----------
$do = optional_param('do', '', PARAM_ALPHA);
if ($do !== '' && data_submitted()) {
    require_sesskey();
    $back = fn(string $msg = '', int $id = 0, string $type = \core\output\notification::NOTIFY_SUCCESS) =>
        redirect(new moodle_url($url, [], $id ? 'c' . $id : ''), $msg, null, $type);
    if ($do === 'add' && $staff && $paras !== null) {
        $idx = optional_param('para', -1, PARAM_INT);
        $body = optional_param('body', '', PARAM_TEXT);
        if (isset($paras[$idx]) && trim($body) !== '') {
            $id = annotate::add_root($proj, $ver, $idx, annotate::hash($paras[$idx]), optional_param('quote', '', PARAM_TEXT), $body, (bool)optional_param('private', 0, PARAM_INT));
            $back($L('ann_saved'), $id);
        }
        $back($L('ann_empty'), 0, \core\output\notification::NOTIFY_WARNING);
    }
    if ($do === 'addfig' && $staff) {
        $fid = optional_param('figid', 0, PARAM_INT);
        $fig = $DB->get_record('local_researchcentral_fig', ['id' => $fid, 'verid' => $verid]);
        $body = optional_param('body', '', PARAM_TEXT);
        if ($fig && trim($body) !== '') {
            $id = annotate::add_root($proj, $ver, 0, '', get_string('fig_n', 'local_researchcentral', (int)$fig->slot), $body, (bool)optional_param('private', 0, PARAM_INT), (int)$fig->id);
            $back($L('ann_saved'), $id);
        }
        $back($L('ann_empty'), 0, \core\output\notification::NOTIFY_WARNING);
    }
    $cid = optional_param('cid', 0, PARAM_INT);
    $c = $cid ? $DB->get_record('local_researchcentral_ann', ['id' => $cid, 'projid' => $proj->id]) : null;
    if ($c && $do === 'reply') {
        $root = $c->parentid ? $DB->get_record('local_researchcentral_ann', ['id' => $c->parentid, 'projid' => $proj->id], '*', MUST_EXIST) : $c;
        if (!$staff && $root->private) {
            throw new moodle_exception('nopermissions', 'error');
        }
        $id = annotate::add_reply($proj, $root, optional_param('body', '', PARAM_TEXT));
        $back($id ? $L('ann_saved') : $L('ann_empty'), $root->id, $id ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
    }
    if ($c && $do === 'status') {
        $ok = annotate::set_status($proj, $c, optional_param('to', '', PARAM_ALPHA));
        $back($ok ? $L('ann_updated') : '', $c->id);
    }
    if ($c && ($do === 'share' || $do === 'unshare')) {
        $ok = \local_researchcentral\local\examiner::set_shared($proj, $c, $do === 'share');
        $back($ok ? $L('ann_updated') : '', $c->id);
    }
    if ($c && ($do === 'queue' || $do === 'unqueue')) {
        $ok = annotate::set_queued($proj, $c, $do === 'queue');
        $back($ok ? $L($do === 'queue' ? 'ann_queued_ok' : 'ann_unqueued_ok') : '', $c->id);
    }
    if ($c && $do === 'delete') {
        $ok = annotate::delete($proj, $c);
        $back($ok ? $L('deleted') : '');
    }
    $back();
}

// ---------- Data for display ----------
$vers = $DB->get_records('local_researchcentral_ver', ['pmsid' => $m->id], 'version');
$all = annotate::for_stage($proj, (int)$m->id, $staff);
$roots = [];
$replies = [];
foreach ($all as $c) {
    if ($c->parentid) {
        $replies[(int)$c->parentid][] = $c;
    } else {
        $roots[(int)$c->id] = $c;
    }
}
[$byPara, $orphans] = annotate::place($paras, $roots, $verid);
// Receipts for the viewer's own comments, then mark the others as seen.
$ids = array_map(fn($c) => (int)$c->id, $all);
$seen = receipts::for_items('ann', $ids);
annotate::mark_seen($all, (int)$proj->id, (int)$USER->id);
$names = [];
$nameof = fn(int $u): string => \local_researchcentral\local\examiner::author_name($u);
$unread = fn(\stdClass $c) => (int)$c->userid !== (int)$USER->id && !isset($seen[(int)$c->id][(int)$USER->id]);
$verlabel = [];
foreach ($vers as $x) {
    $verlabel[(int)$x->id] = $L('submission_v', $x->version);
}

$open = count(array_filter($roots, fn($c) => $c->status !== annotate::RESOLVED && (int)$c->verid === $verid));
$resolved = count(array_filter($roots, fn($c) => $c->status === annotate::RESOLVED && (int)$c->verid === $verid));

$chip = function (string $st) use ($L): string {
    return html_writer::span($L('ann_st_' . $st), 'tt-chk tt-ann-' . $st);
};

// Review points are shown to the student only once the round's decision has been issued.
$decidedreview = [];
foreach (array_unique(array_filter(array_map(fn($c) => (int)$c->reviewid, $all))) as $rid) {
    $rv = $DB->get_record('local_researchcentral_review', ['id' => $rid], 'id, pmsid, round');
    $decidedreview[$rid] = $rv && $DB->record_exists('local_researchcentral_decision', ['pmsid' => $rv->pmsid, 'round' => $rv->round]);
}
$thread = function (\stdClass $c) use ($decidedreview, $m, $replies, $staff, $isstudent, $proj, $USER, $nameof, $dts, $seen, $chip, $L, $unread, $verlabel, $verid, $url): string {
    $form = fn(string $do, array $extra, string $label, string $cls) => html_writer::tag('form',
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => $do]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cid', 'value' => $c->id]) .
        implode('', array_map(fn($k, $v) => html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]), array_keys($extra), $extra)) .
        html_writer::tag('button', $label, ['type' => 'submit', 'class' => 'btn btn-sm ' . $cls]),
        ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline']);
    $one = function (\stdClass $x, bool $root) use ($nameof, $dts, $seen, $proj, $USER, $unread, $L): string {
        $mine = (int)$x->userid === (int)$USER->id;
        $head = html_writer::span(s($nameof((int)$x->userid)), 'tt-strong') . ' ' . html_writer::span(userdate($x->timecreated, $dts), 'tt-sub') .
            ($x->private ? ' ' . html_writer::span($L('ann_private'), 'tt-chk tt-ann-private') : '') .
            ($unread($x) ? ' ' . html_writer::span($L('rcpt_new'), 'tt-newbadge') : '') .
            ($mine ? ' ' . annotate::seen_line($proj, $x, $seen[(int)$x->id] ?? [], $nameof) : '');
        return html_writer::div($head . html_writer::div(nl2br(s($x->body)), 'tt-ann-body'), 'tt-ann-msg');
    };
    $quote = $c->quote !== '' && $c->quote !== null ? html_writer::div('“' . s($c->quote) . '”', 'tt-ann-quote') : '';
    $from = (int)$c->verid !== $verid ? ' ' . html_writer::span($L('ann_from', $verlabel[(int)$c->verid] ?? '?'), 'tt-chk tt-ann-from') : '';
    $fbchip = ((int)$c->reviewid && ($staff || !empty($decidedreview[(int)$c->reviewid]))) ? ' ' . html_writer::span($L('ann_in_review', (int)$c->itemno), 'tt-chk tt-chk-pass') : ((int)$c->queued && $staff ? ' ' . html_writer::span($L('ann_queued'), 'tt-chk tt-chk-warn') : '');
    $html = html_writer::div($chip($c->status) . $from . $fbchip, 'mb-1') . $quote . $one($c, true);
    foreach ($replies[(int)$c->id] ?? [] as $r) {
        $html .= html_writer::div($one($r, false), 'tt-ann-reply');
    }
    $acts = '';
    $canreply = $staff || ($isstudent && !$c->private);
    if ($canreply) {
        $acts .= html_writer::tag('details', html_writer::tag('summary', $L('ann_reply'), ['class' => 'btn btn-sm btn-outline-secondary']) .
            html_writer::tag('form',
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'reply']) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cid', 'value' => $c->id]) .
                html_writer::tag('textarea', '', ['name' => 'body', 'rows' => 3, 'class' => 'form-control mb-1', 'required' => 'required', 'aria-label' => $L('ann_reply')]) .
                html_writer::tag('button', $L('ann_send'), ['type' => 'submit', 'class' => 'btn btn-sm btn-primary']),
                ['method' => 'post', 'action' => $url->out(false)]), ['class' => 'd-inline-block mr-1']);
    }
    if ($isstudent && !$staff && $c->status === annotate::OPEN && !$c->private) {
        $acts .= $form('status', ['to' => 'addressed'], $L('ann_mark_addressed'), 'btn-outline-primary mr-1');
    }
    if ($staff) {
        if ($c->status !== annotate::RESOLVED) {
            $acts .= $form('status', ['to' => 'resolved'], $L('ann_resolve'), 'btn-outline-primary mr-1');
        } else {
            $acts .= $form('status', ['to' => 'open'], $L('ann_reopen'), 'btn-outline-secondary mr-1');
        }
    }
    if ($staff && !$c->private && !$c->parentid && !(int)$c->reviewid && \local_researchcentral\local\review::can_review($proj, $m)) {
        $acts .= (int)$c->queued === (int)$USER->id
            ? $form('unqueue', [], $L('ann_unqueue'), 'btn-outline-secondary mr-1')
            : $form('queue', [], $L('ann_queue'), 'btn-outline-primary mr-1');
    }
    if ($staff && (int)$c->userid < 0 && !$c->parentid) {
        $acts .= $c->private ? $form('share', [], $L('ann_share'), 'btn-outline-primary mr-1') : $form('unshare', [], $L('ann_unshare'), 'btn-outline-secondary mr-1');
    }
    if ((int)$c->userid === (int)$USER->id) {
        $acts .= $form('delete', [], $L('delete'), 'btn-link text-danger');
    }
    return html_writer::div($html . ($acts ? html_writer::div($acts, 'tt-ann-acts mt-2') : ''), 'tt-ann tt-ann-' . $c->status . ($c->private ? ' tt-ann-priv' : ''), ['id' => 'c' . $c->id]);
};

// ---------- Output ----------
echo $OUTPUT->header();
echo \local_researchcentral\output\ui::project_crumbs($proj, [[format_string($stage->name), new moodle_url('/local/researchcentral/stage.php', ['id' => $m->id])], [$L('submission_v', $ver->version), null]]);
echo $OUTPUT->heading($L('read_title') . ': ' . format_string($stage->name) . ' · ' . $L('submission_v', $ver->version), 3);

$tabs = '';
foreach ($vers as $x) {
    $tabs .= html_writer::link(new moodle_url('/local/researchcentral/read.php', ['ver' => $x->id, 'f' => $filter]), $L('submission_v', $x->version),
        ['class' => 'tt-tab' . ((int)$x->id === $verid ? ' active' : '')]);
}
echo html_writer::div($tabs, 'tt-tabs');

$files = get_file_storage()->get_area_files($context->id, 'local_researchcentral', 'submission', $verid, 'filename', false);
$dl = '';
foreach ($files as $f) {
    $dl .= ' ' . html_writer::link(moodle_url::make_pluginfile_url($context->id, 'local_researchcentral', 'submission', $verid, '/', $f->get_filename(), true),
        '⬇ ' . s($f->get_filename()), ['class' => 'btn btn-sm btn-outline-secondary']);
}
$fl = '';
foreach (['all' => 'ann_f_all', 'commented' => 'ann_f_commented', 'open' => 'ann_f_open'] as $k => $sk) {
    $fl .= html_writer::link(new moodle_url('/local/researchcentral/read.php', ['ver' => $verid, 'f' => $k]), $L($sk), ['class' => 'btn btn-sm mr-1 ' . ($filter === $k ? 'btn-primary' : 'btn-outline-secondary')]);
}
echo html_writer::div(html_writer::span($L('ann_counts', (object)['open' => $open, 'resolved' => $resolved]), 'tt-sub mr-3') . $fl . $dl, 'mb-3');
$src = \local_researchcentral\local\commented::file($verid);
$isdocx = $src && strtolower(pathinfo($src->get_filename(), PATHINFO_EXTENSION)) === 'docx';
$dlurl = new moodle_url('/local/researchcentral/download.php');
$opt = fn(string $n, array $o, string $sel) => html_writer::select($o, $n, $sel, false, ['class' => 'custom-select custom-select-sm w-auto mr-2', 'aria-label' => $n]);
echo html_writer::tag('details', html_writer::tag('summary', '⬇ ' . $L('dl_title'), ['class' => 'btn btn-sm btn-primary']) .
    html_writer::tag('form',
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ver', 'value' => $verid]) .
        html_writer::select(['docx' => $isdocx ? $L('dl_fmt_docx') : $L('dl_fmt_docx_text'), 'pdf' => $L('dl_fmt_pdf')], 'fmt', 'docx', false, ['class' => 'custom-select custom-select-sm w-auto mr-2', 'aria-label' => $L('dl_format')]) .
        $opt('inc', ['all' => $L('dl_inc_all'), 'open' => $L('dl_inc_open')], 'all') .
        $opt('content', ['whole' => $L('dl_content_whole'), 'commented' => $L('dl_content_commented')], 'whole') .
        html_writer::tag('button', $L('dl_go'), ['type' => 'submit', 'class' => 'btn btn-sm btn-primary']) .
        html_writer::div($staff ? $L('dl_note_staff') : $L('dl_note_student'), 'tt-sub mt-1'),
        ['method' => 'get', 'action' => $dlurl->out(false), 'class' => 'mt-2 p-2 tt-card']), ['class' => 'mb-3']);
echo html_writer::div($staff ? $L('ann_help_staff') : $L('ann_help_student'), 'tt-sub mb-3');

if ($orphans) {
    $h = '';
    foreach ($orphans as $c) {
        $h .= $thread($c);
    }
    echo html_writer::div(html_writer::tag('h5', $L('ann_orphans')) . html_writer::div($L('ann_orphans_help'), 'tt-sub mb-2') . $h, 'tt-card mb-3');
}

// Figures and diagrams, submitted separately from the text, each with its own thread.
$figs = \local_researchcentral\local\figures::for_version($verid);
$figcomments = \local_researchcentral\local\figures::comments($roots);
if ($figs && $filter !== 'commented' || ($figs && $figcomments)) {
    $fh = html_writer::tag('h5', $L('fig_section') . ' (' . count($figs) . ')', ['id' => 'figures']);
    foreach ($figs as $fg) {
        $cs = $figcomments[(int)$fg->id] ?? [];
        if ($filter === 'commented' && !$cs) {
            continue;
        }
        if ($filter === 'open' && !array_filter($cs, fn($c) => $c->status !== annotate::RESOLVED)) {
            continue;
        }
        $u = \local_researchcentral\local\figures::url($fg);
        $full = \local_researchcentral\local\figures::url($fg, false);
        $threads = '';
        foreach ($cs as $c) {
            if ($filter === 'open' && $c->status === annotate::RESOLVED) {
                continue;
            }
            $threads .= $thread($c);
        }
        $add = $staff ? html_writer::tag('details', html_writer::tag('summary', '💬 ' . $L('fig_comment'), ['class' => 'tt-ann-add']) .
            html_writer::tag('form',
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'addfig']) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'figid', 'value' => $fg->id]) .
                html_writer::tag('textarea', '', ['name' => 'body', 'rows' => 3, 'class' => 'form-control mb-1', 'required' => 'required', 'aria-label' => $L('fig_comment')]) .
                html_writer::tag('label', html_writer::checkbox('private', 1, false) . ' ' . $L('ann_private_opt'), ['class' => 'mr-3 mb-0']) .
                html_writer::tag('button', $L('ann_send'), ['type' => 'submit', 'class' => 'btn btn-sm btn-primary']),
                ['method' => 'post', 'action' => $url->out(false), 'class' => 'tt-ann-form']), ['class' => 'tt-ann-new']) : '';
        $fh .= html_writer::div(
            html_writer::div(($u ? html_writer::link($full, html_writer::empty_tag('img', ['src' => $u->out(false), 'alt' => s($fg->alt !== '' ? $fg->alt : $fg->caption), 'class' => 'tt-fig-view', 'loading' => 'lazy']), ['target' => '_blank', 'rel' => 'noopener', 'title' => $L('fig_open')]) : ''), 'tt-fig-viewcol') .
            html_writer::div(html_writer::div($L('fig_n', (int)$fg->slot), 'tt-label') . html_writer::div(s($fg->caption), 'tt-strong') .
                ($fg->alt !== '' ? html_writer::div(s($fg->alt), 'tt-sub') : '') .
                ($fg->location !== '' ? html_writer::div($L('fig_where', s($fg->location)), 'tt-sub') : '') . $threads . $add, 'tt-fig-viewbody'),
            'tt-fig-viewrow', ['id' => 'fig' . $fg->id]);
    }
    echo html_writer::div($fh, 'tt-card mb-3');
}

if ($paras === null) {
    echo $OUTPUT->notification($L('read_unreadable'), 'warning');
} else if (!$paras) {
    echo html_writer::div($L('read_empty'), 'tt-empty');
} else {
    $shown = 0;
    foreach ($paras as $i => $text) {
        $cs = $byPara[$i] ?? [];
        if ($filter === 'commented' && !$cs) {
            continue;
        }
        if ($filter === 'open' && !array_filter($cs, fn($c) => $c->status !== annotate::RESOLVED)) {
            continue;
        }
        $shown++;
        $out = html_writer::div(html_writer::span($i + 1, 'tt-para-n') . html_writer::div(s($text), 'tt-para-text'), 'tt-para-row');
        foreach ($cs as $c) {
            if ($filter === 'open' && $c->status === annotate::RESOLVED) {
                continue;
            }
            $out .= $thread($c);
        }
        if ($staff) {
            $out .= html_writer::tag('details', html_writer::tag('summary', '💬 ' . $L('ann_add'), ['class' => 'tt-ann-add', 'data-para' => $i]) .
                html_writer::tag('form',
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'add']) .
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'para', 'value' => $i]) .
                    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'quote', 'class' => 'form-control mb-1 tt-ann-quote-in', 'placeholder' => $L('ann_quote_ph'), 'maxlength' => 250, 'aria-label' => $L('ann_quote_ph')]) .
                    html_writer::tag('textarea', '', ['name' => 'body', 'rows' => 3, 'class' => 'form-control mb-1', 'required' => 'required', 'aria-label' => $L('ann_add')]) .
                    html_writer::tag('label', html_writer::checkbox('private', 1, false) . ' ' . $L('ann_private_opt'), ['class' => 'mr-3 mb-0']) .
                    html_writer::tag('button', $L('ann_send'), ['type' => 'submit', 'class' => 'btn btn-sm btn-primary']),
                    ['method' => 'post', 'action' => $url->out(false), 'class' => 'tt-ann-form']), ['class' => 'tt-ann-new']);
        }
        echo html_writer::div($out, 'tt-para' . ($cs ? ' has-comments' : ''), ['id' => 'p' . $i]);
    }
    if (!$shown) {
        echo html_writer::div($L('ann_none'), 'tt-empty');
    }
}

// Prefill the quote box with the text the supervisor has selected inside the paragraph.
$PAGE->requires->js_init_code("(function(){document.querySelectorAll('.tt-ann-add').forEach(function(s){s.addEventListener('mousedown',function(){" .
    "var sel=window.getSelection?window.getSelection().toString().trim():'';var p=s.closest('.tt-para');" .
    "if(sel&&p&&p.querySelector('.tt-para-text').textContent.indexOf(sel)>-1){var q=s.parentNode.querySelector('.tt-ann-quote-in');if(q&&!q.value){q.value=sel.substring(0,250);}}});});})();");

echo $OUTPUT->footer();
