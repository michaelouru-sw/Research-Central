<?php
namespace local_researchcentral\local;

defined('MOODLE_INTERNAL') || die();

/** Downloadable commented copies of a submitted document: Word comments injected into the .docx, or a PDF report. */
class commented {
    const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    const REL_COMMENTS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments';
    const CT_COMMENTS = 'application/vnd.openxmlformats-officedocument.wordprocessingml.comments+xml';

    /** The stored submission file of a version, or null. */
    public static function file(int $verid): ?\stored_file {
        $files = get_file_storage()->get_area_files(\context_system::instance()->id, 'local_researchcentral', 'submission', $verid, 'filename', false);
        return $files ? reset($files) : null;
    }

    /** Plain-text lines of one thread: header, quote, body, replies. */
    public static function thread_lines(\stdClass $root, array $replies, callable $name, ?string $note = null): array {
        $dt = fn(int $t) => userdate($t, get_string('strftimedatetimeshort', 'langconfig'));
        $L = fn(string $k, $a = null) => get_string($k, 'local_researchcentral', $a);
        $lines = [$name((int)$root->userid) . ' · ' . $dt((int)$root->timecreated) . ' · ' . $L('ann_st_' . $root->status) .
            ($root->private ? ' · ' . $L('ann_private') : '')];
        if ($note) {
            $lines[] = $note;
        }
        if ((string)$root->quote !== '') {
            $lines[] = '“' . $root->quote . '”';
        }
        foreach (preg_split('/\R/u', (string)$root->body) as $l) {
            $lines[] = $l;
        }
        foreach ($replies as $r) {
            $lines[] = '↩ ' . $name((int)$r->userid) . ' · ' . $dt((int)$r->timecreated);
            foreach (preg_split('/\R/u', (string)$r->body) as $l) {
                $lines[] = $l;
            }
        }
        return $lines;
    }

    /**
     * Copy of the original .docx with the threads inserted as Word comments.
     * @param array $byPara paragraph index => root comments; $orphans roots whose paragraph is gone
     * @return string path of the temporary file
     */
    public static function docx(string $src, array $byPara, array $orphans, array $replies, callable $name): string {
        $out = make_request_directory() . '/commented.docx';
        copy($src, $out);
        $zip = new \ZipArchive();
        if ($zip->open($out) !== true) {
            throw new \moodle_exception('compare_unreadable', 'local_researchcentral');
        }
        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false || strlen($xml) > 83886080) {
            $zip->close();
            throw new \moodle_exception('compare_unreadable', 'local_researchcentral');
        }
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
            $zip->close();
            throw new \moodle_exception('compare_unreadable', 'local_researchcentral');
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', self::NS);

