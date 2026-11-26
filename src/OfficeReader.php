<?php

namespace PortPro\DocumentReader;

use DomainException;
use DOMDocument;
use DOMNode;
use DOMXPath;
use ZipArchive;

final class OfficeReader
{
    private ZipArchive $zip;

    private int $expanded = 0;

    private int $outputBytes = 0;

    public function read(string $path): array
    {
        $this->zip = new ZipArchive;
        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new DomainException('unreadable');
        }
        try {
            if ($this->zip->numFiles > 4000) {
                throw new DomainException('document_too_complex');
            }
            if ($this->zip->locateName('word/document.xml') !== false) {
                $xml = $this->xml('word/document.xml');
                $body = $xml->query('//*[local-name()="body"]')->item(0);
                if (! $body) {
                    throw new DomainException('unreadable');
                }
                $lines = [];
                $tables = [];
                foreach ($body->childNodes as $node) {
                    if ($node->localName === 'tbl') {
                        $rows = [];
                        foreach ($xml->query('./*[local-name()="tr"]', $node) as $row) {
                            $cells = [];
                            foreach ($xml->query('./*[local-name()="tc"]', $row) as $cell) {
                                $cells[] = $this->text($xml, $cell);
                            }
                            $rows[] = $cells;
                            $lines[] = implode("\t", $cells);
                        }
                        $tables[] = $rows;
                    } else {
                        $lines[] = $this->text($xml, $node);
                    }
                }

                return [DocumentData::page(implode("\n", $lines), 1, $tables)];
            }

            $sheets = $this->parts('~^xl/worksheets/sheet\d+\.xml$~');
            if ($sheets) {
                $shared = [];
                if ($this->zip->locateName('xl/sharedStrings.xml') !== false) {
                    $xml = $this->xml('xl/sharedStrings.xml');
                    foreach ($xml->query('//*[local-name()="si"]') as $entry) {
                        $shared[] = $this->text($xml, $entry);
                    }
                }
                $pages = [];
                foreach ($sheets as $part) {
                    $xml = $this->xml($part);
                    $rows = [];
                    $totalCells = 0;
                    foreach ($xml->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                        $values = [];
                        foreach ($xml->query('./*[local-name()="c"]', $row) as $cell) {
                            $column = 0;
                            if (preg_match('/^([A-Z]+)\d+$/', $cell->getAttribute('r'), $m)) {
                                foreach (str_split($m[1]) as $letter) {
                                    $column = $column * 26 + ord($letter) - 64;
                                    if ($column > 1024) {
                                        throw new DomainException('document_too_complex');
                                    }
                                }
                            }
                            while (count($values) < $column - 1) {
                                $values[] = '';
                            }
                            $value = $xml->evaluate('string(./*[local-name()="v"])', $cell);
                            $content = match ($cell->getAttribute('t')) {
                                's' => $shared[(int) $value] ?? '',
                                'inlineStr' => $this->text($xml, $cell),
                                default => $value,
                            };
                            $this->outputBytes += strlen($content) + 1;
                            if ($this->outputBytes > 1500000) {
                                throw new DomainException('document_too_complex');
                            }
                            $values[] = $content;
                        }
                        $totalCells += count($values);
                        if ($totalCells > 25000) {
                            throw new DomainException('document_too_complex');
                        }
                        if ($values) {
                            $rows[] = $values;
                        }
                    }
                    $pages[] = DocumentData::page(implode("\n", array_map(fn ($row) => implode("\t", $row), $rows)), count($pages) + 1, [$rows]);
                }

                return $pages;
            }

            $pages = [];
            foreach ($this->parts('~^ppt/slides/slide\d+\.xml$~') as $slide) {
                $xml = $this->xml($slide);
                $lines = [];
                foreach ($xml->query('//*[local-name()="p"]') as $p) {
                    $lines[] = $this->text($xml, $p);
                }
                $pages[] = DocumentData::page(implode("\n", $lines), count($pages) + 1);
            }
            if (! $pages) {
                throw new DomainException('unsupported_file');
            }

            return $pages;
        } finally {
            $this->zip->close();
        }
    }

    private function parts(string $pattern): array
    {
        $parts = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = $this->zip->getNameIndex($i);
            if (preg_match($pattern, $name)) {
                $parts[] = $name;
                if (count($parts) > 80) {
                    throw new DomainException('document_too_complex');
                }
            }
        }
        natsort($parts);

        return $parts;
    }

    private function xml(string $name): DOMXPath
    {
        $size = $this->zip->statName($name)['size'];
        $this->expanded += $size;
        if ($this->expanded > 6000000) {
            throw new DomainException('document_too_complex');
        }
        $bytes = $this->zip->getFromName($name);
        $dom = new DOMDocument;
        if ($bytes === false || stripos($bytes, '<!DOCTYPE') !== false || ! @$dom->loadXML($bytes, LIBXML_NONET) || $dom->doctype) {
            throw new DomainException('unreadable');
        }

        return new DOMXPath($dom);
    }

    private function text(DOMXPath $xml, DOMNode $parent): string
    {
        $text = '';
        foreach ($xml->query('.//*[local-name()="t"]', $parent) as $run) {
            $text .= $run->textContent;
        }

        return $text;
    }
}
