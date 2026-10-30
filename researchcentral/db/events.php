<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => '\local_researchcentral\observer::completion_updated',
    ],
    [
        'eventname' => '\assignsubmission_file\event\submission_created',
        'callback' => '\local_researchcentral\observer::submission_changed',
    ],
    [
        'eventname' => '\assignsubmission_file\event\submission_updated',
        'callback' => '\local_researchcentral\observer::submission_changed',
    ],
];
