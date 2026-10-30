<?php
namespace local_researchcentral\checker;

/**
 * Reads a .docx file (pure PHP, no Moodle calls) into paragraphs with formatting facts.
 */
class docx_reader {
    const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    const MAX_XML = 83886080; // 80 MB uncompressed guard against zip bombs.

    public static function read(string $path): ?\stdClass {
        if (!class_exists('\ZipArchive')) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $stat = $zip->statName('word/document.xml');
        if (!$stat || $stat['size'] > self::MAX_XML) {
            $zip->close();
            return null;
        }
        $docxml = $zip->getFromName('word/document.xml');
        $stylesxml = $zip->getFromName('word/styles.xml') ?: '';
        $themexml = $zip->getFromName('word/theme/theme1.xml') ?: '';
        $appxml = $zip->getFromName('docProps/app.xml') ?: '';
        $zip->close();
        if ($docxml === false) {
            return null;
        }

        $theme = ['minor' => null, 'major' => null];
        if (preg_match('/<a:minorFont>\s*<a:latin typeface="([^"]*)"/', $themexml, $m)) {
            $theme['minor'] = $m[1];
        }
        if (preg_match('/<a:majorFont>\s*<a:latin typeface="([^"]*)"/', $themexml, $m)) {
            $theme['major'] = $m[1];
        }

        [$styles, $defaults] = self::parse_styles($stylesxml, $theme);

        $xp = self::load($docxml);
        if (!$xp) {
            return null;
        }

        $paragraphs = [];
        foreach ($xp->query('/w:document/w:body/w:p') as $p) {
            $styleid = self::attr($xp, 'w:pPr/w:pStyle', $p) ?? $defaults['pstyle'];
            $stylename = $styleid && isset($styles[$styleid]) ? strtolower($styles[$styleid]['name']) : '';
            $isheading = (bool)preg_match('/^(heading\s*\d|title|subtitle)/', $stylename)
                || self::attr($xp, 'w:pPr/w:outlineLvl', $p) !== null;
            $istoc = (bool)preg_match('/^(toc|table of|index)/', $stylename);

            $text = '';
            $fonts = [];
            $sizes = [];
            foreach ($xp->query('.//w:r', $p) as $r) {
                $rt = '';
                foreach ($xp->query('w:t | w:tab', $r) as $node) {
                    $rt .= ($node->localName === 'tab') ? ' ' : $node->textContent;
                }
                if ($rt === '') {
                    continue;
                }
                $text .= $rt;
                $len = mb_strlen($rt);
                $font = self::font_of($xp, $xp->query('w:rPr/w:rFonts', $r)->item(0), $theme)
                    ?? self::resolve($styles, $styleid, 'font') ?? $defaults['font'];
                $szraw = self::attr($xp, 'w:rPr/w:sz', $r);
                $size = $szraw !== null ? ((float)$szraw / 2) : (self::resolve($styles, $styleid, 'size') ?? $defaults['size']);
                $fonts[$font ?? '?'] = ($fonts[$font ?? '?'] ?? 0) + $len;
                $sizes[(string)($size ?? '?')] = ($sizes[(string)($size ?? '?')] ?? 0) + $len;
            }
            if (trim($text) === '') {
                continue;
            }

            $spacing = null;
            $lineraw = self::attr($xp, 'w:pPr/w:spacing', $p, 'line');
            $rule = self::attr($xp, 'w:pPr/w:spacing', $p, 'lineRule');
            if ($lineraw !== null && ($rule === null || $rule === 'auto')) {
                $spacing = round((float)$lineraw / 240, 2);
            } else if ($lineraw === null) {
                $spacing = self::resolve($styles, $styleid, 'line') ?? $defaults['line'];
            }

            $paragraphs[] = [
                'text' => trim($text),
                'heading' => $isheading,
                'toc' => $istoc,
                'fonts' => $fonts,
                'sizes' => $sizes,
                'spacing' => $spacing,
                'chars' => mb_strlen($text),
            ];
        }

        // Page margins (smallest across sections), in millimetres. 1 mm = 56.693 twips.
        $minmargin = null;
        foreach ($xp->query('//w:sectPr/w:pgMar') as $mar) {
            foreach (['left', 'right', 'top', 'bottom'] as $side) {
                $v = $mar->getAttributeNS(self::NS, $side);
                if ($v !== '' && is_numeric($v)) {
                    $mm = (float)$v / 56.693;
                    $minmargin = $minmargin === null ? $mm : min($minmargin, $mm);
                }
            }
        }

        $pages = null;
        if (preg_match('/<Pages>(\d+)<\/Pages>/', $appxml, $m)) {
            $pages = (int)$m[1];
        }

        return (object)[
            'type' => 'docx',
            'paragraphs' => $paragraphs,
            'format' => true,
            'minmargin' => $minmargin === null ? null : round($minmargin, 1),
            'pages' => $pages,
        ];
    }

