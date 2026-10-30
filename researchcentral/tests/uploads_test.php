<?php
namespace local_researchcentral;

use local_researchcentral\local\uploads;

defined('MOODLE_INTERNAL') || die();

/**
 * Upload rules: DOCX-only, size limit hierarchy, image validation.
 *
 * @covers \local_researchcentral\local\uploads
 */
final class uploads_test extends \advanced_testcase {
    private function tmp(string $content): string {
        $p = make_request_directory() . '/f';
        file_put_contents($p, $content);
        return $p;
    }

    public function test_limit_hierarchy(): void {
        $this->resetAfterTest();
        set_config('uploadmb', 30, 'local_researchcentral');
        set_config('uploadmb_ceiling', 50, 'local_researchcentral');
        $this->assertSame(30, uploads::limit_mb((object)['maxmb' => 0], (object)['maxmb' => 0]));
        $this->assertSame(20, uploads::limit_mb((object)['maxmb' => 0], (object)['maxmb' => 20]));
        $this->assertSame(10, uploads::limit_mb((object)['maxmb' => 10], (object)['maxmb' => 20]));
        $this->assertSame(50, uploads::limit_mb((object)['maxmb' => 999], (object)['maxmb' => 0]), 'never above the ceiling');
        $this->assertSame('project', uploads::limit_source((object)['maxmb' => 5], (object)['maxmb' => 20]));
        $this->assertSame('stage', uploads::limit_source((object)['maxmb' => 0], (object)['maxmb' => 20]));
        $this->assertSame('site', uploads::limit_source((object)['maxmb' => 0], (object)['maxmb' => 0]));
    }

    public function test_docx_only(): void {
        $this->resetAfterTest();
        $zip = make_request_directory() . '/a.docx';
        $z = new \ZipArchive();
        $z->open($zip, \ZipArchive::CREATE);
        $z->addFromString('[Content_Types].xml', '<x/>');
        $z->addFromString('word/document.xml', '<x/>');
        $z->close();
        $size = filesize($zip);
        $this->assertSame('', uploads::check_docx($zip, 'a.docx', $size, 10));
        $this->assertNotSame('', uploads::check_docx($zip, 'a.pdf', $size, 10), 'wrong extension');
        $this->assertNotSame('', uploads::check_docx($this->tmp('%PDF-1.4'), 'a.docx', 8, 10), 'renamed pdf');
        $this->assertNotSame('', uploads::check_docx($zip, 'a.docx', 11 * 1048576, 10), 'too large');
    }

    public function test_images(): void {
        $this->resetAfterTest();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $this->assertSame('', uploads::check_image($this->tmp($png), 'f.png', strlen($png)));
        $this->assertNotSame('', uploads::check_image($this->tmp($png), 'f.svg', strlen($png)), 'svg is refused');
        $this->assertNotSame('', uploads::check_image($this->tmp('not an image'), 'f.png', 12));
    }
}
