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

        $expectedPath = 'reports/' . $task->uuid . '.csv';

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

        $content = Storage::disk('local')->get('reports/' . $task->uuid . '.csv');
        $rows    = array_values(array_filter(explode("\n", trim($content))));
        $header  = str_getcsv($rows[0]);

        $this->assertSame([
            'order_id', 'sale_date', 'customer_name', 'customer_email',
            'product', 'category', 'quantity', 'unit_price', 'total', 'region', 'salesperson',
        ], $header);
    }

    public function test_csv_contains_exactly_50_data_rows(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->pending()->create(['user_id' => $user->id]);

        dispatch(new GenerateReportJob($task));

        $content  = Storage::disk('local')->get('reports/' . $task->uuid . '.csv');
        $rows     = array_values(array_filter(explode("\n", trim($content))));
        $dataRows = array_slice($rows, 1);

        $this->assertCount(50, $dataRows);
    }

    public function test_csv_data_row_has_correct_column_count(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->pending()->create(['user_id' => $user->id]);

        dispatch(new GenerateReportJob($task));

        $content = Storage::disk('local')->get('reports/' . $task->uuid . '.csv');
        $rows    = array_values(array_filter(explode("\n", trim($content))));
        $dataRow = str_getcsv($rows[1]);

        $this->assertCount(11, $dataRow);
    }

    public function test_csv_total_equals_quantity_times_unit_price(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->pending()->create(['user_id' => $user->id]);

        dispatch(new GenerateReportJob($task));

        $content = Storage::disk('local')->get('reports/' . $task->uuid . '.csv');
        $rows    = array_values(array_filter(explode("\n", trim($content))));

        foreach (array_slice($rows, 1) as $row) {
            $cols     = str_getcsv($row);
            $quantity  = (int) $cols[6];
            $unitPrice = (float) $cols[7];
            $total     = (float) $cols[8];

            $this->assertEqualsWithDelta(round($quantity * $unitPrice, 2), $total, 0.001);
        }
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

        Storage::disk('local')->assertExists('reports/' . $task->uuid . '.csv');
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }
}
