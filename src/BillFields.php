<?php

namespace PortPro\DocumentReader;

/** Conservative field suggestions. Layout coordinates refer to columns in Poppler's layout text. */
final class BillFields
{
    public function extract(string $text): ?array
    {
        $text = str_replace(["\r", '：', "\u{00a0}"], ['', ':', ' '], $text);
        $lines = explode("\n", $text);
        $number = '/(?:\b(?:bill\s+of\s+lading|b\s*\/\s*l)\s*(?:no\.?|number|#)\s*(?:提单号)?|提单号(?:码)?|رقم\s+(?:بوليصة|وثيقة)\s*الشحن)\h*[:#.-]?\h*([A-Z0-9][A-Z0-9\/-]{3,34})\b/iu';
        preg_match_all($number, $text, $matches);
        $numbers = array_values(array_unique(array_filter($matches[1], fn ($v) => preg_match('/\d/', $v))));
        $containers = [];
        $conflicts = [];
        $kgDocument = preg_match('/\bKGS?\b|公斤|كجم/iu', $text) && ! preg_match('/\b(?:LBS?|POUNDS?)\b/i', $text);
        foreach ($lines as $line) {
            if (! preg_match_all('/\b([A-Z]{3}[UJZ])\h*[- ]?\h*(\d{6})\h*[- ]?\h*(\d)\b/i', $line, $ids, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($ids as $id) {
                $serial = strtoupper($id[1].$id[2].$id[3]);
                $weight = null;
                // Associate mass only with a single serial on the same cargo row.
                if (count($ids) === 1) {
                    $after = substr($line, strpos($line, $id[0]) + strlen($id[0]));
                    if (preg_match('/\b([\d,]+(?:\.\d+)?)\s*KGS?\b/i', $after, $m)
                        || ($kgDocument && preg_match('/\b(?:PACKAGES?|PKGS?|CARTONS?|PALLETS?)\s+([\d,]+(?:\.\d+)?)\s+[\d.]+\b/i', $after, $m))) {
                        $weight = str_replace(',', '', $m[1]);
                    }
                }
                // Keep conflicting measurements blank for review.
                if (isset($containers[$serial]) && $weight !== null && $containers[$serial]['weight_kg'] !== null
                    && (float) $containers[$serial]['weight_kg'] !== (float) $weight) {
                    $conflicts[$serial] = true;
                }
                if (! isset($containers[$serial]) || $weight !== null) {
                    $containers[$serial] = ['serial' => $serial, 'weight_kg' => $weight];
                }
            }
        }
        foreach ($conflicts as $serial => $_) {
            $containers[$serial]['weight_kg'] = null;
        }
        if (count($numbers) > 1) {
            return null;
        }

        return [
            'bill_number' => $numbers[0] ?? null,
            'loading_port' => $this->port($lines, '/port of loading|ميناء (?:التحميل|الشحن)|起运港/iu'),
            'discharge_port' => $this->port($lines, '/port of discharge|ميناء (?:التفريغ|الوصول)|卸货港/iu'),
            'description' => $this->goods($lines),
            'containers' => array_values($containers),
        ];
    }

    private function port(array $lines, string $label): ?string
    {
        foreach ($lines as $i => $line) {
            if (! preg_match($label, $line, $match, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            [$header, $byte] = $match[0];
            $column = mb_strwidth(substr($line, 0, $byte));
            $suffix = substr($line, $byte + strlen($header));
            if (preg_match('/^\h*:\h*([\p{L}][\p{L} ,.-]{1,48}?)(?:\h{2,}|$)/u', $suffix, $inline)) {
                if (! preg_match('/destination|delivery|discharge|loading|收货|目的港/iu', $inline[1])) {
                    return trim($inline[1]);
                }
            }
            foreach (array_slice($lines, $i + 1, 5) as $next) {
                foreach (preg_split('/\h{2,}/u', $next, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE) as [$cell, $start]) {
                    $offset = mb_strwidth(substr($next, 0, $start));
                    if (abs($offset - $column) <= 5 && preg_match('/^[\p{L}][\p{L} ,.-]{1,48}$/u', trim($cell))
                        && ! preg_match('/[港地]$|^(?:port|place|final|notify|shipment|same as|是否)/iu', trim($cell))) {
                        return trim($cell);
                    }
                }
            }
        }

        return null;
    }

    private function goods(array $lines): ?string
    {
        foreach ($lines as $i => $line) {
            if (preg_match('/(?:description of goods|cargo description|وصف البضاعة|货物名称)\s*:\s*(\S.*?)\s*$/iu', $line, $m)) {
                return mb_substr(trim(preg_split('/\h{3,}/u', $m[1])[0]), 0, 250);
            }
            // Rows with a package count or a container count followed by a separate goods cell.
            if (preg_match('/\b(?:\d+\s+(?:PACKAGES?|CTNS?|PALLETS?)|\d+\s*[X*]\s*(?:20|40|45)(?:HQ|HC|GP))\h{2,}([\p{L}][\p{L}\d \/&(),.-]*?)(?:\h{2,}|$)/iu', $line, $m)) {
                return trim($m[1]);
            }
            if (preg_match('/\b\d+\s+PACKAGES IN TOTAL\b/i', $line)) {
                $parts = [];
                foreach (array_slice($lines, $i + 1, 4) as $next) {
                    if (! preg_match('/^\s*[|\[]?([A-Z][A-Z0-9 ,&()\/-]{2,80})\s*$/', $next, $m)) {
                        break;
                    }
                    $parts[] = trim($m[1]);
                }
                if ($parts) {
                    return implode(' ', $parts);
                }
            }
        }

        return null;
    }
}
