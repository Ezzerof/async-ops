<?php

namespace Tests\Unit;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\SendBulkEmailJob;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class TaskServiceBulkEmailTest extends TestCase
{
    use RefreshDatabase;

    private TaskService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TaskService::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    /**
     * Call createBulkEmailTask() with sensible defaults, allowing per-test overrides.
     */
    private function dispatch(array $overrides = []): Task
    {
        return $this->service->createBulkEmailTask(
            user:       $overrides['user']       ?? $this->makeUser(),
            recipients: $overrides['recipients'] ?? ['alice@example.com', 'bob@example.com'],
            subject:    $overrides['subject']    ?? 'Hello',
            body:       $overrides['body']       ?? '<p>Test body</p>',
            attachment: $overrides['attachment'] ?? null,
        );
    }

    /**
     * Invoke the then() closures from the most recently dispatched fake batch.
     */
    private function invokeThen(): void
    {
        $fakeBatch    = Mockery::mock(Batch::class);
        $pendingBatch = Bus::dispatchedBatches()[0];
        foreach ($pendingBatch->thenCallbacks() as $cb) {
            $cb($fakeBatch);
        }
    }

    /**
     * Invoke the catch() closures from the most recently dispatched fake batch.
     */
    private function invokeCatch(\Throwable $e): void
    {
        $fakeBatch    = Mockery::mock(Batch::class);
        $pendingBatch = Bus::dispatchedBatches()[0];
        foreach ($pendingBatch->catchCallbacks() as $cb) {
            $cb($fakeBatch, $e);
        }
    }

    // -------------------------------------------------------------------------
    // Group A — Dispatch behaviour
    // -------------------------------------------------------------------------

    public function test_batch_id_is_written_to_payload_after_dispatch(): void
    {
        Bus::fake();
        Storage::fake('local');

        $task = $this->dispatch();

        $this->assertNotNull($task->fresh()->payload['batch_id'] ?? null);
    }

    public function test_delivery_status_is_absent_from_initial_payload(): void
    {
        Bus::fake();
        Storage::fake('local');

        $task = $this->dispatch();

        $this->assertArrayNotHasKey('delivery_status', $task->fresh()->payload);
    }

