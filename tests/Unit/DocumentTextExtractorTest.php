<?php

namespace Tests\Unit;

use App\Services\ContentUpload\DocumentTextExtractor;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class DocumentTextExtractorTest extends TestCase
{
    public function test_extracts_text_from_docx(): void
    {
        $path = sys_get_temp_dir() . '/cmbop-extract-test.docx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hello extraction world from docx file content.</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        $result = (new DocumentTextExtractor())->extract($path, 'docx');
        @unlink($path);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Hello extraction world', (string) $result['text']);
        $this->assertGreaterThan(3, $result['word_count']);
        $this->assertNotEmpty($result['html']);
    }

    public function test_rejects_empty_docx(): void
    {
        $path = sys_get_temp_dir() . '/cmbop-empty.docx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body></w:body></w:document>');
        $zip->close();

        $result = (new DocumentTextExtractor())->extract($path, 'docx');
        @unlink($path);

        $this->assertFalse($result['ok']);
        $this->assertSame('empty_document', $result['error_code']);
    }
}
