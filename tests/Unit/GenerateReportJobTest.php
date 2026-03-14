<?php

namespace Tests\Unit;

use App\Enums\TaskStatus;
use App\Jobs\GenerateReportJob;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateReportJobTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Group A — Happy Path
    // -------------------------------------------------------------------------

    public function test_job_completes_successfully_and_sets_progress_to_100(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        User::factory()->count(2)->create();
        $task = Task::factory()->pending()->create(['user_id' => $user->id]);

        dispatch(new GenerateReportJob($task));

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Completed, $fresh->status);
        $this->assertSame(100, $fresh->progress);
    }

    public function test_job_stores_result_path_and_creates_file(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->pending()->create(['user_id' => $user->id]);

        dispatch(new GenerateReportJob($task));

        $expectedPath = 'reports/' . $task->id . '.csv';

        $this->assertSame($expectedPath, $task->fresh()->result_path);
        Storage::disk('local')->assertExists($expectedPath);
    }

    public function test_job_leaves_error_message_null_on_success(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->pending()->create(['user_id' => $user->id]);

        dispatch(new GenerateReportJob($task));

        $this->assertNull($task->fresh()->error_message);
    }

    // -------------------------------------------------------------------------
    // Group B — Guard Behaviour
    // -------------------------------------------------------------------------

    public function test_handle_returns_early_when_task_is_deleted(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->pending()->create(['user_id' => $user->id]);

        $job = new GenerateReportJob($task);
        $task->delete();

        $job->handle();

        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_handle_returns_early_when_task_is_not_pending(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->processing()->create(['user_id' => $user->id]);

        $job = new GenerateReportJob($task);
        $job->handle();

        $this->assertEmpty(Storage::disk('local')->allFiles());
        $this->assertSame(TaskStatus::Processing, $task->fresh()->status);
        $this->assertSame(50, $task->fresh()->progress);
    }

    // -------------------------------------------------------------------------
    // Group C — CSV Behaviour
    // -------------------------------------------------------------------------

    public function test_csv_has_correct_header_row(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->pending()->create(['user_id' => $user->id]);

        dispatch(new GenerateReportJob($task));

        $content = Storage::disk('local')->get('reports/' . $task->id . '.csv');
        $rows    = array_values(array_filter(explode("\n", trim($content))));
        $header  = str_getcsv($rows[0]);

        $this->assertSame(['id', 'name', 'email', 'created_at'], $header);
    }

    public function test_csv_contains_one_data_row_per_user(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        User::factory()->count(4)->create();
        $task = Task::factory()->pending()->create(['user_id' => $user->id]);

        dispatch(new GenerateReportJob($task));

        $content  = Storage::disk('local')->get('reports/' . $task->id . '.csv');
        $rows     = array_values(array_filter(explode("\n", trim($content))));
        $dataRows = array_slice($rows, 1);

        $this->assertCount(5, $dataRows);
    }

    public function test_csv_data_row_matches_known_user_values(): void
    {
        Storage::fake('local');

        $user = User::factory()->create(['name' => 'Alice Example', 'email' => 'alice@example.com']);
        $task = Task::factory()->pending()->create(['user_id' => $user->id]);

        dispatch(new GenerateReportJob($task));

        $content = Storage::disk('local')->get('reports/' . $task->id . '.csv');
        $rows    = array_values(array_filter(explode("\n", trim($content))));
        $dataRow = str_getcsv($rows[1]);

        $this->assertSame((string) $user->id, $dataRow[0]);
        $this->assertSame('Alice Example', $dataRow[1]);
        $this->assertSame('alice@example.com', $dataRow[2]);
        $this->assertNotEmpty($dataRow[3]);
    }

    public function test_csv_completes_cleanly_with_minimal_user_count(): void
    {
        // The FK constraint requires at least one user (the task owner).
        // This is the minimum-scale path: max(1, ceil(1/10)) = 1 threshold.
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->pending()->create(['user_id' => $user->id]);

        dispatch(new GenerateReportJob($task));

        $content  = Storage::disk('local')->get('reports/' . $task->id . '.csv');
        $rows     = array_values(array_filter(explode("\n", trim($content))));

        $this->assertCount(2, $rows); // header + 1 data row
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Group D — Failure Behaviour
    // -------------------------------------------------------------------------

    public function test_failed_marks_task_as_failed_and_stores_error_message(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->pending()->create(['user_id' => $user->id]);

        $job = new GenerateReportJob($task);
        $job->failed(new \RuntimeException('Something went wrong'));

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Failed, $fresh->status);
        $this->assertSame('Something went wrong', $fresh->error_message);
    }

    public function test_failed_does_nothing_harmful_when_task_is_deleted(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->pending()->create(['user_id' => $user->id]);
        $id   = $task->id;

        $job = new GenerateReportJob($task);
        $task->delete();

        $job->failed(new \RuntimeException('irrelevant'));

        $this->assertNull(Task::find($id));
    }

    // -------------------------------------------------------------------------
    // Group E — Storage
    // -------------------------------------------------------------------------

    public function test_result_path_in_database_points_to_existing_file(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->pending()->create(['user_id' => $user->id]);

        dispatch(new GenerateReportJob($task));

        Storage::disk('local')->assertExists($task->fresh()->result_path);
    }

    public function test_file_is_stored_on_the_local_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = User::factory()->create();
        $task = Task::factory()->pending()->create(['user_id' => $user->id]);

        dispatch(new GenerateReportJob($task));

        Storage::disk('local')->assertExists('reports/' . $task->id . '.csv');
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }
}
