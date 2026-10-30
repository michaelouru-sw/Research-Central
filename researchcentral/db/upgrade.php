<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_researchcentral_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026101302) {
        // Rules per stage.
        $table = new xmldb_table('local_researchcentral_rules');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('stageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        foreach (['minwords', 'maxwords', 'minpages', 'maxpages', 'fontsize', 'linespacing', 'minmargin',
                'minrefs', 'recentyears', 'minrecent'] as $f) {
            $table->add_field($f, XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        }
        $table->add_field('font', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('headings', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('refstyle', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'none');
        $table->add_field('excluderefs', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('severity', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'warn');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('stageid', XMLDB_KEY_FOREIGN, ['stageid'], 'local_researchcentral_stage', ['id']);
        $table->add_index('stageid_uq', XMLDB_INDEX_UNIQUE, ['stageid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Document check reports.
        $table = new xmldb_table('local_researchcentral_report');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('thesisid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('stageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('filehash', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'error');
        $table->add_field('results', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('thesisid', XMLDB_KEY_FOREIGN, ['thesisid'], 'local_researchcentral_thesis', ['id']);
        $table->add_index('thesis_stage', XMLDB_INDEX_NOTUNIQUE, ['thesisid', 'stageid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026101302, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101303) {
        $table = new xmldb_table('local_researchcentral_course');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN_UNIQUE, ['courseid'], 'course', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        // Courses already using the tracker stay enabled.
        $ids = array_unique(array_merge(
            $DB->get_fieldset_sql('SELECT DISTINCT courseid FROM {local_researchcentral_stage}'),
            $DB->get_fieldset_sql('SELECT DISTINCT courseid FROM {local_researchcentral_thesis}')));
        foreach ($ids as $cid) {
            if (!$DB->record_exists('local_researchcentral_course', ['courseid' => $cid])) {
                $DB->insert_record('local_researchcentral_course', (object)['courseid' => $cid, 'timecreated' => time()]);
            }
        }
        upgrade_plugin_savepoint(true, 2026101303, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101307) {
        $table = new xmldb_table('local_researchcentral_course');
        $field = new xmldb_field('level', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026101307, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101308) {
        $stage = new xmldb_table('local_researchcentral_stage');
        $f = new xmldb_field('progid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($stage, $f)) {
            $dbman->add_field($stage, $f);
        }
        $f = new xmldb_field('needsupload', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        if (!$dbman->field_exists($stage, $f)) {
            $dbman->add_field($stage, $f);
        }
        $rep = new xmldb_table('local_researchcentral_report');
        foreach (['projid', 'verid'] as $name) {
            $f = new xmldb_field($name, XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            if (!$dbman->field_exists($rep, $f)) {
                $dbman->add_field($rep, $f);
            }
        }
        $defs = [
            'local_researchcentral_prog' => function ($t) {
                $t->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
                $t->add_field('school', XMLDB_TYPE_CHAR, '255');
                $t->add_field('level', XMLDB_TYPE_CHAR, '20');
                $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            },
            'local_researchcentral_proj' => function ($t) {
                $t->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('supervisorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('progid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('title', XMLDB_TYPE_CHAR, '255');
                $t->add_field('startdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_index('user_prog', XMLDB_INDEX_UNIQUE, ['userid', 'progid']);
                $t->add_index('supervisor', XMLDB_INDEX_NOTUNIQUE, ['supervisorid']);
            },
            'local_researchcentral_pms' => function ($t) {
                $t->add_field('projid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('stageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('duedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('status', XMLDB_TYPE_CHAR, '12');
                $t->add_field('timesubmitted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('timedecided', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('decisionnote', XMLDB_TYPE_TEXT);
                $t->add_index('proj_stage', XMLDB_INDEX_UNIQUE, ['projid', 'stageid']);
                $t->add_index('status_due', XMLDB_INDEX_NOTUNIQUE, ['status', 'duedate']);
            },
            'local_researchcentral_ver' => function ($t) {
                $t->add_field('pmsid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
                $t->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('note', XMLDB_TYPE_TEXT);
                $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_index('pmsid', XMLDB_INDEX_NOTUNIQUE, ['pmsid']);
            },
            'local_researchcentral_appt' => function ($t) {
                $t->add_field('supervisorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('studentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('projid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('starttime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('endtime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('location', XMLDB_TYPE_CHAR, '255');
                $t->add_field('status', XMLDB_TYPE_CHAR, '10');
                $t->add_field('topic', XMLDB_TYPE_CHAR, '255');
                $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_index('sup_start', XMLDB_INDEX_NOTUNIQUE, ['supervisorid', 'starttime']);
                $t->add_index('student', XMLDB_INDEX_NOTUNIQUE, ['studentid']);
            },
        ];
        foreach ($defs as $name => $build) {
            $t = new xmldb_table($name);
            $t->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $build($t);
            $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            if (!$dbman->table_exists($t)) {
                $dbman->create_table($t);
            }
        }
        upgrade_plugin_savepoint(true, 2026101308, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101309) {
        $stage = new xmldb_table('local_researchcentral_stage');
        foreach (['maxgrade', 'weight'] as $name) {
            $f = new xmldb_field($name, XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
            if (!$dbman->field_exists($stage, $f)) {
                $dbman->add_field($stage, $f);
            }
        }
        $pms = new xmldb_table('local_researchcentral_pms');
        $f = new xmldb_field('grade', XMLDB_TYPE_NUMBER, '6, 2', null, null, null, null);
        if (!$dbman->field_exists($pms, $f)) {
            $dbman->add_field($pms, $f);
        }
        // Existing portal stages that take uploads become marked out of 100.
        $DB->execute("UPDATE {local_researchcentral_stage} SET maxgrade = 100 WHERE progid > 0 AND needsupload = 1 AND maxgrade = 0");
        $defs = [
            'local_researchcentral_msg' => function ($t) {
                $t->add_field('projid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('body', XMLDB_TYPE_TEXT);
                $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_index('projid', XMLDB_INDEX_NOTUNIQUE, ['projid']);
            },
            'local_researchcentral_act' => function ($t) {
                $t->add_field('projid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('action', XMLDB_TYPE_CHAR, '30');
                $t->add_field('detail', XMLDB_TYPE_CHAR, '255');
                $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_index('projid', XMLDB_INDEX_NOTUNIQUE, ['projid']);
            },
            'local_researchcentral_ext' => function ($t) {
                $t->add_field('pmsid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('reason', XMLDB_TYPE_TEXT);
                $t->add_field('requestdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('status', XMLDB_TYPE_CHAR, '10');
                $t->add_field('decidedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $t->add_index('pmsid', XMLDB_INDEX_NOTUNIQUE, ['pmsid']);
            },
        ];
        foreach ($defs as $name => $build) {
            $t = new xmldb_table($name);
            $t->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $build($t);
            $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            if (!$dbman->table_exists($t)) {
                $dbman->create_table($t);
            }
        }
        upgrade_plugin_savepoint(true, 2026101309, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101310) {
        $t = new xmldb_table('local_researchcentral_school');
        $t->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $t->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        if (!$dbman->table_exists($t)) {
            $dbman->create_table($t);
        }
        $prog = new xmldb_table('local_researchcentral_prog');
        $f = new xmldb_field('schoolid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($prog, $f)) {
            $dbman->add_field($prog, $f);
        }
        // Turn schools that were typed earlier into proper school records.
        foreach ($DB->get_records('local_researchcentral_prog') as $g) {
            $name = trim((string)$g->school);
            if ($name === '' || $g->schoolid) {
                continue;
            }
            $sid = 0;
            foreach ($DB->get_records('local_researchcentral_school') as $sc) {
                if (\core_text::strtolower($sc->name) === \core_text::strtolower($name)) {
                    $sid = $sc->id;
                }
            }
            if (!$sid) {
                $sid = $DB->insert_record('local_researchcentral_school', (object)['name' => $name, 'timecreated' => time()]);
            }
            $DB->set_field('local_researchcentral_prog', 'schoolid', $sid, ['id' => $g->id]);
        }
        upgrade_plugin_savepoint(true, 2026101310, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101311) {
        $t = new xmldb_table('local_researchcentral_scoord');
        $t->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $t->add_field('schoolid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $t->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $t->add_index('school_user', XMLDB_INDEX_UNIQUE, ['schoolid', 'userid']);
        $t->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->table_exists($t)) {
            $dbman->create_table($t);
        }
        upgrade_plugin_savepoint(true, 2026101311, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101320) {
        $mk = function (string $name, array $fields, array $indexes) use ($dbman) {
            $t = new xmldb_table($name);
            $t->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            foreach ($fields as $n => [$type, $len]) {
                if ($type === 'i') {
                    $t->add_field($n, XMLDB_TYPE_INTEGER, (string)$len, null, XMLDB_NOTNULL, null, '0');
                } else if ($type === 'c') {
                    $t->add_field($n, XMLDB_TYPE_CHAR, (string)$len, null, null, null, null);
                } else {
                    $t->add_field($n, XMLDB_TYPE_TEXT, null, null, null, null, null);
                }
            }
            $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            foreach ($indexes as $in => [$uniq, $cols]) {
                $t->add_index($in, $uniq ? XMLDB_INDEX_UNIQUE : XMLDB_INDEX_NOTUNIQUE, $cols);
            }
            if (!$dbman->table_exists($t)) {
                $dbman->create_table($t);
            }
        };
        $mk('local_researchcentral_forum', ['type' => ['c', 12], 'refid' => ['i', 10], 'name' => ['c', 255], 'timecreated' => ['i', 10]],
            ['type_ref' => [true, ['type', 'refid']]]);
        $mk('local_researchcentral_thread', ['forumid' => ['i', 10], 'userid' => ['i', 10], 'subject' => ['c', 255], 'message' => ['t', 0],
            'pinned' => ['i', 1], 'locked' => ['i', 1], 'answerpostid' => ['i', 10], 'lastpost' => ['i', 10], 'timecreated' => ['i', 10]],
            ['forumid_last' => [false, ['forumid', 'lastpost']], 'userid' => [false, ['userid']]]);
        $mk('local_researchcentral_post', ['threadid' => ['i', 10], 'userid' => ['i', 10], 'message' => ['t', 0], 'timecreated' => ['i', 10]],
            ['threadid' => [false, ['threadid']], 'userid' => [false, ['userid']]]);
        $mk('local_researchcentral_fread', ['forumid' => ['i', 10], 'userid' => ['i', 10], 'timeread' => ['i', 10]],
            ['forum_user' => [true, ['forumid', 'userid']]]);
        $mk('local_researchcentral_bcast', ['userid' => ['i', 10], 'subject' => ['c', 255], 'body' => ['t', 0], 'audience' => ['c', 255],
            'channel' => ['c', 10], 'total' => ['i', 10], 'sent' => ['i', 10], 'status' => ['c', 10], 'timecreated' => ['i', 10]],
            ['userid' => [false, ['userid']]]);
        $mk('local_researchcentral_bcastu', ['bcastid' => ['i', 10], 'userid' => ['i', 10], 'status' => ['c', 10], 'timesent' => ['i', 10]],
            ['bcastid' => [false, ['bcastid']]]);
        upgrade_plugin_savepoint(true, 2026101320, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101400) {
        $addf = function (string $table, array $defs) use ($dbman) {
            $t = new xmldb_table($table);
            foreach ($defs as $n => [$type, $len]) {
                $f = $type === 'i' ? new xmldb_field($n, XMLDB_TYPE_INTEGER, (string)$len, null, XMLDB_NOTNULL, null, '0')
                    : ($type === 'c' ? new xmldb_field($n, XMLDB_TYPE_CHAR, (string)$len, null, null, null, null)
                    : new xmldb_field($n, XMLDB_TYPE_TEXT, null, null, null, null, null));
                if (!$dbman->field_exists($t, $f)) {
                    $dbman->add_field($t, $f);
                }
            }
        };
        $addf('local_researchcentral_appt', ['platform' => ['c', 12], 'joinurl' => ['c', 255], 'cmid' => ['i', 10], 'agenda' => ['t', 0],
            'supnote' => ['t', 0], 'minutes' => ['t', 0], 'allocated' => ['i', 1], 'studentconf' => ['i', 1], 'supconf' => ['i', 1],
            'studentconftime' => ['i', 10], 'supconftime' => ['i', 10], 'studentjoin' => ['i', 10], 'supjoin' => ['i', 10],
            'rem24' => ['i', 10], 'rem1' => ['i', 10], 'confasked' => ['i', 10], 'eventstu' => ['i', 10], 'eventsup' => ['i', 10]]);
        $addf('local_researchcentral_ext', ['recommendedby' => ['i', 10], 'recommendedtime' => ['i', 10], 'recommendnote' => ['t', 0],
            'confirmedby' => ['i', 10], 'confirmedtime' => ['i', 10], 'confirmnote' => ['t', 0]]);
        $addf('local_researchcentral_pms', ['firstresponse' => ['i', 10]]);

        $mk = function (string $name, array $fields, array $indexes) use ($dbman) {
            $t = new xmldb_table($name);
            $t->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            foreach ($fields as $n => [$type, $len]) {
                if ($type === 'i') {
                    $t->add_field($n, XMLDB_TYPE_INTEGER, (string)$len, null, XMLDB_NOTNULL, null, '0');
                } else if ($type === 'c') {
                    $t->add_field($n, XMLDB_TYPE_CHAR, (string)$len, null, null, null, null);
                } else if ($type === 'n') {
                    $t->add_field($n, XMLDB_TYPE_NUMBER, '6, 2', null, null, null, null);
                } else {
                    $t->add_field($n, XMLDB_TYPE_TEXT, null, null, null, null, null);
                }
            }
            $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            foreach ($indexes as $in => [$uniq, $cols]) {
                $t->add_index($in, $uniq ? XMLDB_INDEX_UNIQUE : XMLDB_INDEX_NOTUNIQUE, $cols);
            }
            if (!$dbman->table_exists($t)) {
                $dbman->create_table($t);
            }
        };
        $mk('local_researchcentral_cosup', ['projid' => ['i', 10], 'userid' => ['i', 10], 'timecreated' => ['i', 10]],
            ['proj_user' => [true, ['projid', 'userid']], 'userid' => [false, ['userid']]]);
        $mk('local_researchcentral_review', ['projid' => ['i', 10], 'pmsid' => ['i', 10], 'verid' => ['i', 10], 'round' => ['i', 10],
            'userid' => ['i', 10], 'recommendation' => ['c', 8], 'grade' => ['n', 0], 'comment' => ['t', 0], 'confidential' => ['t', 0],
            'status' => ['c', 10], 'timecreated' => ['i', 10]],
            ['pms_round' => [false, ['pmsid', 'round']], 'projid' => [false, ['projid']], 'userid' => [false, ['userid']]]);
        $mk('local_researchcentral_decision', ['projid' => ['i', 10], 'pmsid' => ['i', 10], 'verid' => ['i', 10], 'round' => ['i', 10],
            'decision' => ['c', 10], 'grade' => ['n', 0], 'letter' => ['t', 0], 'decidedby' => ['i', 10], 'mode' => ['c', 12],
            'timesubmitted' => ['i', 10], 'firstresponse' => ['i', 10], 'timecreated' => ['i', 10]],
            ['pmsid' => [false, ['pmsid']], 'projid' => [false, ['projid']]]);

        // Backfill history: earlier single-supervisor decisions become decision records so reports and QA are complete.
        $rows = $DB->get_recordset_select('local_researchcentral_pms', "status IN ('approved','revision') AND timedecided > 0");
        foreach ($rows as $m) {
            if ($DB->record_exists('local_researchcentral_decision', ['pmsid' => $m->id])) {
                continue;
            }
            $verid = (int)$DB->get_field_sql('SELECT MAX(id) FROM {local_researchcentral_ver} WHERE pmsid = ?', [$m->id]);
            $DB->insert_record('local_researchcentral_decision', (object)['projid' => $m->projid, 'pmsid' => $m->id, 'verid' => $verid,
                'round' => 1, 'decision' => $m->status === 'approved' ? 'approve' : 'revision', 'grade' => $m->grade,
                'letter' => (string)$m->decisionnote, 'decidedby' => 0, 'mode' => 'legacy', 'timesubmitted' => $m->timesubmitted,
                'firstresponse' => $m->timedecided, 'timecreated' => $m->timedecided]);
        }
        $rows->close();
        upgrade_plugin_savepoint(true, 2026101400, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101500) {
        $t = new xmldb_table('local_researchcentral_receipt');
        $t->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        foreach (['projid', 'userid', 'itemid', 'firsttime', 'lasttime'] as $f) {
            $t->add_field($f, XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        }
        $t->add_field('kind', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'msg');
        $t->add_field('hits', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $t->add_index('kind_item_user', XMLDB_INDEX_UNIQUE, ['kind', 'itemid', 'userid']);
        $t->add_index('projid', XMLDB_INDEX_NOTUNIQUE, ['projid']);
        if (!$dbman->table_exists($t)) {
            $dbman->create_table($t);
        }
        // Messages written before this release are treated as already read; receipts start from now.
        set_config('receipts_since', time(), 'local_researchcentral');
        upgrade_plugin_savepoint(true, 2026101500, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101600) {
        $mk = function (string $name, array $fields, array $indexes) use ($dbman) {
            $t = new xmldb_table($name);
            $t->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            foreach ($fields as $n => [$type, $len]) {
                if ($type === 'i') {
                    $t->add_field($n, XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                } else if ($type === 'c') {
                    $t->add_field($n, XMLDB_TYPE_CHAR, (string)$len, null, null, null, null);
                } else {
                    $t->add_field($n, XMLDB_TYPE_TEXT, null, null, null, null, null);
                }
            }
            $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            foreach ($indexes as $in => [$uniq, $cols]) {
                $t->add_index($in, $uniq ? XMLDB_INDEX_UNIQUE : XMLDB_INDEX_NOTUNIQUE, $cols);
            }
            if (!$dbman->table_exists($t)) {
                $dbman->create_table($t);
            }
        };
        $mk('local_researchcentral_fbresp', ['projid' => ['i', 10], 'reviewid' => ['i', 10], 'itemno' => ['i', 10], 'response' => ['t', 0], 'status' => ['c', 10], 'verified' => ['i', 10], 'vernote' => ['t', 0], 'verby' => ['i', 10], 'vertime' => ['i', 10], 'timecreated' => ['i', 10], 'timemodified' => ['i', 10]],
            ['review_item' => [true, ['reviewid', 'itemno']], 'projid' => [false, ['projid']]]);
        $mk('local_researchcentral_permit', ['projid' => ['i', 10], 'type' => ['c', 20], 'reference' => ['c', 100], 'authority' => ['c', 100], 'status' => ['c', 12], 'issued' => ['i', 10], 'expiry' => ['i', 10], 'note' => ['t', 0], 'remind' => ['i', 10], 'timecreated' => ['i', 10], 'timemodified' => ['i', 10]],
            ['projid' => [false, ['projid']]]);
        $mk('local_researchcentral_pub', ['projid' => ['i', 10], 'type' => ['c', 20], 'title' => ['c', 255], 'venue' => ['c', 255], 'status' => ['c', 12], 'doi' => ['c', 255], 'year' => ['i', 10], 'timecreated' => ['i', 10], 'timemodified' => ['i', 10]],
            ['projid' => [false, ['projid']]]);
        $mk('local_researchcentral_checkin', ['projid' => ['i', 10], 'userid' => ['i', 10], 'period' => ['c', 7], 'progress' => ['i', 10], 'blockers' => ['t', 0], 'plans' => ['t', 0], 'needsupport' => ['i', 10], 'supcomment' => ['t', 0], 'supseen' => ['i', 10], 'timecreated' => ['i', 10]],
            ['proj_period' => [true, ['projid', 'period']]]);
        $mk('local_researchcentral_viva', ['projid' => ['i', 10], 'starttime' => ['i', 10], 'endtime' => ['i', 10], 'venue' => ['c', 255], 'joinurl' => ['c', 255], 'status' => ['c', 12], 'outcome' => ['c', 16], 'corrections' => ['t', 0], 'corrdeadline' => ['i', 10], 'corrdone' => ['i', 10], 'corrnote' => ['t', 0], 'signedby' => ['i', 10], 'signedtime' => ['i', 10], 'remind' => ['i', 10], 'timecreated' => ['i', 10]],
            ['projid' => [false, ['projid']]]);
        $mk('local_researchcentral_vivapanel', ['vivaid' => ['i', 10], 'userid' => ['i', 10], 'name' => ['c', 255], 'role' => ['c', 12], 'recommendation' => ['c', 16], 'report' => ['t', 0], 'timecreated' => ['i', 10]],
            ['vivaid' => [false, ['vivaid']]]);
        $mk('local_researchcentral_event', ['title' => ['c', 255], 'descr' => ['t', 0], 'starttime' => ['i', 10], 'endtime' => ['i', 10], 'venue' => ['c', 255], 'joinurl' => ['c', 255], 'progid' => ['i', 10], 'level' => ['c', 12], 'capacity' => ['i', 10], 'createdby' => ['i', 10], 'timecreated' => ['i', 10]],
            ['starttime' => [false, ['starttime']]]);
        $mk('local_researchcentral_eventreg', ['eventid' => ['i', 10], 'userid' => ['i', 10], 'status' => ['c', 12], 'timecreated' => ['i', 10]],
            ['event_user' => [true, ['eventid', 'userid']]]);
        $mk('local_researchcentral_snip', ['userid' => ['i', 10], 'title' => ['c', 255], 'body' => ['t', 0], 'timecreated' => ['i', 10]],
            ['userid' => [false, ['userid']]]);
        $vt = new xmldb_table('local_researchcentral_ver');
        $f1 = new xmldb_field('simpct', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '-1');
        if (!$dbman->field_exists($vt, $f1)) {
            $dbman->add_field($vt, $f1);
        }
        $f2 = new xmldb_field('simnote', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        if (!$dbman->field_exists($vt, $f2)) {
            $dbman->add_field($vt, $f2);
        }
        upgrade_plugin_savepoint(true, 2026101600, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101601) {
        upgrade_plugin_savepoint(true, 2026101601, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101602) {
        upgrade_plugin_savepoint(true, 2026101602, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101603) {
        upgrade_plugin_savepoint(true, 2026101603, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101604) {
        $t = new xmldb_table('local_researchcentral_ann');
        $t->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        foreach (['projid', 'pmsid', 'verid', 'parentid', 'paraidx', 'userid', 'timecreated', 'timemodified'] as $f) {
            $t->add_field($f, XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        }
        $t->add_field('parahash', XMLDB_TYPE_CHAR, '40', null, null);
        $t->add_field('quote', XMLDB_TYPE_CHAR, '255', null, null);
        $t->add_field('body', XMLDB_TYPE_TEXT, null, null, null);
        $t->add_field('private', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $t->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'open');
        $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        foreach (['verid', 'pmsid', 'projid', 'parentid'] as $ix) {
            $t->add_index($ix, XMLDB_INDEX_NOTUNIQUE, [$ix]);
        }
        if (!$dbman->table_exists($t)) {
            $dbman->create_table($t);
        }
        upgrade_plugin_savepoint(true, 2026101604, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101605) {
        upgrade_plugin_savepoint(true, 2026101605, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101606) {
        upgrade_plugin_savepoint(true, 2026101606, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101607) {
        upgrade_plugin_savepoint(true, 2026101607, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101700) {
        // v2.0.0: comment-to-review links, supervision meeting log, external examiners, generated documents, ethics, repository handoff.
        $t = new xmldb_table('local_researchcentral_ann');
        foreach (['queued' => '10', 'reviewid' => '10', 'itemno' => '10', 'examid' => '10'] as $fname => $len) {
            $fld = new xmldb_field($fname, XMLDB_TYPE_INTEGER, $len, null, XMLDB_NOTNULL, null, '0');
            if (!$dbman->field_exists($t, $fld)) {
                $dbman->add_field($t, $fld);
            }
        }
        foreach (['meet', 'mact', 'exam', 'doc', 'eth', 'repo'] as $tn) {
            $tbl = new xmldb_table('local_researchcentral_' . $tn);
            if (!$dbman->table_exists($tbl)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_researchcentral_' . $tn);
            }
        }
        upgrade_plugin_savepoint(true, 2026101700, 'local', 'researchcentral');
    }
    if ($oldversion < 2026101701) {
        // v2.1.0: DOCX-only uploads with adjustable size limits, separate figure slots, comments on figures.
        foreach (['local_researchcentral_stage' => 'needsupload', 'local_researchcentral_proj' => 'startdate'] as $tn => $after) {
            $tbl = new xmldb_table($tn);
            $fld = new xmldb_field('maxmb', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
            if (!$dbman->field_exists($tbl, $fld)) {
                $dbman->add_field($tbl, $fld);
            }
        }
        $tbl = new xmldb_table('local_researchcentral_ann');
        $fld = new xmldb_field('figid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($tbl, $fld)) {
            $dbman->add_field($tbl, $fld);
        }
        $tbl = new xmldb_table('local_researchcentral_fig');
        if (!$dbman->table_exists($tbl)) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_researchcentral_fig');
        }
        upgrade_plugin_savepoint(true, 2026101701, 'local', 'researchcentral');
    }
    return true;
}
