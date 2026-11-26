# Extraction reference

## PHP API

```php
use PortPro\DocumentReader\DocumentReader;

$reader = new DocumentReader();
$result = $reader->read($path);
$result = $reader->read($temporaryPath, extension: 'csv');
$result = $reader->read($imagePath, languages: 'eng+ara');
```

The first argument is a local path. The second argument identifies the original file extension when a temporary file has no suffix. The reader also checks the file contents. The third argument selects installed Tesseract language packs. Run `tesseract --list-langs` on the processing host to see the available languages.

## JSON structure

| Property | Contents |
| --- | --- |
| `pages[].number` | One-based section number. A section can be a PDF page, image, slide, worksheet or text section |
| `pages[].text` | Extracted text in reading order as returned by the reader |
| `pages[].fields` | Ordered objects with `label` and `value`. Repeated labels remain separate |
| `pages[].tables` | An array of tables. Each table contains rows, and each row contains cells |
| `structured_data` | The decoded value for JSON input. Other formats return `null` |
| `shipping` | Suggested `bill_number`, `loading_port`, `discharge_port`, `description` and `containers`. Multiple distinct bill numbers suppress the entire suggestion |

Missing shipping values are `null`. A container has a `serial` and a `weight_kg` string or `null`. Review the suggestions before filling a shipment form. OCR errors can change a serial by a single character.

## Reading behavior and limits

- PDF pages with at least two consecutive letters in their text layer use embedded text. Other pages are rendered and read by OCR. On a page that combines selectable text with a photographed table, text inside the photograph can be missed.
- Inline labels followed by a colon produce field suggestions. Aligned columns in text or scans produce table suggestions. Irregular spacing, handwriting and rotated photos can reduce accuracy. Use the returned text when field or table recognition is incomplete.
- Word tables and spreadsheet cells are read directly. XLSX formula cells use the cached value stored by the workbook. Embedded images in Office files receive no OCR. CSV uses commas and TSV uses tabs.
- The file limit is 10 MiB. PDFs may contain up to 8 pages, images up to 16 megapixels, and plain-text formats up to 500,000 bytes. The reader also bounds expanded Office XML, cell counts and process output. PDF and OCR subprocesses share a 40-second budget.
- The command writes JSON to standard output. On failure it writes a diagnostic to standard error and exits with status 1.

## Errors

| PHP exception | Common messages | Handling |
| --- | --- | --- |
| `DomainException` | `invalid_file`, `unsupported_file`, `unreadable` | Verify the format and path. Supply an unlocked PDF or a readable image |
| `DomainException` | `page_limit`, `image_size`, `document_too_complex`, `time_limit` | Split the file, reduce the resolution or process a smaller input |
| `RuntimeException` | `reader_unavailable`, `temporary_storage` | Check the PHP worker's PATH and writable temporary directory |

Text, CSV, TSV, JSON and supported Office documents can be read with PHP and the declared extensions alone. PDF processing requires `pdfinfo` and `pdftotext`. Scanned PDFs additionally require `pdftoppm` and `tesseract`. Images use `tesseract`.
