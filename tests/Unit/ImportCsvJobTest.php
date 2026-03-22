<?php

namespace Tests\Unit;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\ImportCsvJob;
use App\Models\Task;
use App\Models\User;
use App\Services\CsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ImportCsvJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeTask(?User $user = null): Task
    {
        $user ??= User::factory()->create();
        $task = Task::factory()->pending()->create([
            'user_id' => $user->id,
            'type'    => TaskType::CsvImport->value,
        ]);

        $uploadPath = 'uploads/' . $task->uuid . '/data.csv';
        Storage::disk('local')->put($uploadPath, "name,age\nAlice,30\nBob,25\n");
        $task->update(['payload' => [
            'file'              => $uploadPath,
            'original_filename' => 'data.csv',
        ]]);

        return $task->fresh();
    }

    // -------------------------------------------------------------------------
    // Group A — Guards
    // -------------------------------------------------------------------------

    public function test_handle_exits_silently_when_task_is_deleted(): void
    {
        $task = $this->makeTask();
        $job  = new ImportCsvJob($task);

        $task->delete();

        $job->handle(app(CsvImportService::class));

        $this->assertDatabaseCount('csv_imports', 0);
    }

    public function test_handle_exits_silently_when_status_is_not_pending(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->processing()->create([
            'user_id' => $user->id,
            'type'    => TaskType::CsvImport->value,
            'payload' => ['file' => 'uploads/unused/data.csv', 'original_filename' => 'data.csv'],
        ]);

        $job = new ImportCsvJob($task);
        $job->handle(app(CsvImportService::class));

        $this->assertSame(TaskStatus::Processing, $task->fresh()->status);
        $this->assertDatabaseCount('csv_imports', 0);
    }

    // -------------------------------------------------------------------------
    // Group B — Happy path (progress milestones)
    // -------------------------------------------------------------------------

    public function test_validation_runtime_exception_fails_job_immediately_without_retrying(): void
    {
        $task    = $this->makeTask();
        $service = Mockery::mock(CsvImportService::class);
        $service->shouldReceive('validateAndCount')
            ->once()
            ->andThrow(new \RuntimeException('CSV file contains duplicate column names.'));

        $job = new ImportCsvJob($task);
        $job->handle($service);

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Failed, $fresh->status);
        $this->assertSame('CSV file contains duplicate column names.', $fresh->error_message);
    }

    public function test_handle_marks_task_completed_with_progress_100(): void
    {
        $task = $this->makeTask();
        $job  = new ImportCsvJob($task);
        $job->handle(app(CsvImportService::class));

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Completed, $fresh->status);
        $this->assertSame(100, $fresh->progress);
    }

    public function test_handle_creates_csv_import_record(): void
    {
        $task = $this->makeTask();
        $job  = new ImportCsvJob($task);
        $job->handle(app(CsvImportService::class));

        $this->assertDatabaseHas('csv_imports', [
            'task_id'           => $task->id,
            'user_id'           => $task->user_id,
            'original_filename' => 'data.csv',
        ]);
    }

    public function test_handle_stores_csv_import_id_in_task_payload(): void
    {
        $task = $this->makeTask();
        $job  = new ImportCsvJob($task);
        $job->handle(app(CsvImportService::class));

        $fresh  = $task->fresh();
        $import = \App\Models\CsvImport::where('task_id', $task->id)->sole();
        $this->assertSame($import->id, $fresh->payload['csv_import_id']);
    }

    public function test_handle_copies_file_to_imports_directory(): void
    {
        $task = $this->makeTask();
        $job  = new ImportCsvJob($task);
        $job->handle(app(CsvImportService::class));

        Storage::disk('local')->assertExists('imports/' . $task->uuid . '/data.csv');
    }

    public function test_handle_deletes_uploads_directory_on_completion(): void
    {
        $task = $this->makeTask();
        $job  = new ImportCsvJob($task);
        $job->handle(app(CsvImportService::class));

        Storage::disk('local')->assertMissing('uploads/' . $task->uuid . '/data.csv');
    }

    // -------------------------------------------------------------------------
    // Group C — Failure path
    // -------------------------------------------------------------------------

    public function test_failed_sets_task_to_failed_and_propagates_runtime_exception_message(): void
    {
        $task = $this->makeTask();
        $job  = new ImportCsvJob($task);

        $job->failed(new \RuntimeException('CSV file contains duplicate column names.'));

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Failed, $fresh->status);
        $this->assertSame('CSV file contains duplicate column names.', $fresh->error_message);
    }

    public function test_failed_uses_generic_message_for_non_runtime_exceptions(): void
    {
        $task = $this->makeTask();
        $job  = new ImportCsvJob($task);

        $job->failed(new \Exception('Internal storage error'));

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Failed, $fresh->status);
        $this->assertSame('An unexpected error occurred during import.', $fresh->error_message);
    }

    public function test_failed_is_no_op_when_task_is_deleted(): void
    {
        $task = $this->makeTask();
        $id   = $task->id;
        $job  = new ImportCsvJob($task);

        $task->delete();

        $job->failed(new \RuntimeException('irrelevant'));

        $this->assertNull(Task::find($id));
    }

    public function test_failed_deletes_uploads_directory(): void
    {
        $task = $this->makeTask();
        $job  = new ImportCsvJob($task);

        $job->failed(new \RuntimeException('something failed'));

        Storage::disk('local')->assertMissing('uploads/' . $task->uuid . '/data.csv');
    }

    // -------------------------------------------------------------------------
    // Group D — Retry configuration
    // -------------------------------------------------------------------------

    public function test_job_has_correct_retry_configuration(): void
    {
        $task = $this->makeTask();
        $job  = new ImportCsvJob($task);

        $this->assertSame(3, $job->tries);
        $this->assertSame(120, $job->timeout);
        $this->assertSame([10, 30, 60], $job->backoff());
    }
}
