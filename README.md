# comprestack/sdk

Official PHP SDK for the [Comprestack](https://www.comprestack.com) image optimization API.

Compress or convert images from Laravel, WordPress, and plain PHP with a small client. Same REST surface as the [API docs](https://www.comprestack.com/docs) and [OpenAPI](https://api.comprestack.com/openapi.yaml).

---

## Install

```bash
composer require comprestack/sdk
```

**Requirements:** PHP 8.1+ with `curl` and `json` extensions.

Create an API key in the [dashboard](https://www.comprestack.com/dashboard?section=api), then set:

```bash
export COMPRESTACK_API_KEY=csk_live_â€¦
```

### Security

API keys must stay on the **server**. Do not put them in themes, public repos, or browser JavaScript.

---

## Quick start

```php
<?php

use Comprestack\Client;

$client = new Client([
    'apiKey' => getenv('COMPRESTACK_API_KEY'),
]);

$job = $client->convert(
    ['photo.jpg'],
    'webp',
    [
        'quality' => 'balanced',
        'maxWidth' => 1600,
    ],
);

file_put_contents('photo.webp', $client->download($job));
echo "Saved {$job['files'][0]['saved_percent']}% Â· {$job['usage']['remaining']} left\n";
```

Compress without changing format:

```php
$job = $client->compress(['hero.png'], ['quality' => 'high']);
```

### Laravel tip

Resolve the client in a controller or job â€” keep `COMPRESTACK_API_KEY` in `.env`:

```php
$client = new Client(['apiKey' => config('services.comprestack.key')]);
```

---

## What you can do

| Goal | Method |
|------|--------|
| Shrink, keep format | `compress($files, $options)` |
| Change format + shrink | `convert($files, $format, $options)` |
| Re-fetch job metadata | `getJob($id)` |
| Download ZIP / result | `download($job)` or `download($downloadUrl)` |
| Download one file in a batch | `downloadFile($jobId, $index)` |
| Check quota | `usage()` |
| White-label billing report | `roomReport($roomId)` |

Jobs finish in the same request. Results expire after about one hour.

---

## Inputs

| Input | Example |
|-------|---------|
| File path | `'photo.jpg'` |
| `SplFileInfo` | `new SplFileInfo('photo.jpg')` |
| Stream resource | `fopen('photo.jpg', 'rb')` |
| Named payload | `['contents' => $bytes, 'filename' => 'hero.png']` |
| PSR-7 upload / stream | Duck-typed `UploadedFileInterface` / `StreamInterface` |

---

## Options

Available on both `compress` and `convert`:

| Option | Description |
|--------|-------------|
| `quality` | `'balanced'` (default), `'high'`, `'smallest'`, or `1â€“100` on Pro/Business |
| `format` | **convert only** â€” passed as the second argument |
| `maxWidth` / `maxHeight` | Downscale to fit (never enlarges) |
| `target_kb` | Aim for a max file size (Pro/Business) |
| `lossless` | Pixel-perfect where the codec allows (Pro/Business) |
| `keep_metadata` | Keep EXIF / color profile (default strips metadata, including GPS) |

---

## Client configuration

```php
$client = new Client([
    'apiKey' => getenv('COMPRESTACK_API_KEY'),
    // 'baseUrl' => 'https://api.comprestack.com/api/v1',
    // 'timeout' => 300,
    // 'maxRetries' => 2, // extra tries on 429 / 503 (honours Retry-After)
    // 'onProgress' => fn (array $e) => error_log($e['phase'].' '.$e['path']),
    // 'headers' => ['X-Request-Id' => 'order-4417'],
]);
```

---

## Errors

Failed requests throw `Comprestack\ComprestackException`:

```php
use Comprestack\Client;
use Comprestack\ComprestackException;

try {
    $client->compress(['huge.tiff']);
} catch (ComprestackException $err) {
    // $err->status, $err->errorCode, $err->getMessage(), $err->retryAfter
    throw $err;
}
```

Common codes: `unauthorized`, `limit_reached`, `file_too_large`, `rate_limited`, `busy`, `plan_required`.

---

## Links

- [API reference](https://www.comprestack.com/docs)
- [OpenAPI 3.1](https://api.comprestack.com/openapi.yaml)
- [Dashboard â€” API keys](https://www.comprestack.com/dashboard?section=api)
- [npm SDK](https://www.npmjs.com/package/comprestack)
- [PyPI SDK](https://pypi.org/project/comprestack/)
- [Source](https://github.com/MichaelGenesisII/Comprestack-PHP-SDK)

---

## License

MIT Â© Comprestack
