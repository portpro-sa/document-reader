<?php

namespace PortPro\DocumentReader\Tests;

use PHPUnit\Framework\TestCase;
use PortPro\DocumentReader\DocumentReader;

final class FileReaderTest extends TestCase
{
    public function test_cli_example_matches_the_published_json(): void
    {
        $dir = __DIR__.'/../examples/';
        $expected = json_decode(file_get_contents($dir.'inspection.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($expected, (new DocumentReader)->read($dir.'inspection.txt'));
    }

    public function test_image_ocr_recovers_a_labeled_value(): void
    {
        $result = (new DocumentReader)->read(__DIR__.'/fixtures/label.png');
        self::assertSame('7319', $result['pages'][0]['fields'][0]['value']);
    }

    public function test_pdf_keeps_text_and_reads_scanned_pages(): void
    {
        $result = (new DocumentReader)->read(__DIR__.'/fixtures/mixed.pdf');
        self::assertCount(2, $result['pages']);
        self::assertStringContainsString('QC-1234', $result['pages'][0]['text']);
        self::assertStringContainsString('7319', $result['pages'][1]['text']);
    }

    public function test_missing_file_is_rejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid_file');
        (new DocumentReader)->read(__DIR__.'/fixtures/missing');
    }
}
