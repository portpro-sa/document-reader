# Using the reader in Laravel

Install the library and system dependencies using the [installation instructions](../README.md#install-with-composer). Laravel can resolve the reader through its service container.

This example adds a multipart endpoint to an application's API routes. It accepts a file under the field name `document` and returns the extracted data. Attach the authentication middleware used by your application.

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PortPro\DocumentReader\DocumentReader;

Route::post('/documents/extract', function (Request $request, DocumentReader $reader) {
    $request->validate(['document' => ['required', 'file', 'max:10240']]);
    $file = $request->file('document');

    try {
        $result = $reader->read($file->getRealPath(), $file->getClientOriginalExtension());
    } catch (\DomainException $error) {
        return response()->json(['error' => $error->getMessage()], 422);
    } catch (\RuntimeException $error) {
        report($error);
        return response()->json(['error' => 'reader_unavailable'], 503);
    }

    return response()->json($result)->header('Cache-Control', 'no-store');
})->middleware(['auth', 'throttle:6,1']);
```

Passing the original extension lets the reader distinguish CSV, TSV and JSON when PHP stores the upload under a temporary name. Read the upload during the request. If your application handles OCR in a queued job, first persist the file to private storage and delete it after processing.

Set PHP and web-server upload limits high enough for the files you accept. The worker running PHP must have access to Poppler, Tesseract and any selected language packs. Use the provided [sample input and result](../examples/) to check the response shape before connecting a form.
