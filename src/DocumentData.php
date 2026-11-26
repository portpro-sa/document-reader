<?php

namespace PortPro\DocumentReader;

final class DocumentData
{
    public static function page(string $text, int $number, ?array $tables = null): array
    {
        if (strlen($text) > 1500000 || substr_count($text, "\n") > 25000) {
            throw new \DomainException('document_too_complex');
        }
        $cellCount = 0;
        $fields = [];
        $blocks = [];
        $block = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            // Repeated labels retain their order, including multiple pairs on one line.
            preg_match_all('/(?:^|\h{2,})([\p{L}][\p{L}\p{N} ._()\/-]{0,60}?)\h*[:：]\h*(\S.*?)(?=\h{2,}[\p{L}][^:：]{0,50}[:：]|$)/u', $line, $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $fields[] = ['label' => trim($pair[1]), 'value' => trim($pair[2])];
            }
            if ($tables !== null) {
                continue;
            }
            $cells = preg_split('/\t| {2,}/u', trim($line));
            $cellCount += count($cells);
            if ($cellCount > 50000 || count($fields) > 25000) {
                throw new \DomainException('document_too_complex');
            }
            if (count($cells) >= 2) {
                $block[] = $cells;
            } else {
                if (count($block) >= 2) {
                    $blocks[] = $block;
                }
                $block = [];
            }
        }
        if (count($block) >= 2) {
            $blocks[] = $block;
        }

        return ['number' => $number, 'text' => $text, 'fields' => $fields, 'tables' => $tables ?? $blocks];
    }

    public static function fromPages(array $pages, mixed $structured = null): array
    {
        $shipping = (new BillFields)->extract(implode("\n\n", array_column($pages, 'text')));

        return ['pages' => $pages, 'structured_data' => $structured, 'shipping' => $shipping];
    }
}