        // Existing comments part (a document may already carry the author's own comments).
        $cxml = $zip->getFromName('word/comments.xml');
        $cdom = new \DOMDocument();
        if ($cxml !== false && @$cdom->loadXML($cxml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
            $croot = $cdom->documentElement;
        } else {
            $cdom->loadXML('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:comments xmlns:w="' . self::NS . '"/>');
            $croot = $cdom->documentElement;
        }
        $cxp = new \DOMXPath($cdom);
        $cxp->registerNamespace('w', self::NS);
        $nextid = 0;
        foreach ($cxp->query('//w:comment') as $c) {
            $nextid = max($nextid, (int)$c->getAttributeNS(self::NS, 'id') + 1);
        }

        // Threads on text that has since changed stay margin comments, pinned to the first paragraph.
        $orph = [];
        foreach ($orphans as $root) {
            $byPara[0][] = $root;
            $orph[(int)$root->id] = true;
        }
        $mkcomment = function (\stdClass $root) use ($cdom, $croot, $replies, $name, &$nextid, $orph): int {
            $id = $nextid++;
            $c = $cdom->createElementNS(self::NS, 'w:comment');
            $c->setAttributeNS(self::NS, 'w:id', (string)$id);
            $c->setAttributeNS(self::NS, 'w:author', $name((int)$root->userid));
            $c->setAttributeNS(self::NS, 'w:date', gmdate('Y-m-d\TH:i:s\Z', (int)$root->timecreated));
            $initials = implode('', array_map(fn($w) => \core_text::strtoupper(\core_text::substr($w, 0, 1)), array_slice(preg_split('/\s+/u', trim($name((int)$root->userid))), 0, 2)));
            $c->setAttributeNS(self::NS, 'w:initials', $initials);
            foreach (self::thread_lines($root, $replies[(int)$root->id] ?? [], $name, isset($orph[(int)$root->id]) ? get_string('dl_orphan_note', 'local_researchcentral') : null) as $line) {
                $p = $cdom->createElementNS(self::NS, 'w:p');
                $r = $cdom->createElementNS(self::NS, 'w:r');
                $t = $cdom->createElementNS(self::NS, 'w:t');
                $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                $t->appendChild($cdom->createTextNode($line));
                $r->appendChild($t);
                $p->appendChild($r);
                $c->appendChild($p);
            }
            $croot->appendChild($c);
            return $id;
        };

        // Map paragraph index (non-empty top-level paragraphs, same rule as the reader) to its node.
        $idx = 0;
        $placed = 0;
        foreach ($xp->query('/w:document/w:body/w:p') as $p) {
            $text = '';
            foreach ($xp->query('.//w:r', $p) as $r) {
                foreach ($xp->query('w:t | w:tab', $r) as $node) {
                    $text .= ($node->localName === 'tab') ? ' ' : $node->textContent;
                }
            }
            if (trim($text) === '') {
                continue;
            }
            foreach ($byPara[$idx] ?? [] as $root) {
                $id = $mkcomment($root);
                $start = $dom->createElementNS(self::NS, 'w:commentRangeStart');
                $start->setAttributeNS(self::NS, 'w:id', (string)$id);
                $first = $p->firstChild;
                if ($first && $first->localName === 'pPr' && $first->namespaceURI === self::NS) {
                    $first = $first->nextSibling;
                }
                $first ? $p->insertBefore($start, $first) : $p->appendChild($start);
                $end = $dom->createElementNS(self::NS, 'w:commentRangeEnd');
                $end->setAttributeNS(self::NS, 'w:id', (string)$id);
                $p->appendChild($end);
                $run = $dom->createElementNS(self::NS, 'w:r');
                $ref = $dom->createElementNS(self::NS, 'w:commentReference');
                $ref->setAttributeNS(self::NS, 'w:id', (string)$id);
                $run->appendChild($ref);
                $p->appendChild($run);
                $placed++;
            }
            $idx++;
        }

        if (!$placed) {
            // Nothing to add: return the original untouched.
            $zip->close();
            return $out;
        }

        // Register the comments part.
        $zip->addFromString('word/document.xml', $dom->saveXML());
        $zip->addFromString('word/comments.xml', $cdom->saveXML());
        $rels = $zip->getFromName('word/_rels/document.xml.rels');
        if ($rels !== false && stripos($rels, 'relationships/comments"') === false) {
            $zip->addFromString('word/_rels/document.xml.rels', str_replace('</Relationships>',
                '<Relationship Id="rIdTTcomments" Type="' . self::REL_COMMENTS . '" Target="comments.xml"/></Relationships>', $rels));
        }
        $ct = $zip->getFromName('[Content_Types].xml');
        if ($ct !== false && stripos($ct, '/word/comments.xml') === false) {
            $zip->addFromString('[Content_Types].xml', str_replace('</Types>',
                '<Override PartName="/word/comments.xml" ContentType="' . self::CT_COMMENTS . '"/></Types>', $ct));
        }
        $zip->close();
        return $out;
    }

