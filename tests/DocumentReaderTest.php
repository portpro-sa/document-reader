<?php

namespace PortPro\DocumentReader\Tests;

use PHPUnit\Framework\TestCase;
use PortPro\DocumentReader\BillFields;
use PortPro\DocumentReader\DocumentData;
use PortPro\DocumentReader\DocumentReader;

final class DocumentReaderTest extends TestCase
{
    public function test_unseen_labels_and_tables_remain_available_without_shipping_fields(): void
    {
        $page = DocumentData::page("Inspection number: QC-009\nالموقع: جدة\n客户：上海\n\nItem    Count\nPallet  9", 1);
        self::assertSame(['QC-009', 'جدة', '上海'], array_column($page['fields'], 'value'));
        self::assertSame([['Item', 'Count'], ['Pallet', '9']], $page['tables'][0]);
        self::assertNull(DocumentData::fromPages([$page])['shipping']['bill_number']);
    }

    public function test_different_bill_numbers_never_merge_containers_and_booking_references_are_not_bills(): void
    {
        $parser = new BillFields;
        self::assertNull($parser->extract("B/L No: AA1234\nB/L No: BB4321"));
        self::assertNull($parser->extract('Booking No: BK12345')['bill_number']);
        $data = $parser->extract("B/L No: XY1234\nABC U123\nABCU1234567  125 KG\nDEMU7654321  340 KG\nABCU1234567");
        self::assertSame(['125', '340'], array_column($data['containers'], 'weight_kg'));
    }

    public function test_quoted_csv_cells_and_multiline_values_round_trip(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'document-test-');
        try {
            file_put_contents($file, "Item,Units\n\"Packed, large\nbox\",24\n");
            $data = (new DocumentReader)->read($file, 'csv');
            self::assertSame([['Item', 'Units'], ["Packed, large\nbox", '24']], $data['pages'][0]['tables'][0]);
        } finally {
            unlink($file);
        }
    }
}