    private static function load(string $xml): ?\DOMXPath {
        $dom = new \DOMDocument();
        if ($xml === '' || !@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
            return null;
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', self::NS);
        return $xp;
    }

    private static function attr(\DOMXPath $xp, string $query, ?\DOMNode $ctx, string $name = 'val'): ?string {
        $n = $xp->query($query, $ctx)->item(0);
        if (!$n) {
            return null;
        }
        $v = $n->getAttributeNS(self::NS, $name);
        return $v === '' ? null : $v;
    }

    private static function font_of(\DOMXPath $xp, ?\DOMNode $rfonts, array $theme): ?string {
        if (!$rfonts) {
            return null;
        }
        $ascii = $rfonts->getAttributeNS(self::NS, 'ascii');
        if ($ascii !== '') {
            return $ascii;
        }
        $t = $rfonts->getAttributeNS(self::NS, 'asciiTheme');
        if ($t !== '') {
            return stripos($t, 'major') === 0 ? $theme['major'] : $theme['minor'];
        }
        return null;
    }

    private static function parse_styles(string $xml, array $theme): array {
        $styles = [];
        $defaults = ['font' => null, 'size' => null, 'line' => 1.0, 'pstyle' => null];
        $xp = self::load($xml);
        if (!$xp) {
            return [$styles, $defaults];
        }
        $dn = $xp->query("/w:styles/w:style[@w:type='paragraph'][@w:default='1']")->item(0);
        if ($dn) {
            $d = $dn->getAttributeNS(self::NS, 'styleId');
            $defaults['pstyle'] = $d !== '' ? $d : null;
        }
        $defaults['font'] = self::font_of($xp, $xp->query('/w:styles/w:docDefaults/w:rPrDefault/w:rPr/w:rFonts')->item(0), $theme);
        $sz = self::attr($xp, '/w:styles/w:docDefaults/w:rPrDefault/w:rPr/w:sz', null);
        $defaults['size'] = $sz !== null ? (float)$sz / 2 : null;
        $ln = self::attr($xp, '/w:styles/w:docDefaults/w:pPrDefault/w:pPr/w:spacing', null, 'line');
        $defaults['line'] = $ln !== null ? round((float)$ln / 240, 2) : 1.0;

        foreach ($xp->query('/w:styles/w:style') as $s) {
            $id = $s->getAttributeNS(self::NS, 'styleId');
            if ($id === '') {
                continue;
            }
            $sz = self::attr($xp, 'w:rPr/w:sz', $s);
            $ln = self::attr($xp, 'w:pPr/w:spacing', $s, 'line');
            $rule = self::attr($xp, 'w:pPr/w:spacing', $s, 'lineRule');
            $styles[$id] = [
                'name' => (string)self::attr($xp, 'w:name', $s),
                'basedOn' => self::attr($xp, 'w:basedOn', $s),
                'font' => self::font_of($xp, $xp->query('w:rPr/w:rFonts', $s)->item(0), $theme),
                'size' => $sz !== null ? (float)$sz / 2 : null,
                'line' => ($ln !== null && ($rule === null || $rule === 'auto')) ? round((float)$ln / 240, 2) : null,
            ];
        }
        return [$styles, $defaults];
    }

    private static function resolve(array $styles, ?string $id, string $prop) {
        for ($i = 0; $id && isset($styles[$id]) && $i < 12; $i++) {
            if ($styles[$id][$prop] !== null) {
                return $styles[$id][$prop];
            }
            $id = $styles[$id]['basedOn'];
        }
        return null;
    }
}
