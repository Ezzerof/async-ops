<?php

namespace Tests\Unit;

use App\Enums\TaskType;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskTypeTest extends TestCase
{
    public function test_bulk_email_download_meta_returns_correct_filename(): void
    {
        $uuid = (string) Str::uuid();

        $meta = TaskType::BulkEmail->downloadMeta($uuid, null);

        $this->assertSame('email-report-' . $uuid . '.csv', $meta['filename']);
    }

    public function test_bulk_email_download_meta_returns_csv_content_type(): void
    {
        $meta = TaskType::BulkEmail->downloadMeta((string) Str::uuid(), null);

        $this->assertSame('text/csv', $meta['content_type']);
    }
}
