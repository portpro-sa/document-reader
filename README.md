# PortPro Document Reader

Extract text, labeled fields and tables from documents and images, then export the result as JSON. The package runs on your own server with PHP 8.2 or later. It works in Laravel, in standalone PHP applications and from the command line. Reading a document requires no API key and incurs no per-page service fee.

## Install with Composer

Run these commands in your application directory to install the tagged release directly from GitHub.

```sh
composer config repositories.portpro-document-reader vcs https://github.com/portpro-sa/document-reader
composer require "portpro/document-reader:^0.1"
```

Composer checks the required PHP extensions. To process PDFs and images, install Poppler and Tesseract on the machine where PHP runs. On Ubuntu or Debian:

```sh
sudo apt-get install poppler-utils tesseract-ocr tesseract-ocr-eng tesseract-ocr-ara tesseract-ocr-chi-sim
```

On macOS with Homebrew:

```sh
brew install poppler tesseract tesseract-lang
```

## Read a document

```sh
vendor/bin/read-document inspection.pdf > inspection.json
```

In PHP, use the Composer autoloader and pass a local file path:

```php
require __DIR__.'/vendor/autoload.php';

use PortPro\DocumentReader\DocumentReader;

$result = (new DocumentReader())->read(__DIR__.'/inspection.pdf');
foreach ($result['pages'] as $page) {
    echo $page['text'], PHP_EOL;
}
```

A line such as `Inspection ID: QC-7319` produces a field with label `Inspection ID` and value `QC-7319`. The full text remains available alongside extracted fields and tables. Compare the [sample document](examples/inspection.txt) with its [complete JSON output](examples/inspection.json).

## Supported inputs

| Input | What the reader extracts |
| --- | --- |
| PDF | Embedded text from digital pages and OCR from scanned pages |
| JPEG, PNG, WebP, BMP | Printed text using Tesseract. The default languages are English, Arabic and simplified Chinese |
| DOCX, XLSX, PPTX | Word body text and tables, worksheet cells and PowerPoint slide text |
| UTF-8 TXT, CSV, TSV, JSON | Text, delimited rows and cells, or the parsed JSON value |
| Bills of lading in supported formats | Additional suggestions for the bill number, ports, cargo description, container serials and weights |

The maximum input size is 10 MiB. Individual formats have additional limits. Check the [extraction reference](docs/extraction.md) for the JSON structure, OCR behavior, format limits and errors. See the [Laravel integration guide](docs/laravel.md) for temporary uploads and JSON responses.

## Run the example from a clone

```sh
git clone https://github.com/portpro-sa/document-reader.git
cd document-reader
composer install
bin/read-document examples/inspection.txt
composer test
```

## License

Released by PortPro under the [MIT License](LICENSE).
