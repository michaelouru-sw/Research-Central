<?php
namespace local_researchcentral\output;

use html_writer;

defined('MOODLE_INTERNAL') || die();

/** Small HTML helpers for the central tracker (Editorial-Manager style). */
class ui {
    public static function pill(string $state, ?string $label = null): string {
        $label = $label ?? get_string('state_' . $state, 'local_researchcentral');
        return html_writer::span(s($label), 'tt-pill tt-' . $state);
    }

    public static function bar(int $done, int $total): string {
        $pct = $total ? (int)round(100 * $done / $total) : 0;
        return html_writer::div(html_writer::div('', 'tt-bar-fill', ['style' => "width:{$pct}%"]), 'tt-bar',
            ['title' => "$done / $total"]) . html_writer::span($pct . '%', 'tt-pct');
    }

    public static function stat(string $value, string $label, string $class = ''): string {
        return html_writer::div(html_writer::div($value, 'tt-stat-n') . html_writer::div($label, 'tt-stat-l'), 'tt-stat ' . $class);
    }

    public static function stats(array $stats): string {
        return html_writer::div(implode('', $stats), 'tt-stats');
    }

    /** Horizontal pipeline of stages with status dots. */
    public static function pipeline(array $milestones): string {
        $out = '';
        foreach ($milestones as $i => $m) {
            $state = \local_researchcentral\local\portal::is_overdue($m) ? 'overdue' : $m->status;
            $out .= html_writer::div(
                html_writer::span($m->status === 'approved' ? '✓' : (string)($i + 1), 'tt-dot') .
                html_writer::span(format_string($m->stagename), 'tt-step-name'),
                'tt-step tt-step-' . $state);
        }
        return html_writer::div($out, 'tt-pipeline');
    }

    public static function tabs(array $tabs, string $active, \moodle_url $base, string $param = 'view'): string {
        $out = '';
        foreach ($tabs as $key => $label) {
            $url = new \moodle_url($base, [$param => $key]);
            $out .= html_writer::link($url, $label, ['class' => 'tt-tab' . ($key === $active ? ' active' : '')]);
        }
        return html_writer::div($out, 'tt-tabs');
    }

    public static function user_line(?\stdClass $user): string {
        return $user ? s(fullname($user)) : '-';
    }

    /** Breadcrumb trail; the last item is the current page. Items: [label, moodle_url|null]. */
    public static function crumbs(array $items): string {
        $out = [];
        $n = count($items);
        foreach ($items as $i => [$label, $url]) {
            $out[] = ($url && $i < $n - 1) ? html_writer::link($url, s($label)) : html_writer::span(s($label), '', ['aria-current' => 'page']);
        }
        return html_writer::tag('nav', implode(html_writer::span('›', 'tt-crumb-sep', ['aria-hidden' => 'true']), $out), ['class' => 'tt-crumbs', 'aria-label' => get_string('navigation')]);
    }

    /** Portal › Student › ... for a project page. $tail: [label, url|null] items after the project. */
    public static function project_crumbs(\stdClass $proj, array $tail = []): string {
        $stu = \core_user::get_user($proj->userid);
        $items = [[get_string('pluginname', 'local_researchcentral'), new \moodle_url('/local/researchcentral/portal.php')],
            [$stu ? fullname($stu) : get_string('untitled', 'local_researchcentral'), new \moodle_url('/local/researchcentral/project.php', ['id' => $proj->id])]];
        return self::crumbs(array_merge($items, $tail));
    }

    /**
     * Dropdown menu of links. $items: [[label, moodle_url], ...] (null entries skipped).
     */
    public static function menu(string $label, array $items, string $class = 'btn-outline-secondary'): string {
        $items = array_filter($items);
        if (!$items) {
            return '';
        }
        $li = '';
        foreach ($items as $it) {
            $li .= html_writer::link($it[1], $it[0], ['class' => 'dropdown-item']);
        }
        return html_writer::div(
            html_writer::tag('button', $label, ['type' => 'button', 'class' => 'btn ' . $class . ' dropdown-toggle',
                'data-bs-toggle' => 'dropdown', 'data-toggle' => 'dropdown', 'aria-haspopup' => 'true', 'aria-expanded' => 'false']) .
            html_writer::div($li, 'dropdown-menu dropdown-menu-end dropdown-menu-right'), 'dropdown d-inline-block tt-menu');
    }

    /** Action bar: primary actions left, menus pushed right. */
    public static function actionbar(array $primary, array $menus = []): string {
        return html_writer::div(
            html_writer::div(implode('', array_filter($primary)), 'tt-ab-main') .
            html_writer::div(implode('', array_filter($menus)), 'tt-ab-more'), 'tt-actionbar');
    }

    /** The one thing this person should do now. */
    public static function hero(string $label, string $text, ?\moodle_url $url, string $button, string $level = 'info'): string {
        return html_writer::div(
            html_writer::div($label, 'tt-label') . html_writer::div($text, 'tt-hero-text') .
            ($url ? html_writer::link($url, $button, ['class' => 'btn btn-primary mt-2']) : ''), 'tt-hero tt-hero-' . $level);
    }

    /** Status colour guide, collapsed by default. */
    public static function legend(): string {
        $items = '';
        foreach (['active', 'review', 'revision', 'completed', 'overdue'] as $st) {
            $items .= html_writer::span(self::pill($st), 'mr-2 mb-1 d-inline-block');
        }
        return html_writer::tag('details', html_writer::tag('summary', get_string('ux_legend', 'local_researchcentral'), ['class' => 'tt-sub']) . html_writer::div($items, 'mt-1'), ['class' => 'tt-legend mb-2']);
    }

    /** A due date with a live countdown (js/tt.js fills the text; the date is the no-JS fallback). */
    public static function due(int $ts): string {
        return html_writer::span(userdate($ts, get_string('strftimedatefullshort', 'langconfig')), 'tt-due', ['data-ts' => $ts]);
    }
}
