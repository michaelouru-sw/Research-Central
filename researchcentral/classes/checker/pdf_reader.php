<?php
namespace local_researchcentral\checker;

/**
 * Reads text from a PDF with the external pdftotext binary. Text and page count only;
 * formatting checks are not possible on PDF.
 */
class pdf_reader {
    public static function read(string $path, string $binary): ?\stdClass {
        if ($binary === '' || !is_executable($binary)) {
            return null;
        }
        $out = [];
        $code = 0;
        $cmd = escapeshellarg($binary) . ' -enc UTF-8 -nopgbrk ' . escapeshellarg($path) . ' - 2>/dev/null';
        exec($cmd, $out, $code);
        if ($code !== 0) {
            return null;
        }
        $pages = null;
        $info = dirname($binary) . '/pdfinfo';
        if (is_executable($info)) {
            $o = [];
            exec(escapeshellarg($info) . ' ' . escapeshellarg($path) . ' 2>/dev/null', $o);
            foreach ($o as $line) {
                if (preg_match('/^Pages:\s+(\d+)/', $line, $m)) {
                    $pages = (int)$m[1];
                }
            }
        }

        $paragraphs = [];
        $buffer = '';
        foreach ($out as $line) {
            if (trim($line) === '') {
                if ($buffer !== '') {
                    $paragraphs[] = self::para($buffer);
                    $buffer = '';
                }
                continue;
            }
            $buffer .= ($buffer === '' ? '' : ' ') . trim($line);
        }
        if ($buffer !== '') {
            $paragraphs[] = self::para($buffer);
        }
        return (object)['type' => 'pdf', 'paragraphs' => $paragraphs, 'format' => false, 'minmargin' => null, 'pages' => $pages];
    }

    private static function para(string $text): array {
        return ['text' => $text, 'heading' => false, 'toc' => false, 'fonts' => [], 'sizes' => [],
            'spacing' => null, 'chars' => mb_strlen($text)];
    }
}