    public function test_one_job_dispatched_per_recipient(): void
    {
        Bus::fake();
        Storage::fake('local');

        $this->dispatch(['recipients' => ['alice@example.com', 'bob@example.com', 'carol@example.com']]);

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 3);
    }

    public function test_each_dispatched_job_carries_its_own_recipient(): void
    {
        Bus::fake();
        Storage::fake('local');

        $recipients = ['alice@example.com', 'bob@example.com'];
        $this->dispatch(['recipients' => $recipients]);

        Bus::assertBatched(function ($batch) use ($recipients): bool {
            $jobRecipients = $batch->jobs
                ->map(fn (SendBulkEmailJob $j) => $j->recipient)
                ->sort()->values()->all();

            return $jobRecipients === collect($recipients)->sort()->values()->all();
        });
    }

    public function test_batch_is_dispatched_with_allow_failures(): void
    {
        Bus::fake();
        Storage::fake('local');

        $this->dispatch();

        $this->assertTrue(Bus::dispatchedBatches()[0]->allowsFailures());
    }

    // -------------------------------------------------------------------------
    // Group B — Attachment handling
    // -------------------------------------------------------------------------

    public function test_attachment_path_is_null_when_no_file_provided(): void
    {
        Bus::fake();
        Storage::fake('local');

        $task = $this->dispatch(['attachment' => null]);

        $this->assertNull($task->fresh()->payload['attachment_path']);
    }

    public function test_no_files_written_when_no_attachment(): void
    {
        Bus::fake();
        Storage::fake('local');

        $task = $this->dispatch(['attachment' => null]);

        $this->assertEmpty(Storage::disk('local')->allFiles('emails/' . $task->uuid));
    }

    public function test_attachment_is_stored_under_task_uuid_directory(): void
    {
        Bus::fake();
        Storage::fake('local');

        $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');
        $task = $this->dispatch(['attachment' => $file]);

        $attachmentPath = $task->fresh()->payload['attachment_path'];
        $this->assertStringStartsWith('emails/' . $task->uuid . '/', $attachmentPath);
        Storage::disk('local')->assertExists($attachmentPath);
    }

    public function test_attachment_path_uses_uuid_filename(): void
    {
        Bus::fake();
        Storage::fake('local');

        $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');
        $task = $this->dispatch(['attachment' => $file]);

        $attachmentPath = $task->fresh()->payload['attachment_path'];
        $this->assertMatchesRegularExpression('/attachment_[0-9a-f-]{36}\.pdf$/', $attachmentPath);
    }

    public function test_task_is_deleted_when_attachment_store_fails(): void
    {
        Bus::fake();
        Storage::fake('local');

        $file = Mockery::mock(UploadedFile::class);
        $file->shouldReceive('storeAs')->andReturn(false);
        $file->shouldReceive('getClientOriginalName')->andReturn('invoice.pdf');

        try {
            $this->dispatch(['attachment' => $file]);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, Task::where('type', TaskType::BulkEmail->value)->count());
    }

    public function test_directory_is_cleaned_up_when_attachment_store_fails(): void
    {
        Bus::fake();
        Storage::fake('local');

        $file = Mockery::mock(UploadedFile::class);
        $file->shouldReceive('storeAs')->andReturn(false);
        $file->shouldReceive('getClientOriginalName')->andReturn('invoice.pdf');

        $taskUuid = null;

        try {
            $task     = $this->dispatch(['attachment' => $file]);
            $taskUuid = $task->uuid;
        } catch (\RuntimeException $e) {
            // Capture uuid from the thrown context via DB — it won't exist post-cleanup
        }

        // No emails directory should remain
        $this->assertEmpty(Storage::disk('local')->allFiles('emails'));
    }

    // -------------------------------------------------------------------------
    // Group C — then() callback
    // -------------------------------------------------------------------------

    public function test_then_marks_task_completed_when_at_least_one_delivered(): void
    {
        Bus::fake();
        Storage::fake('local');

        $task = $this->dispatch(['recipients' => ['alice@example.com', 'bob@example.com']]);

        $task->update(['payload' => array_merge($task->payload, [
            'delivery_status' => ['alice@example.com' => 'sent', 'bob@example.com' => 'failed'],
        ])]);

        $this->invokeThen();

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $this->assertSame(100, $task->fresh()->progress);
    }

    public function test_then_writes_report_csv_with_header_and_data_rows(): void
    {
        Bus::fake();
        Storage::fake('local');

        $task = $this->dispatch(['recipients' => ['alice@example.com', 'bob@example.com']]);

        $task->update(['payload' => array_merge($task->payload, [
            'delivery_status' => ['alice@example.com' => 'sent', 'bob@example.com' => 'failed'],
        ])]);

        $this->invokeThen();

        $reportPath = 'emails/' . $task->uuid . '/report.csv';
        Storage::disk('local')->assertExists($reportPath);

        $csv = Storage::disk('local')->get($reportPath);
        $this->assertStringContainsString('recipient,status', $csv);
        $this->assertStringContainsString('alice@example.com,sent', $csv);
        $this->assertStringContainsString('bob@example.com,failed', $csv);
    }

    public function test_then_sets_result_path_to_report_csv(): void
    {
        Bus::fake();
        Storage::fake('local');

        $task = $this->dispatch(['recipients' => ['alice@example.com']]);

        $task->update(['payload' => array_merge($task->payload, [
            'delivery_status' => ['alice@example.com' => 'sent'],
        ])]);

        $this->invokeThen();

        $this->assertSame('emails/' . $task->uuid . '/report.csv', $task->fresh()->result_path);
    }

    public function test_then_deletes_attachment_at_exact_stored_path(): void
    {
        Bus::fake();
        Storage::fake('local');

        $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');
        $task = $this->dispatch(['recipients' => ['alice@example.com'], 'attachment' => $file]);

        $attachmentPath = $task->fresh()->payload['attachment_path'];
        Storage::disk('local')->assertExists($attachmentPath);

        $task->update(['payload' => array_merge($task->payload, [
            'delivery_status' => ['alice@example.com' => 'sent'],
        ])]);

        $this->invokeThen();

        Storage::disk('local')->assertMissing($attachmentPath);
    }

    public function test_then_does_not_delete_files_outside_task_uuid_directory(): void
    {
        Bus::fake();
        Storage::fake('local');

        $task = $this->dispatch(['recipients' => ['alice@example.com']]);

        // Tampered path pointing outside the task's own directory
        $task->update(['payload' => array_merge($task->payload, [
            'attachment_path' => 'emails/other-uuid/attachment_evil.pdf',
            'delivery_status' => ['alice@example.com' => 'sent'],
        ])]);

        Storage::disk('local')->put('emails/other-uuid/attachment_evil.pdf', 'sensitive');

        $this->invokeThen();

        Storage::disk('local')->assertExists('emails/other-uuid/attachment_evil.pdf');
    }

    public function test_then_marks_task_failed_when_all_deliveries_failed(): void
    {
        Bus::fake();
        Storage::fake('local');

        $task = $this->dispatch(['recipients' => ['alice@example.com', 'bob@example.com']]);

        $task->update(['payload' => array_merge($task->payload, [
            'delivery_status' => ['alice@example.com' => 'failed', 'bob@example.com' => 'failed'],
        ])]);

        $this->invokeThen();

        $this->assertSame(TaskStatus::Failed, $task->fresh()->status);
        $this->assertSame('No emails were delivered successfully.', $task->fresh()->error_message);
    }

    public function test_then_marks_task_failed_when_delivery_status_is_null(): void
    {
        Bus::fake();
        Storage::fake('local');

        $task = $this->dispatch(['recipients' => ['alice@example.com', 'bob@example.com']]);

        $task->update(['payload' => array_merge($task->payload, ['delivery_status' => null])]);

        $this->invokeThen();

        $this->assertSame(TaskStatus::Failed, $task->fresh()->status);
    }

    public function test_then_returns_early_when_task_is_deleted(): void
    {
        Bus::fake();
        Storage::fake('local');

        $task = $this->dispatch(['recipients' => ['alice@example.com']]);
        $task->delete();

        $this->invokeThen();

        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Group D — catch() callback
    // -------------------------------------------------------------------------

    public function test_catch_sets_task_to_failed_on_infrastructure_error(): void
    {
        Bus::fake();
        Storage::fake('local');

        $task = $this->dispatch();

        $this->invokeCatch(new \RuntimeException('Redis unavailable'));

        $this->assertSame(TaskStatus::Failed, $task->fresh()->status);
        $this->assertSame('Batch processing failed unexpectedly.', $task->fresh()->error_message);
    }

    public function test_catch_does_not_overwrite_completed_status(): void
    {
        Bus::fake();
        Storage::fake('local');

        $task = $this->dispatch();
        $task->update(['status' => TaskStatus::Completed, 'progress' => 100]);

        $this->invokeCatch(new \RuntimeException('Redis unavailable'));

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    public function test_catch_does_not_overwrite_already_failed_status(): void
    {
        Bus::fake();
        Storage::fake('local');

        $task = $this->dispatch();
        $task->update(['status' => TaskStatus::Failed, 'error_message' => 'Already failed.']);

        $this->invokeCatch(new \RuntimeException('Redis unavailable'));

        $this->assertSame('Already failed.', $task->fresh()->error_message);
    }

    public function test_catch_returns_early_when_task_is_deleted(): void
    {
        Bus::fake();
        Storage::fake('local');

        $task = $this->dispatch();
        $task->delete();

        $this->invokeCatch(new \RuntimeException('Redis unavailable'));

        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Group E — Guard
    // -------------------------------------------------------------------------

    public function test_empty_recipients_throws_before_creating_task(): void
    {
        Bus::fake();
        Storage::fake('local');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->createBulkEmailTask(
            user:       $this->makeUser(),
            recipients: [],
            subject:    'Hello',
            body:       '<p>Body</p>',
            attachment: null,
        );
    }

    public function test_no_task_created_when_recipients_empty(): void
    {
        Bus::fake();
        Storage::fake('local');

        try {
            $this->service->createBulkEmailTask(
                user:       $this->makeUser(),
                recipients: [],
                subject:    'Hello',
                body:       '<p>Body</p>',
                attachment: null,
            );
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, Task::where('type', TaskType::BulkEmail->value)->count());
    }
}
