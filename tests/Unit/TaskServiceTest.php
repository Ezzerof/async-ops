<?php

namespace Tests\Unit;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class TaskServiceTest extends TestCase
{
    use RefreshDatabase;

    private TaskService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TaskService::class);
    }

    // -------------------------------------------------------------------------
    // Group A — withLiveProgress: pass-through cases
    // -------------------------------------------------------------------------

    public function test_with_live_progress_returns_original_task_when_no_batch_id(): void
    {
        $task = Task::factory()->pending()->create([
            'type'    => TaskType::FileConversion->value,
            'payload' => ['target_format' => 'json', 'files' => [], 'output_files' => []],
        ]);

        $result = $this->service->withLiveProgress($task);

        $this->assertSame($task, $result);
    }

    public function test_with_live_progress_returns_original_task_when_batch_not_found(): void
    {
        Bus::shouldReceive('findBatch')
            ->with('missing-batch-id')
            ->andReturn(null);

        $task = Task::factory()->pending()->create([
            'type'    => TaskType::FileConversion->value,
            'payload' => ['batch_id' => 'missing-batch-id'],
        ]);

        $result = $this->service->withLiveProgress($task);

        $this->assertSame($task, $result);
    }

    public function test_with_live_progress_returns_original_task_when_batch_finished(): void
    {
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('finished')->andReturn(true);

        Bus::shouldReceive('findBatch')
            ->with('finished-batch-id')
            ->andReturn($batch);

        $task = Task::factory()->completed()->create([
            'type'    => TaskType::FileConversion->value,
            'payload' => ['batch_id' => 'finished-batch-id'],
        ]);

        $result = $this->service->withLiveProgress($task);

        $this->assertSame($task, $result);
    }

    public function test_with_live_progress_returns_original_task_when_no_batch_id_for_report_task(): void
    {
        $task = Task::factory()->pending()->create([
            'type'    => TaskType::UserExport->value,
            'payload' => null,
        ]);

        $result = $this->service->withLiveProgress($task);

        $this->assertSame($task, $result);
    }

    // -------------------------------------------------------------------------
    // Group B — withLiveProgress: in-flight batch hydration
    // -------------------------------------------------------------------------

    public function test_with_live_progress_returns_clone_not_original_when_batch_in_flight(): void
    {
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('finished')->andReturn(false);
        $batch->shouldReceive('processedJobs')->andReturn(1);
        $batch->totalJobs = 2;

        Bus::shouldReceive('findBatch')->andReturn($batch);

        $task = Task::factory()->pending()->create([
            'type'    => TaskType::FileConversion->value,
            'payload' => ['batch_id' => 'live-batch-id'],
        ]);

        $result = $this->service->withLiveProgress($task);

        $this->assertNotSame($task, $result);
    }

    public function test_with_live_progress_sets_status_to_processing_when_batch_in_flight(): void
    {
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('finished')->andReturn(false);
        $batch->shouldReceive('processedJobs')->andReturn(0);
        $batch->totalJobs = 3;

        Bus::shouldReceive('findBatch')->andReturn($batch);

        $task = Task::factory()->pending()->create([
            'type'    => TaskType::FileConversion->value,
            'payload' => ['batch_id' => 'live-batch-id'],
        ]);

        $result = $this->service->withLiveProgress($task);

        $this->assertSame(TaskStatus::Processing, $result->status);
    }

    public function test_with_live_progress_does_not_mutate_original_task(): void
    {
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('finished')->andReturn(false);
        $batch->shouldReceive('processedJobs')->andReturn(1);
        $batch->totalJobs = 2;

        Bus::shouldReceive('findBatch')->andReturn($batch);

        $task = Task::factory()->pending()->create([
            'type'    => TaskType::FileConversion->value,
            'payload' => ['batch_id' => 'live-batch-id'],
        ]);

        $originalStatus   = $task->status;
        $originalProgress = $task->progress;

        $this->service->withLiveProgress($task);

        $this->assertSame($originalStatus, $task->status);
        $this->assertSame($originalProgress, $task->progress);
    }

    /** @dataProvider batchProgressProvider */
    public function test_with_live_progress_derives_correct_percentage(
        int $processed,
        int $total,
        int $expectedProgress,
    ): void {
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('finished')->andReturn(false);
        $batch->shouldReceive('processedJobs')->andReturn($processed);
        $batch->totalJobs = $total;

        Bus::shouldReceive('findBatch')->andReturn($batch);

        $task = Task::factory()->pending()->create([
            'type'    => TaskType::FileConversion->value,
            'payload' => ['batch_id' => 'live-batch-id'],
        ]);

        $result = $this->service->withLiveProgress($task);

        $this->assertSame($expectedProgress, $result->progress);
    }

    public static function batchProgressProvider(): array
    {
        return [
            '0 of 1 jobs done'  => [0, 1, 0],
            '1 of 1 jobs done'  => [1, 1, 100],
            '1 of 3 jobs done'  => [1, 3, 33],
            '2 of 3 jobs done'  => [2, 3, 67],
            '1 of 2 jobs done'  => [1, 2, 50],
        ];
    }

    public function test_with_live_progress_returns_zero_progress_when_total_jobs_is_zero(): void
    {
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('finished')->andReturn(false);
        $batch->totalJobs = 0;

        Bus::shouldReceive('findBatch')->andReturn($batch);

        $task = Task::factory()->pending()->create([
            'type'    => TaskType::FileConversion->value,
            'payload' => ['batch_id' => 'live-batch-id'],
        ]);

        $result = $this->service->withLiveProgress($task);

        $this->assertSame(0, $result->progress);
    }

    // -------------------------------------------------------------------------
    // Group C — resolveDownloadMeta: report tasks
    // -------------------------------------------------------------------------

    public function test_resolve_download_meta_returns_csv_content_type_for_report_task(): void
    {
        $task = Task::factory()->completed()->create([
            'type'        => TaskType::UserExport->value,
            'result_path' => 'reports/some-file.csv',
        ]);

        $meta = $this->service->resolveDownloadMeta($task);

        $this->assertSame('text/csv', $meta['content_type']);
    }

    public function test_resolve_download_meta_uses_report_prefix_for_report_task(): void
    {
        $task = Task::factory()->completed()->create([
            'type'        => TaskType::UserExport->value,
            'result_path' => 'reports/some-file.csv',
        ]);

        $meta = $this->service->resolveDownloadMeta($task);

        $this->assertSame('report-' . $task->uuid . '.csv', $meta['filename']);
    }

    // -------------------------------------------------------------------------
    // Group D — resolveDownloadMeta: conversion tasks
    // -------------------------------------------------------------------------

    public function test_resolve_download_meta_uses_conversion_prefix_for_conversion_task(): void
    {
        $task = Task::factory()->completed()->create([
            'type'        => TaskType::FileConversion->value,
            'result_path' => 'conversions/' . Str::uuid() . '/output_abc.json',
        ]);

        $meta = $this->service->resolveDownloadMeta($task);

        $this->assertStringStartsWith('conversion-', $meta['filename']);
        $this->assertStringEndsWith('.json', $meta['filename']);
        $this->assertStringContainsString($task->uuid, $meta['filename']);
    }

    public function test_resolve_download_meta_returns_zip_content_type_for_multi_file_result(): void
    {
        $task = Task::factory()->completed()->create([
            'type'        => TaskType::FileConversion->value,
            'result_path' => 'conversions/' . Str::uuid() . '/result.zip',
        ]);

        $meta = $this->service->resolveDownloadMeta($task);

        $this->assertSame('application/zip', $meta['content_type']);
        $this->assertStringEndsWith('.zip', $meta['filename']);
    }

    /** @dataProvider conversionMimeProvider */
    public function test_resolve_download_meta_maps_extension_to_correct_mime(
        string $extension,
        string $expectedMime,
    ): void {
        $task = Task::factory()->completed()->create([
            'type'        => TaskType::FileConversion->value,
            'result_path' => 'conversions/' . Str::uuid() . '/output.' . $extension,
        ]);

        $meta = $this->service->resolveDownloadMeta($task);

        $this->assertSame($expectedMime, $meta['content_type']);
    }

    public static function conversionMimeProvider(): array
    {
        return [
            'json' => ['json', 'application/json'],
            'csv'  => ['csv',  'text/csv'],
            'xml'  => ['xml',  'application/xml'],
            'pdf'  => ['pdf',  'application/pdf'],
            'docx' => ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xlsx' => ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'yaml' => ['yaml', 'application/yaml'],
            'txt'  => ['txt',  'text/plain'],
            'zip'  => ['zip',  'application/zip'],
        ];
    }

    public function test_resolve_download_meta_falls_back_to_octet_stream_for_unknown_extension(): void
    {
        $task = Task::factory()->completed()->create([
            'type'        => TaskType::FileConversion->value,
            'result_path' => 'conversions/' . Str::uuid() . '/output.xyz',
        ]);

        $meta = $this->service->resolveDownloadMeta($task);

        $this->assertSame('application/octet-stream', $meta['content_type']);
    }
}
