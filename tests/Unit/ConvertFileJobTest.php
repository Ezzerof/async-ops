<?php

namespace Tests\Unit;

use App\Enums\ConversionFormat;
use App\Enums\TaskStatus;
use App\Jobs\ConvertFileJob;
use App\Models\Task;
use App\Services\FileConversionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ConvertFileJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    // -------------------------------------------------------------------------
    // Group A — Cancelled batch
    // -------------------------------------------------------------------------

    public function test_handle_returns_early_when_batch_is_cancelled(): void
    {
        $task = Task::factory()->pending()->create(['type' => 'file_conversion']);
        Storage::put('uploads/' . $task->uuid . '/input.csv', "name,age\nAlice,30\n");

        $job = new ConvertFileJob($task, 'uploads/' . $task->uuid . '/input.csv', ConversionFormat::Json);
        $job->withFakeBatch(cancelledAt: CarbonImmutable::now());

        $job->handle(app(FileConversionService::class));

        // No output file should have been written
        $this->assertEmpty(
            array_filter(Storage::disk('local')->allFiles(), fn ($f) => str_ends_with($f, '.json'))
        );

        // Payload should not have been updated
        $this->assertNull($task->fresh()->payload['output_files'] ?? null);
    }

    public function test_handle_does_not_call_service_when_batch_is_cancelled(): void
    {
        $task = Task::factory()->pending()->create(['type' => 'file_conversion']);

        $service = $this->mock(FileConversionService::class);
        $service->shouldNotReceive('convert');

        $job = new ConvertFileJob($task, 'conversions/' . $task->uuid . '/input.csv', ConversionFormat::Json);
        $job->withFakeBatch(cancelledAt: CarbonImmutable::now());

        $job->handle($service);
    }

    // -------------------------------------------------------------------------
    // Group B — Successful conversion
    // -------------------------------------------------------------------------

    public function test_handle_writes_output_file_on_successful_conversion(): void
    {
        $task = Task::factory()->pending()->create(['type' => 'file_conversion']);
        Storage::put('conversions/' . $task->uuid . '/input.csv', "name,age\nAlice,30\n");

        $job = new ConvertFileJob($task, 'conversions/' . $task->uuid . '/input.csv', ConversionFormat::Json);
        $job->withFakeBatch(); // sets up a non-cancelled fake batch

        $job->handle(app(FileConversionService::class));

        $files = array_filter(Storage::disk('local')->allFiles(), fn ($f) => str_ends_with($f, '.json'));
        $this->assertCount(1, $files);
    }

    public function test_handle_accumulates_output_path_in_task_payload(): void
    {
        $task = Task::factory()->pending()->create(['type' => 'file_conversion']);
        Storage::put('conversions/' . $task->uuid . '/input.csv', "name,age\nAlice,30\n");

        $job = new ConvertFileJob($task, 'conversions/' . $task->uuid . '/input.csv', ConversionFormat::Json);
        $job->withFakeBatch(); // sets up a non-cancelled fake batch

        $job->handle(app(FileConversionService::class));

        $outputFiles = $task->fresh()->payload['output_files'] ?? [];
        $this->assertCount(1, $outputFiles);
        $this->assertStringEndsWith('.json', $outputFiles[0]);
    }

    public function test_handle_does_not_mark_task_as_failed_on_success(): void
    {
        $task = Task::factory()->pending()->create(['type' => 'file_conversion']);
        Storage::put('conversions/' . $task->uuid . '/input.csv', "name,age\nAlice,30\n");

        $job = new ConvertFileJob($task, 'conversions/' . $task->uuid . '/input.csv', ConversionFormat::Json);
        $job->withFakeBatch(); // sets up a non-cancelled fake batch

        $job->handle(app(FileConversionService::class));

        $this->assertNotSame(TaskStatus::Failed, $task->fresh()->status);
        $this->assertNull($task->fresh()->error_message);
    }

    public function test_handle_stub_conversion_writes_output_and_accumulates_path(): void
    {
        $task = Task::factory()->pending()->create(['type' => 'file_conversion']);
        Storage::put('conversions/' . $task->uuid . '/input.pdf', 'binary content');

        $job = new ConvertFileJob($task, 'conversions/' . $task->uuid . '/input.pdf', ConversionFormat::Txt);
        $job->withFakeBatch(); // sets up a non-cancelled fake batch

        $job->handle(app(FileConversionService::class));

        $outputFiles = $task->fresh()->payload['output_files'] ?? [];
        $this->assertCount(1, $outputFiles);
        $this->assertStringEndsWith('.txt', $outputFiles[0]);
        Storage::disk('local')->assertExists($outputFiles[0]);
    }

    public function test_handle_accumulates_multiple_output_paths_across_calls(): void
    {
        $task = Task::factory()->pending()->create(['type' => 'file_conversion']);
        Storage::put('conversions/' . $task->uuid . '/a.csv', "name\nAlice\n");
        Storage::put('conversions/' . $task->uuid . '/b.csv', "name\nBob\n");

        $job1 = new ConvertFileJob($task, 'conversions/' . $task->uuid . '/a.csv', ConversionFormat::Json);
        $job1->withFakeBatch();
        $job1->handle(app(FileConversionService::class));

        $job2 = new ConvertFileJob($task, 'conversions/' . $task->uuid . '/b.csv', ConversionFormat::Json);
        $job2->withFakeBatch();
        $job2->handle(app(FileConversionService::class));

        $outputFiles = $task->fresh()->payload['output_files'] ?? [];
        $this->assertCount(2, $outputFiles);
    }

    public function test_handle_does_not_accumulate_output_path_when_conversion_throws(): void
    {
        $task = Task::factory()->pending()->create(['type' => 'file_conversion']);
        // Source file is deliberately absent — convert() will throw RuntimeException

        $job = new ConvertFileJob($task, 'conversions/' . $task->uuid . '/missing.csv', ConversionFormat::Json);
        $job->withFakeBatch(); // sets up a non-cancelled fake batch

        try {
            $job->handle(app(FileConversionService::class));
        } catch (RuntimeException) {
            // Expected — simulates what the queue worker catches before calling failed()
        }

        $this->assertNull($task->fresh()->payload['output_files'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Group C — Task deletion guard
    // -------------------------------------------------------------------------

    public function test_handle_returns_early_when_task_is_deleted(): void
    {
        $task = Task::factory()->pending()->create(['type' => 'file_conversion']);
        Storage::put('conversions/' . $task->uuid . '/input.csv', "name,age\nAlice,30\n");

        $job = new ConvertFileJob($task, 'conversions/' . $task->uuid . '/input.csv', ConversionFormat::Json);
        $job->withFakeBatch(); // sets up a non-cancelled fake batch

        $task->delete();
        $job->handle(app(FileConversionService::class));

        // No output files and no exception thrown
        $files = array_filter(Storage::disk('local')->allFiles(), fn ($f) => str_ends_with($f, '.json'));
        $this->assertEmpty($files);
    }

    // -------------------------------------------------------------------------
    // Group D — failed() hook
    // -------------------------------------------------------------------------

    public function test_failed_sets_task_status_to_failed(): void
    {
        $task = Task::factory()->pending()->create(['type' => 'file_conversion']);

        $job = new ConvertFileJob($task, 'conversions/' . $task->uuid . '/input.csv', ConversionFormat::Json);
        $job->failed(new RuntimeException('CSV parse error'));

        $this->assertSame(TaskStatus::Failed, $task->fresh()->status);
    }

    public function test_failed_stores_runtime_exception_message(): void
    {
        $task = Task::factory()->pending()->create(['type' => 'file_conversion']);

        $job = new ConvertFileJob($task, 'conversions/' . $task->uuid . '/input.csv', ConversionFormat::Json);
        $job->failed(new RuntimeException('CSV parse error'));

        $this->assertSame('CSV parse error', $task->fresh()->error_message);
    }

    public function test_failed_stores_generic_message_for_non_runtime_exception(): void
    {
        $task = Task::factory()->pending()->create(['type' => 'file_conversion']);

        $job = new ConvertFileJob($task, 'conversions/' . $task->uuid . '/input.csv', ConversionFormat::Json);
        $job->failed(new \Exception('SQLSTATE[HY000] connection refused at /var/app/secret/path'));

        $this->assertSame(
            'An unexpected error occurred during file conversion.',
            $task->fresh()->error_message
        );
    }

    public function test_failed_overwrites_stale_error_message_on_retry(): void
    {
        $task = Task::factory()->failed()->create([
            'type'          => 'file_conversion',
            'error_message' => 'Previous attempt error',
        ]);

        $job = new ConvertFileJob($task, 'conversions/' . $task->uuid . '/input.csv', ConversionFormat::Json);
        $job->failed(new RuntimeException('New attempt error'));

        $this->assertSame('New attempt error', $task->fresh()->error_message);
    }

    public function test_failed_is_idempotent_when_task_is_already_failed(): void
    {
        $task = Task::factory()->failed()->create(['type' => 'file_conversion']);

        $job = new ConvertFileJob($task, 'conversions/' . $task->uuid . '/input.csv', ConversionFormat::Json);
        $job->failed(new RuntimeException('error'));
        $job->failed(new RuntimeException('error'));

        $this->assertSame(TaskStatus::Failed, $task->fresh()->status);
    }

    public function test_job_has_correct_retry_configuration(): void
    {
        $task = Task::factory()->pending()->create(['type' => 'file_conversion']);
        $job  = new ConvertFileJob($task, 'conversions/' . $task->uuid . '/input.csv', ConversionFormat::Json);

        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertSame([10, 30, 60], $job->backoff());
    }

    public function test_failed_does_not_throw_when_task_is_deleted(): void
    {
        $task = Task::factory()->pending()->create(['type' => 'file_conversion']);
        $job  = new ConvertFileJob($task, 'conversions/' . $task->uuid . '/input.csv', ConversionFormat::Json);

        $task->delete();

        // Should complete silently with no exception
        $job->failed(new RuntimeException('irrelevant'));
        $this->assertTrue(true);
    }
}
