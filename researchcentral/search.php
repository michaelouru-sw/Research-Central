<?php
// One search box for everything the signed-in person is allowed to see: theses, then ethics applications.
require_once(__DIR__ . '/../../config.php');

use local_researchcentral\local\portal;
use local_researchcentral\output\ui;

\local_researchcentral\local\portal::gate();
$PAGE->set_context(context_system::instance());
$q = trim(optional_param('q', '', PARAM_TEXT));
$PAGE->set_url(new moodle_url('/local/researchcentral/search.php', ['q' => $q]));
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('ux_search_go', 'local_researchcentral'));
$PAGE->set_heading(get_string('pluginname', 'local_researchcentral'));
$L = fn(string $k, $a = null) => get_string($k, 'local_researchcentral', $a);
$roles = portal::roles();

$found = [];
if ($q !== '' && $roles) {
    foreach (['coordinator', 'supervisor', 'student'] as $scope) {
        if (!in_array($scope, $roles, true)) {
            continue;
        }
        foreach (portal::projects($scope, ['q' => $q]) as $p) {
            $found[$p->id] = $found[$p->id] ?? $p;
        }
    }
}
$eth = [];
if ($q !== '' && (\local_researchcentral\local\ethics::is_committee() || portal::is_coordinator())) {
    $like = '%' . $DB->sql_like_escape($q) . '%';
    foreach (\local_researchcentral\local\ethics::queue() as $e) {
        if (stripos($e->ref . ' ' . $e->title, $q) !== false) {
            $eth[] = $e;
        }
    }
}

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/researchcentral/portal.php'), '← ' . $L('back'), ['class' => 'tt-back']);
echo html_writer::tag('form',
    html_writer::empty_tag('input', ['type' => 'search', 'name' => 'q', 'value' => s($q), 'class' => 'form-control', 'autofocus' => 'autofocus',
        'placeholder' => $L('ux_search'), 'aria-label' => $L('ux_search')]) .
    html_writer::tag('button', $L('ux_search_go'), ['type' => 'submit', 'class' => 'btn btn-primary']),
    ['method' => 'get', 'action' => (new moodle_url('/local/researchcentral/search.php'))->out(false), 'class' => 'tt-searchform', 'role' => 'search']);
echo $OUTPUT->heading($q === '' ? $L('ux_search_go') : $L('ux_results', s($q)), 3);
if ($q === '') {
    echo html_writer::div($L('ux_search'), 'tt-empty');
} else if (!$found && !$eth) {
    echo html_writer::div($L('ux_no_results', s($q)), 'tt-empty');
} else {
    if ($found) {
        $t = new html_table();
        $t->attributes['class'] = 'tt-table';
        $t->head = [$L('student'), $L('programme'), $L('supervisor'), $L('current_stage'), $L('status'), ''];
        $found = array_slice($found, 0, 100, true);
        foreach ($found as $p) {
            $t->data[] = [html_writer::link(new moodle_url('/local/researchcentral/project.php', ['id' => $p->id]), ui::user_line($p->student), ['class' => 'tt-link']) .
                html_writer::div(format_string($p->title), 'tt-sub'), format_string($p->progname), ui::user_line($p->supervisor),
                $p->current ? format_string($p->current->stagename) : '-', ui::pill($p->state),
                html_writer::link(new moodle_url('/local/researchcentral/workspace.php', ['id' => $p->id]), $L('ws_title'), ['class' => 'btn btn-sm btn-outline-secondary'])];
        }
        echo html_writer::div(html_writer::table($t), 'tt-tablewrap');
    }
    foreach ($eth as $e) {
        echo html_writer::div(html_writer::link(new moodle_url('/local/researchcentral/ethics.php', ['id' => $e->id]), s($e->ref) . ' · ' . s($e->title), ['class' => 'tt-link']) . ' ' .
            html_writer::span($L('eth_st_' . $e->status), 'tt-chk tt-chk-warn'), 'tt-card mb-2');
    }
}
echo $OUTPUT->footer();