    /** A minimal .docx rebuilt from extracted paragraphs, so PDF and text submissions can carry margin comments too. */
    public static function plain_docx(array $paras): string {
        $out = make_request_directory() . '/plain.docx';
        $zip = new \ZipArchive();
        $zip->open($out, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $w = 'xmlns:w="' . self::NS . '"';
        $body = '';
        foreach ($paras as $t) {
            $body .= '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($t, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r></w:p>';
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $w . '><w:body>' . $body . '</w:body></w:document>');
        $zip->close();
        return $out;
    }

    /**
     * PDF report: the text with the comment threads under each paragraph.
     * @param bool $onlycommented list only paragraphs that have comments
     */
    public static function pdf(\stdClass $meta, ?array $paras, array $byPara, array $orphans, array $replies, callable $name, bool $onlycommented): string {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');
        $e = fn(string $s) => s($s);
        $L = fn(string $k, $a = null) => get_string($k, 'local_researchcentral', $a);
        $dt = fn(int $t) => userdate($t, get_string('strftimedatetimeshort', 'langconfig'));
        $thread = function (\stdClass $root) use ($replies, $name, $e, $dt, $L): string {
            $col = ['open' => '#8a4b00', 'addressed' => '#0b4a9e', 'resolved' => '#17603a'][$root->status] ?? '#333333';
            $h = '<table cellpadding="5" cellspacing="0" border="0" bgcolor="#f1f5f9" width="100%"><tr><td>' .
                '<b>' . $e($name((int)$root->userid)) . '</b> <font color="#667788">' . $e($dt((int)$root->timecreated)) . '</font> ' .
                '<font color="' . $col . '"><b>[' . $e($L('ann_st_' . $root->status)) . ']</b></font>' .
                ($root->private ? ' <font color="#667788">[' . $e($L('ann_private')) . ']</font>' : '');
            if ((string)$root->quote !== '') {
                $h .= '<br/><i><font color="#667788">“' . $e($root->quote) . '”</font></i>';
            }
            $h .= '<br/>' . nl2br($e($root->body));
            foreach ($replies[(int)$root->id] ?? [] as $r) {
                $h .= '<br/><br/><b>↩ ' . $e($name((int)$r->userid)) . '</b> <font color="#667788">' . $e($dt((int)$r->timecreated)) . '</font><br/>' . nl2br($e($r->body));
            }
            return $h . '</td></tr></table><br/>';
        };

        $pdf = new \pdf();
        $pdf->SetCreator('Research Central');
        $pdf->SetTitle($meta->title);
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(true);
        $pdf->SetMargins(18, 16, 18);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->SetFont('freeserif', '', 11);
        $pdf->AddPage();

        $total = 0;
        foreach ($byPara as $cs) {
            $total += count($cs);
        }
        $total += count($orphans);
        $html = '<h2>' . $e($meta->title) . '</h2>' .
            '<p><b>' . $e($meta->student) . '</b> · ' . $e($meta->stage) . ' · ' . $e($meta->version) . '<br/>' .
            $e($L('dl_generated', (object)['who' => $meta->who, 'when' => $dt(time())])) . ' · ' . $e($L('dl_count', $total)) .
            ($meta->staffcopy ? ' · <b>' . $e($L('dl_staffcopy')) . '</b>' : '') . '</p><hr/>';
        if ($orphans) {
            $html .= '<h4>' . $e($L('ann_orphans')) . '</h4>';
            foreach ($orphans as $root) {
                $html .= $thread($root);
            }
            $html .= '<hr/>';
        }
        if ($paras === null) {
            $html .= '<p>' . $e($L('read_unreadable')) . '</p>';
            foreach ($byPara as $cs) {
                foreach ($cs as $root) {
                    $html .= $thread($root);
                }
            }
        } else {
            foreach ($paras as $i => $text) {
                $cs = $byPara[$i] ?? [];
                if ($onlycommented && !$cs) {
                    continue;
                }
                $html .= '<p><font color="#667788" size="8">' . ($i + 1) . '</font>&nbsp;&nbsp;' . ($cs ? '<u>' : '') . $e($text) . ($cs ? '</u>' : '') . '</p>';
                foreach ($cs as $root) {
                    $html .= $thread($root);
                }
            }
        }
        $pdf->writeHTML($html, true, false, true, false, '');
        return $pdf->Output('commented.pdf', 'S');
    }
}
