<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/** Grade sheets: Student Name, Email, one column per stage, total score. One sheet per programme. */
class gradesheet {
    /**
     * @param \stdClass[] $projects from portal::projects()
     * @param bool $percent show each stage as a percentage of its maximum
     * @param bool $allstages include stages that carry no mark
     * @return array programme name => ['head' => string[], 'rows' => array[]]
     */
    public static function build(array $projects, bool $percent, bool $allstages): array {
        $byprog = [];
        foreach ($projects as $p) {
            $byprog[$p->progid][] = $p;
        }
        $sheets = [];
        foreach ($byprog as $plist) {
            $first = reset($plist);
            $stages = [];
            foreach ($first->ms as $m) {
                if ($allstages || (int)$m->maxgrade > 0) {
                    $stages[(int)$m->stageid] = $m;
                }
            }
            $head = ['Student Name', 'Email'];
            foreach ($stages as $m) {
                $head[] = format_string($m->stagename) . ((int)$m->maxgrade > 0 && !$percent ? ' (/' . (int)$m->maxgrade . ')' : ($percent && (int)$m->maxgrade > 0 ? ' (%)' : ''));
            }
            array_push($head, 'Total marks', 'Total possible', 'Total score (%)');
            $rows = [];
            foreach ($plist as $p) {
                $row = [$p->student ? fullname($p->student) : '', $p->student->email ?? ''];
                $got = $possible = 0.0;
                $bystage = [];
                foreach ($p->ms as $m) {
                    $bystage[(int)$m->stageid] = $m;
                }
                foreach ($stages as $sid => $sm) {
                    $m = $bystage[$sid] ?? null;
                    $max = (int)$sm->maxgrade;
                    if ($max > 0) {
                        if ($m && $m->grade !== null) {
                            $row[] = $percent ? round(100 * $m->grade / $max, 1) : round((float)$m->grade, 2);
                            $got += (float)$m->grade;
                            $possible += $max;
                        } else {
                            $row[] = '';
                        }
                    } else {
                        $row[] = $m ? get_string('state_' . $m->status, 'local_researchcentral') : '';
                    }
                }
                $ov = portal::overall($p->ms);
                array_push($row, $possible > 0 ? round($got, 2) : '', $possible > 0 ? $possible : '', $ov ? round($ov['percent'], 1) : '');
                $rows[] = $row;
            }
            usort($rows, fn($a, $b) => strcasecmp($a[0], $b[0]));
            $name = $first->school ? $first->progname . ' - ' . $first->school : $first->progname;
            $sheets[$name] = ['head' => $head, 'rows' => $rows];
        }
        ksort($sheets);
        return $sheets;
    }

    /** One table for CSV: stage columns merged by name across programmes; programme column added when needed. */
    public static function merge(array $sheets): array {
        if (count($sheets) <= 1) {
            $s = $sheets ? reset($sheets) : ['head' => ['Student Name', 'Email', 'Total marks', 'Total possible', 'Total score (%)'], 'rows' => []];
            return [$s['head'], $s['rows']];
        }
        $stagecols = [];
        foreach ($sheets as $s) {
            foreach (array_slice($s['head'], 2, -3) as $h) {
                $stagecols[$h] = true;
            }
        }
        $stagecols = array_keys($stagecols);
        $head = array_merge(['Student Name', 'Email', 'Programme'], $stagecols, ['Total marks', 'Total possible', 'Total score (%)']);
        $rows = [];
        foreach ($sheets as $name => $s) {
            $cols = array_slice($s['head'], 2, -3);
            foreach ($s['rows'] as $r) {
                $map = array_combine($cols, array_slice($r, 2, count($cols)));
                $row = [$r[0], $r[1], $name];
                foreach ($stagecols as $c) {
                    $row[] = $map[$c] ?? '';
                }
                $rows[] = array_merge($row, array_slice($r, -3));
            }
        }
        return [$head, $rows];
    }
}
