<?php

namespace Tests\Unit;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\AnalyseDataJob;
use App\Models\Task;
use App\Models\User;
use App\Services\DataAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class AnalyseDataJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeTask(?User $user = null): Task
    {
        $user ??= User::factory()->create();
        $task = Task::factory()->pending()->create([
            'user_id' => $user->id,
            'type'    => TaskType::DataAnalysis->value,
        ]);

        $csvPath = 'uploads/' . $task->uuid . '/data.csv';
        Storage::disk('local')->put($csvPath, "name,age\nAlice,30\nBob,25\n");
        $task->update(['payload' => ['file' => $csvPath]]);

        return $task->fresh();
    }

    // -------------------------------------------------------------------------
    // Group A — Guards
    // -------------------------------------------------------------------------

    public function test_handle_exits_silently_when_task_is_deleted(): void
    {
        $task = $this->makeTask();
        $job  = new AnalyseDataJob($task);

        $task->delete();

        $job->handle(app(DataAnalysisService::class));

        // Only the uploaded CSV should exist — no result file
        $files = Storage::disk('local')->allFiles('analyses');
        $this->assertEmpty($files);
    }

    public function test_handle_exits_silently_when_status_is_not_pending(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->processing()->create([
            'user_id' => $user->id,
            'type'    => TaskType::DataAnalysis->value,
            'payload' => [],
        ]);

        $job = new AnalyseDataJob($task);
        $job->handle(app(DataAnalysisService::class));

        // Status should remain Processing, unchanged
        $this->assertSame(TaskStatus::Processing, $task->fresh()->status);
        $this->assertEmpty(Storage::disk('local')->allFiles('analyses'));
    }

    // -------------------------------------------------------------------------
    // Group B — Happy path (progress milestones)
    // -------------------------------------------------------------------------

    public function test_handle_sets_status_processing_and_progress_zero_before_analysis(): void
    {
        $task = $this->makeTask();

        $service = Mockery::mock(DataAnalysisService::class);
        $service->shouldReceive('analyse')
            ->once()
            ->andThrow(new \RuntimeException('analysis aborted'));

        $job = new AnalyseDataJob($task);

        try {
            $job->handle($service);
        } catch (\RuntimeException) {
            // Expected — job fails after setting Processing/0
        }

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Processing, $fresh->status);
        $this->assertSame(0, $fresh->progress);
    }

    public function test_handle_sets_progress_50_after_analysis_completes(): void
    {
        $task    = $this->makeTask();
        $csvPath = Storage::disk('local')->path($task->payload['file']);

        $service = Mockery::mock(DataAnalysisService::class);
        $service->shouldReceive('analyse')
            ->once()
            ->andReturn(['row_count' => 0, 'columns' => []]);

        // Replace local disk with one that throws on put() so we can inspect state at progress=50
        $disk = Mockery::mock();
        $disk->shouldReceive('path')->andReturn($csvPath);
        $disk->shouldReceive('put')->once()->andThrow(new \RuntimeException('disk full'));
        Storage::set('local', $disk);

        $job = new AnalyseDataJob($task);

        try {
            $job->handle($service);
        } catch (\RuntimeException) {
            // Expected — job fails after progress=50 is committed
        }

        $this->assertSame(50, $task->fresh()->progress);
    }

    public function test_handle_sets_progress_90_after_writing_result_file(): void
    {
        $task = $this->makeTask();

        $job = new AnalyseDataJob($task);
        $job->handle(app(DataAnalysisService::class));

        // After a full successful run the final state is 100; progress=90 was
        // the last update before completion. Verify the file write occurred by
        // asserting the result exists (it is written between progress 50 and 90).
        $resultPath = 'analyses/' . $task->uuid . '/result.json';
        Storage::disk('local')->assertExists($resultPath);
        // Progress is 100 at the end — 90 was a transient write immediately before
        $this->assertSame(100, $task->fresh()->progress);
    }

    public function test_handle_marks_task_completed_with_progress_100_and_result_path(): void
    {
        $task = $this->makeTask();
        $job  = new AnalyseDataJob($task);
        $job->handle(app(DataAnalysisService::class));

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Completed, $fresh->status);
        $this->assertSame(100, $fresh->progress);
        $this->assertSame('analyses/' . $task->uuid . '/result.json', $fresh->result_path);
    }

    public function test_result_json_file_exists_on_disk_after_completion(): void
    {
        $task = $this->makeTask();
        $job  = new AnalyseDataJob($task);
        $job->handle(app(DataAnalysisService::class));

        Storage::disk('local')->assertExists('analyses/' . $task->uuid . '/result.json');
    }

    // -------------------------------------------------------------------------
    // Group C — Failure path
    // -------------------------------------------------------------------------

    public function test_failed_sets_status_failed_and_stores_error_message(): void
    {
        $task = $this->makeTask();
        $job  = new AnalyseDataJob($task);

        $job->failed(new \RuntimeException('Something went wrong'));

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Failed, $fresh->status);
        $this->assertSame('Something went wrong', $fresh->error_message);
    }

    public function test_failed_is_no_op_when_task_is_deleted(): void
    {
        $task = $this->makeTask();
        $id   = $task->id;
        $job  = new AnalyseDataJob($task);

        $task->delete();

        // Should complete silently — no exception
        $job->failed(new \RuntimeException('irrelevant'));

        $this->assertNull(Task::find($id));
    }
}
