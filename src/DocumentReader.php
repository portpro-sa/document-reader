<?php

namespace PortPro\DocumentReader;

use DomainException;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class DocumentReader
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_PAGES = 8;

    public function read(string $path, string $extension = '', string $languages = 'eng+ara+chi_sim'): array
    {
        $extension = strtolower($extension ?: pathinfo($path, PATHINFO_EXTENSION));
        $path = realpath($path);
        if (! $path || ! is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw new DomainException('invalid_file');
        }
        $deadline = microtime(true) + 40;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (in_array($extension, ['docx', 'xlsx', 'pptx'], true) || $mime === 'application/zip'
            || str_starts_with($mime, 'application/vnd.openxmlformats-officedocument.')) {
            return DocumentData::fromPages((new OfficeReader)->read($path));
        }
        if (str_starts_with($mime, 'text/') || in_array($mime, ['application/json', 'application/csv', 'application/x-empty'], true)) {
            if (filesize($path) > 500000) {
                throw new DomainException('document_too_complex');
            }
            $text = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($path));
            if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
                throw new DomainException('unreadable');
            }
            $structured = null;
            $tables = null;
            if ($extension === 'json' || $mime === 'application/json') {
                try {
                    $structured = json_decode($text, false, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                } catch (\JsonException) {
                    throw new DomainException('unreadable');
                }
            } elseif (in_array($extension, ['csv', 'tsv'], true) || $mime === 'text/csv') {
                $stream = fopen('php://temp', 'w+');
                fwrite($stream, $text);
                rewind($stream);
                $rows = [];
                $cells = 0;
                while (($row = fgetcsv($stream, 0, $extension === 'tsv' ? "\t" : ',', '"', '')) !== false) {
                    $cells += count($row);
                    if ($cells > 25000) {
                        fclose($stream);
                        throw new DomainException('document_too_complex');
                    }
                    $rows[] = $row;
                }
                fclose($stream);
                $tables = [$rows];
            }

            return DocumentData::fromPages([DocumentData::page($text, 1, $tables)], $structured);
        }
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/bmp', 'image/x-ms-bmp'], true)) {
            $size = @getimagesize($path);
            if (! $size || $size[0] * $size[1] > 16000000) {
                throw new DomainException('image_size');
            }

            return DocumentData::fromPages([DocumentData::page($this->ocr($path, $languages, $deadline), 1)]);
        }
        if ($mime !== 'application/pdf') {
            throw new DomainException('invalid_file');
        }

        $info = $this->run(['pdfinfo', $path], $deadline);
        if (! preg_match('/^Pages:\s+(\d+)/m', $info, $count)) {
            throw new DomainException('unreadable');
        }
        if ((int) $count[1] > self::MAX_PAGES) {
            throw new DomainException('page_limit');
        }
        $text = $this->run(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-'], $deadline);
        $pages = explode("\f", $text);
        $dir = sys_get_temp_dir().'/portpro-bill-'.bin2hex(random_bytes(12));
        if (! mkdir($dir, 0700)) {
            throw new RuntimeException('temporary_storage');
        }
        try {
            for ($i = 0; $i < (int) $count[1]; $i++) {
                // Preserve even short embedded fields. Rasterize pages with no usable text (or just a page number).
                if (preg_match('/\p{L}{2}/u', $pages[$i] ?? '')) {
                    continue;
                }
                $number = (string) ($i + 1);
                $this->run(['pdftoppm', '-f', $number, '-l', $number, '-scale-to', '2400',
                    '-singlefile', '-png', $path, $dir.'/page'], $deadline);
                $pages[$i] = $this->ocr($dir.'/page.png', $languages, $deadline);
            }
        } finally {
            if (is_file($dir.'/page.png')) {
                unlink($dir.'/page.png');
            }
            rmdir($dir);
        }
        $result = [];
        for ($i = 0; $i < (int) $count[1]; $i++) {
            $result[] = DocumentData::page(rtrim($pages[$i]), $i + 1);
        }

        return DocumentData::fromPages($result);
    }

    private function ocr(string $path, string $languages, float $deadline): string
    {
        return $this->run(['tesseract', $path, 'stdout', '-l', $languages, '--psm', '6',
            '-c', 'preserve_interword_spaces=1'], $deadline);
    }

    private function run(array $command, float $deadline): string
    {
        $binary = (new ExecutableFinder)->find($command[0]);
        if (! $binary) {
            throw new RuntimeException('reader_unavailable');
        }
        $command[0] = $binary;
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new DomainException('time_limit');
        }
        $process = new Process($command, null, ['LC_ALL' => 'C', 'OMP_THREAD_LIMIT' => '1'], null, $remaining);
        $bytes = 0;
        try {
            $process->run(function ($type, $output) use (&$bytes, $process) {
                $bytes += strlen($output);
                if ($bytes > 1500000) {
                    $process->stop(0);
                    throw new DomainException('document_too_complex');
                }
            });
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
            throw new DomainException('time_limit');
        }
        if (! $process->isSuccessful()) {
            throw new DomainException('unreadable');
        }

        return $process->getOutput();
    }
}
