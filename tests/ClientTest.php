<?php

declare(strict_types=1);

namespace Comprestack\Tests;

use Comprestack\Client;
use Comprestack\ComprestackException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

final class ClientTest extends TestCase
{
    /** @return array<string, mixed> */
    private function jobPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'job_1',
            'object' => 'job',
            'mode' => 'convert',
            'status' => 'done',
            'format' => 'webp',
            'files' => [[
                'index' => 0,
                'name' => 'photo.webp',
                'original_size' => 1000,
                'size' => 400,
                'saved_percent' => 60,
                'unchanged' => false,
                'width' => 100,
                'height' => 80,
                'format' => 'webp',
            ]],
            'created_at' => '2026-01-01T00:00:00Z',
            'expires_at' => '2026-01-01T01:00:00Z',
            'images_used' => 1,
            'usage' => [
                'object' => 'usage',
                'plan' => 'free',
                'images_used' => 1,
                'images_limit' => 100,
                'remaining' => 99,
            ],
            'download_url' => '/api/v1/jobs/job_1/download?token=abc',
        ], $overrides);
    }

    public function testRequiresApiKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client(['apiKey' => '']);
    }

    public function testResolveDownloadUrlPrefixesRelativePaths(): void
    {
        $client = new Client(['apiKey' => 'csk_test']);
        $this->assertSame(
            'https://api.comprestack.com/api/v1/jobs/1/download',
            $client->resolveDownloadUrl('/api/v1/jobs/1/download'),
        );
    }

    public function testResolveDownloadUrlKeepsAbsolute(): void
    {
        $client = new Client(['apiKey' => 'csk_test']);
        $url = 'https://cdn.example/file.zip';
        $this->assertSame($url, $client->resolveDownloadUrl($url));
    }

    public function testConvertSendsAuthAndReturnsJob(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cs');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, 'fake-image');

        $client = new Client([
            'apiKey' => 'csk_test',
            'httpHandler' => function (string $method, string $url, ?string $body, array $headers) use ($tmp) {
                $this->assertSame('POST', $method);
                $this->assertStringEndsWith('/convert', $url);
                $this->assertContains('Authorization: Bearer csk_test', $headers);
                $this->assertNotNull($body);
                $this->assertStringContainsString('name="format"', $body);
                $this->assertStringContainsString('webp', $body);
                $this->assertStringContainsString('name="files[]"', $body);
                $this->assertStringContainsString(basename($tmp), $body);

                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => json_encode($this->jobPayload(), JSON_THROW_ON_ERROR),
                ];
            },
        ]);

        $job = $client->convert([$tmp], 'webp', ['quality' => 'balanced', 'maxWidth' => 1600]);
        $this->assertSame('job_1', $job['id']);
        $this->assertSame(99, $job['usage']['remaining']);
        unlink($tmp);
    }

    public function testRetriesOn429ThenSucceeds(): void
    {
        $calls = 0;
        $client = new Client([
            'apiKey' => 'csk_test',
            'maxRetries' => 2,
            'httpHandler' => function () use (&$calls) {
                $calls++;
                if ($calls === 1) {
                    return [
                        'status' => 429,
                        'headers' => [
                            'content-type' => 'application/json',
                            'retry-after' => '0',
                        ],
                        'body' => json_encode([
                            'error' => 'rate',
                            'code' => 'rate_limited',
                            'message' => 'slow down',
                        ], JSON_THROW_ON_ERROR),
                    ];
                }

                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => json_encode($this->jobPayload(['mode' => 'compress', 'format' => null]), JSON_THROW_ON_ERROR),
                ];
            },
        ]);

        $job = $client->compress([['contents' => 'abc', 'filename' => 'a.jpg']], ['quality' => 'high']);
        $this->assertSame('job_1', $job['id']);
        $this->assertSame(2, $calls);
    }

    public function testRaisesComprestackExceptionWithCode(): void
    {
        $client = new Client([
            'apiKey' => 'bad',
            'httpHandler' => static fn () => [
                'status' => 401,
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode([
                    'error' => 'auth',
                    'code' => 'unauthorized',
                    'message' => 'bad key',
                ], JSON_THROW_ON_ERROR),
            ],
        ]);

        try {
            $client->usage();
            $this->fail('Expected ComprestackException');
        } catch (ComprestackException $err) {
            $this->assertSame(401, $err->status);
            $this->assertSame('unauthorized', $err->errorCode);
            $this->assertStringContainsString('bad key', $err->getMessage());
        }
    }

    public function testDownloadFileHitsFilesPath(): void
    {
        $client = new Client([
            'apiKey' => 'csk_test',
            'baseUrl' => 'https://api.example/api/v1',
            'httpHandler' => function (string $method, string $url) {
                $this->assertSame('GET', $method);
                $this->assertStringEndsWith('/jobs/job_1/files/0', $url);

                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'image/webp'],
                    'body' => 'webp-bytes',
                ];
            },
        ]);

        $this->assertSame('webp-bytes', $client->downloadFile('job_1', 0));
    }

    public function testDownloadResolvesRelativeUrl(): void
    {
        $client = new Client([
            'apiKey' => 'csk_test',
            'httpHandler' => function (string $method, string $url) {
                $this->assertSame(
                    'https://api.comprestack.com/api/v1/jobs/job_1/download?token=abc',
                    $url,
                );

                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/zip'],
                    'body' => 'zip',
                ];
            },
        ]);

        $this->assertSame('zip', $client->download($this->jobPayload()));
    }

    public function testAcceptsSplFileInfo(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cs');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, 'png-bytes');

        $client = new Client([
            'apiKey' => 'csk_test',
            'httpHandler' => function (string $method, string $url, ?string $body) use ($tmp) {
                $this->assertNotNull($body);
                $this->assertStringContainsString(basename($tmp), $body);

                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => json_encode($this->jobPayload(), JSON_THROW_ON_ERROR),
                ];
            },
        ]);

        $client->compress([new SplFileInfo($tmp)]);
        unlink($tmp);
    }

    public function testProgressCallbackPhases(): void
    {
        $phases = [];
        $client = new Client([
            'apiKey' => 'csk_test',
            'onProgress' => static function (array $event) use (&$phases): void {
                $phases[] = $event['phase'];
            },
            'httpHandler' => static fn () => [
                'status' => 200,
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode(['object' => 'usage', 'plan' => 'free', 'remaining' => 1], JSON_THROW_ON_ERROR),
            ],
        ]);

        $client->usage();
        $this->assertSame('start', $phases[0]);
        $this->assertSame('complete', $phases[array_key_last($phases)]);
    }
}
