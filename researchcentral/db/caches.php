<?php
defined('MOODLE_INTERNAL') || die();

$definitions = [
    'leadership' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'staticacceleration' => false,
        'ttl' => 7200,
    ],
    // Risk scores for large coordinator dashboards (two minutes).
    'risk' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'staticacceleration' => true,
        'staticaccelerationsize' => 4,
        'ttl' => 120,
    ],
    // Which Research Central roles a user has; read on every page for the navigation link (five minutes).
    'roles' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
        'ttl' => 300,
    ],
];
