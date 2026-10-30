<?php
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\navigation\primary_extend::class,
        'callback' => [\local_researchcentral\hook_callbacks::class, 'primary_extend'],
    ],
    [
        'hook' => \core\hook\output\before_standard_head_html_generation::class,
        'callback' => [\local_researchcentral\hook_callbacks::class, 'plugin_css'],
    ],
    [
        'hook' => \core\hook\output\before_standard_top_of_body_html_generation::class,
        'callback' => [\local_researchcentral\hook_callbacks::class, 'ux_toolbar'],
    ],
];
