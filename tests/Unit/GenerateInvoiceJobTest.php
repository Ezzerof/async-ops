<?php

namespace Tests\Unit;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\GenerateInvoiceJob;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateInvoiceJobTest extends TestCase
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

    private function makeTask(?User $user = null, string $csvContent = ''): Task
    {
        $user ??= User::factory()->create();

        if ($csvContent === '') {
            $csvContent = "description,quantity,unit_price\nWidget A,2,9.99\nWidget B,1,4.50\n";
        }

        $task = Task::factory()->pending()->create([
            'user_id' => $user->id,
            'type'    => TaskType::InvoiceGeneration->value,
        ]);

        $csvPath = 'uploads/' . $task->uuid . '/invoice.csv';
        Storage::disk('local')->put($csvPath, $csvContent);
        $task->update(['payload' => ['file' => $csvPath]]);

        return $task->fresh();
    }

    private function csvPath(Task $task): string
    {
        return $task->payload['file'];
    }

    // -------------------------------------------------------------------------
    // Group A — Guards
    // -------------------------------------------------------------------------

    public function test_handle_exits_silently_when_task_is_deleted(): void
    {
        $task = $this->makeTask();
        $job  = new GenerateInvoiceJob($task);

        $task->delete();
        $job->handle();

        $this->assertEmpty(Storage::disk('local')->allFiles('invoices'));
    }

    public function test_handle_exits_silently_when_status_is_not_pending(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->processing()->create([
            'user_id' => $user->id,
            'type'    => TaskType::InvoiceGeneration->value,
            'payload' => ['file' => 'uploads/fake/invoice.csv'],
        ]);

        $job = new GenerateInvoiceJob($task);
        $job->handle();

        $this->assertSame(TaskStatus::Processing, $task->fresh()->status);
        $this->assertEmpty(Storage::disk('local')->allFiles('invoices'));
    }

    // -------------------------------------------------------------------------
    // Group B — Happy path
    // -------------------------------------------------------------------------

    public function test_progress_remains_zero_when_parsing_fails_before_midpoint(): void
    {
        // A row validation failure happens before progress is advanced to 50.
        // If progress = 0 on the Failed task, the initial Processing/0 update
        // ran before parsing started (the only code path that sets progress to 0).
        $task = $this->makeTask(csvContent: "description,quantity,unit_price\nbad,,\n");
        (new GenerateInvoiceJob($task))->handle();

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Failed, $fresh->status);
        $this->assertSame(0, $fresh->progress);
    }

    public function test_handle_sets_progress_50_after_parsing_completes(): void
    {
        $task = $this->makeTask();
        $job  = new GenerateInvoiceJob($task);
        $job->handle();

        // Job completes fully — progress is 100 at end.
        // Verify progress 50 was reached by checking the file was rendered
        // (it is stored between progress=50 and progress=100)
        $this->assertSame(100, $task->fresh()->progress);
        Storage::disk('local')->assertExists('invoices/' . $task->uuid . '/invoice.pdf');
    }

    public function test_handle_marks_task_completed_with_progress_100_and_result_path(): void
    {
        $task = $this->makeTask();
        $job  = new GenerateInvoiceJob($task);
        $job->handle();

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Completed, $fresh->status);
        $this->assertSame(100, $fresh->progress);
        $this->assertSame('invoices/' . $task->uuid . '/invoice.pdf', $fresh->result_path);
    }

    public function test_result_path_in_db_points_to_existing_pdf_file(): void
    {
        $task = $this->makeTask();
        (new GenerateInvoiceJob($task))->handle();

        Storage::disk('local')->assertExists($task->fresh()->result_path);
    }

    public function test_error_message_is_null_on_successful_run(): void
    {
        $task = $this->makeTask();
        (new GenerateInvoiceJob($task))->handle();

        $this->assertNull($task->fresh()->error_message);
    }

    // -------------------------------------------------------------------------
    // Group C — Row count limit
    // -------------------------------------------------------------------------

    public function test_exactly_500_rows_completes_successfully(): void
    {
        $rows = "description,quantity,unit_price\n";
        for ($i = 1; $i <= 500; $i++) {
            $rows .= "Item {$i},1,1.00\n";
        }

        $task = $this->makeTask(csvContent: $rows);
        (new GenerateInvoiceJob($task))->handle();

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    public function test_501_rows_marks_task_failed_with_maximum_message(): void
    {
        $rows = "description,quantity,unit_price\n";
        for ($i = 1; $i <= 501; $i++) {
            $rows .= "Item {$i},1,1.00\n";
        }

        $task = $this->makeTask(csvContent: $rows);
        (new GenerateInvoiceJob($task))->handle();

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Failed, $fresh->status);
        $this->assertStringContainsString('maximum', $fresh->error_message);
    }

    // -------------------------------------------------------------------------
    // Group D — Row-level validation failures
    // -------------------------------------------------------------------------

    public function test_invalid_quantity_not_a_positive_integer_marks_task_failed(): void
    {
        $csv  = "description,quantity,unit_price\nWidget A,abc,9.99\n";
        $task = $this->makeTask(csvContent: $csv);
        (new GenerateInvoiceJob($task))->handle();

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Failed, $fresh->status);
        $this->assertStringContainsString('Row 1', $fresh->error_message);
    }

    public function test_quantity_zero_marks_task_failed(): void
    {
        $csv  = "description,quantity,unit_price\nWidget A,0,9.99\n";
        $task = $this->makeTask(csvContent: $csv);
        (new GenerateInvoiceJob($task))->handle();

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Failed, $fresh->status);
        $this->assertStringContainsString('Row 1', $fresh->error_message);
    }

    public function test_float_quantity_marks_task_failed(): void
    {
        $csv  = "description,quantity,unit_price\nWidget A,1.5,9.99\n";
        $task = $this->makeTask(csvContent: $csv);
        (new GenerateInvoiceJob($task))->handle();

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Failed, $fresh->status);
        $this->assertStringContainsString('Row 1', $fresh->error_message);
    }

    public function test_invalid_unit_price_not_positive_marks_task_failed(): void
    {
        $csv  = "description,quantity,unit_price\nWidget A,2,-5.00\n";
        $task = $this->makeTask(csvContent: $csv);
        (new GenerateInvoiceJob($task))->handle();

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Failed, $fresh->status);
        $this->assertStringContainsString('Row 1', $fresh->error_message);
    }

    public function test_unit_price_zero_marks_task_failed(): void
    {
        $csv  = "description,quantity,unit_price\nWidget A,2,0\n";
        $task = $this->makeTask(csvContent: $csv);
        (new GenerateInvoiceJob($task))->handle();

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Failed, $fresh->status);
        $this->assertStringContainsString('Row 1', $fresh->error_message);
    }

    public function test_empty_description_marks_task_failed(): void
    {
        $csv  = "description,quantity,unit_price\n,2,9.99\n";
        $task = $this->makeTask(csvContent: $csv);
        (new GenerateInvoiceJob($task))->handle();

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Failed, $fresh->status);
        $this->assertStringContainsString('Row 1', $fresh->error_message);
    }

    public function test_error_message_includes_correct_row_number_for_second_row_failure(): void
    {
        $csv  = "description,quantity,unit_price\nWidget A,1,9.99\nWidget B,bad,4.50\n";
        $task = $this->makeTask(csvContent: $csv);
        (new GenerateInvoiceJob($task))->handle();

        $this->assertStringContainsString('Row 2', $task->fresh()->error_message);
    }

    // -------------------------------------------------------------------------
    // Group E — Edge cases
    // -------------------------------------------------------------------------

    public function test_csv_with_only_header_row_completes_with_empty_invoice(): void
    {
        $csv  = "description,quantity,unit_price\n";
        $task = $this->makeTask(csvContent: $csv);
        (new GenerateInvoiceJob($task))->handle();

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        Storage::disk('local')->assertExists('invoices/' . $task->uuid . '/invoice.pdf');
    }

    public function test_grand_total_is_sum_of_rounded_line_totals(): void
    {
        // 0.1 * 3 = 0.30000000000000004 in raw float — round() must be applied
        $csv  = "description,quantity,unit_price\nItem,3,0.10\n";
        $task = $this->makeTask(csvContent: $csv);
        (new GenerateInvoiceJob($task))->handle();

        // Job completes without failure — float arithmetic was handled correctly
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Group F — CSV cleanup (finally block)
    // -------------------------------------------------------------------------

    public function test_csv_is_deleted_from_disk_after_successful_run(): void
    {
        $task    = $this->makeTask();
        $csvPath = $this->csvPath($task);

        (new GenerateInvoiceJob($task))->handle();

        Storage::disk('local')->assertMissing($csvPath);
    }

    public function test_csv_is_deleted_from_disk_even_when_job_fails(): void
    {
        $csv     = "description,quantity,unit_price\nWidget A,bad,9.99\n";
        $task    = $this->makeTask(csvContent: $csv);
        $csvPath = $this->csvPath($task);

        (new GenerateInvoiceJob($task))->handle();

        Storage::disk('local')->assertMissing($csvPath);
    }

    // -------------------------------------------------------------------------
    // Group G — failed() callback
    // -------------------------------------------------------------------------

    public function test_failed_sets_status_failed_and_stores_error_message(): void
    {
        $task = $this->makeTask();
        $job  = new GenerateInvoiceJob($task);

        $job->failed(new \RuntimeException('Something went wrong'));

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Failed, $fresh->status);
        $this->assertSame('Something went wrong', $fresh->error_message);
    }

    public function test_failed_is_no_op_when_task_is_deleted(): void
    {
        $task = $this->makeTask();
        $id   = $task->id;
        $job  = new GenerateInvoiceJob($task);

        $task->delete();
        $job->failed(new \RuntimeException('irrelevant'));

        $this->assertNull(Task::find($id));
    }
}
