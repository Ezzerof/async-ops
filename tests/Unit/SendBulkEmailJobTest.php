<?php

namespace Tests\Unit;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\SendBulkEmailJob;
use App\Mail\BulkEmailMailable;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendBulkEmailJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeTask(array $payload = [], string $status = 'pending'): Task
    {
        return Task::factory()->{$status}()->create([
            'type'    => TaskType::BulkEmail->value,
            'payload' => array_merge([
                'subject'         => 'Test Subject',
                'body'            => '<p>Hello</p>',
                'recipients'      => ['alice@example.com'],
                'attachment_path' => null,
                'delivery_status' => null,
            ], $payload),
        ]);
    }

    // -------------------------------------------------------------------------
    // Group A — Successful send
    // -------------------------------------------------------------------------

    public function test_handle_sends_to_the_correct_recipient(): void
    {
        Mail::fake();
        $task = $this->makeTask();

        $job = new SendBulkEmailJob($task, 'alice@example.com');
        $job->withFakeBatch();
        $job->handle(app(\Illuminate\Contracts\Mail\Mailer::class));

        Mail::assertSent(BulkEmailMailable::class, fn ($m) => $m->hasTo('alice@example.com'));
    }

    public function test_handle_sends_correct_subject_and_body(): void
    {
        Mail::fake();
        $task = $this->makeTask(['subject' => 'Invoice March', 'body' => '<p>See attached</p>']);

        $job = new SendBulkEmailJob($task, 'alice@example.com');
        $job->withFakeBatch();
        $job->handle(app(\Illuminate\Contracts\Mail\Mailer::class));

        Mail::assertSent(BulkEmailMailable::class, function ($m) {
            return $m->subject === 'Invoice March' && $m->body === '<p>See attached</p>';
        });
    }

    public function test_handle_passes_null_attachment_path_when_none_stored(): void
    {
        Mail::fake();
        $task = $this->makeTask(['attachment_path' => null]);

        $job = new SendBulkEmailJob($task, 'alice@example.com');
        $job->withFakeBatch();
        $job->handle(app(\Illuminate\Contracts\Mail\Mailer::class));

        Mail::assertSent(BulkEmailMailable::class, fn ($m) => $m->attachmentPath === null);
    }

    public function test_handle_reads_payload_from_freshly_loaded_task(): void
    {
        Mail::fake();

        // Create task with one payload; job is constructed with it
        $task = $this->makeTask(['subject' => 'Original Subject']);
        $job  = new SendBulkEmailJob($task, 'alice@example.com');
        $job->withFakeBatch();

        // Update payload after job construction — simulates attachment_path being written post-dispatch
        $task->update(['payload' => array_merge($task->payload, ['subject' => 'Updated Subject'])]);

        $job->handle(app(\Illuminate\Contracts\Mail\Mailer::class));

        // Job must have read the updated value, not the stale serialised one
        Mail::assertSent(BulkEmailMailable::class, fn ($m) => $m->subject === 'Updated Subject');
    }

    public function test_handle_appends_sent_to_delivery_status(): void
    {
        Mail::fake();
        $task = $this->makeTask();

        $job = new SendBulkEmailJob($task, 'alice@example.com');
        $job->withFakeBatch();
        $job->handle(app(\Illuminate\Contracts\Mail\Mailer::class));

        $status = $task->fresh()->payload['delivery_status'] ?? [];
        $this->assertSame('sent', $status['alice@example.com']);
    }

    public function test_handle_accumulates_delivery_status_across_recipients(): void
    {
        Mail::fake();
        $task = $this->makeTask(['recipients' => ['alice@example.com', 'bob@example.com']]);

        $job1 = new SendBulkEmailJob($task, 'alice@example.com');
        $job1->withFakeBatch();
        $job1->handle(app(\Illuminate\Contracts\Mail\Mailer::class));

        $job2 = new SendBulkEmailJob($task, 'bob@example.com');
        $job2->withFakeBatch();
        $job2->handle(app(\Illuminate\Contracts\Mail\Mailer::class));

        $status = $task->fresh()->payload['delivery_status'];
        $this->assertSame('sent', $status['alice@example.com']);
        $this->assertSame('sent', $status['bob@example.com']);
    }

    // -------------------------------------------------------------------------
    // Group B — Early returns
    // -------------------------------------------------------------------------

    public function test_handle_returns_early_when_batch_is_cancelled(): void
    {
        Mail::fake();
        $task = $this->makeTask();

        $job = new SendBulkEmailJob($task, 'alice@example.com');
        $job->withFakeBatch(cancelledAt: CarbonImmutable::now());
        $job->handle(app(\Illuminate\Contracts\Mail\Mailer::class));

        Mail::assertNothingSent();
    }

    public function test_handle_returns_early_when_task_is_deleted(): void
    {
        Mail::fake();
        $task = $this->makeTask();

        $job = new SendBulkEmailJob($task, 'alice@example.com');
        $job->withFakeBatch();

        $task->delete();
        $job->handle(app(\Illuminate\Contracts\Mail\Mailer::class));

        Mail::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Group C — failed() hook
    // -------------------------------------------------------------------------

    public function test_failed_appends_failed_to_delivery_status(): void
    {
        $task = $this->makeTask();

        $job = new SendBulkEmailJob($task, 'alice@example.com');
        $job->failed(new \RuntimeException('SMTP timeout'));

        $status = $task->fresh()->payload['delivery_status'] ?? [];
        $this->assertSame('failed', $status['alice@example.com']);
    }

    public function test_failed_initialises_delivery_status_when_null(): void
    {
        $task = $this->makeTask(['delivery_status' => null]);

        $job = new SendBulkEmailJob($task, 'alice@example.com');
        $job->failed(new \RuntimeException('SMTP timeout'));

        $status = $task->fresh()->payload['delivery_status'];
        $this->assertIsArray($status);
        $this->assertSame('failed', $status['alice@example.com']);
    }

    public function test_failed_does_not_change_task_status(): void
    {
        $task = $this->makeTask(status: 'pending');

        $job = new SendBulkEmailJob($task, 'alice@example.com');
        $job->failed(new \RuntimeException('SMTP timeout'));

        $this->assertSame(TaskStatus::Pending, $task->fresh()->status);
    }

    public function test_failed_returns_early_when_task_is_deleted(): void
    {
        $task = $this->makeTask();
        $job  = new SendBulkEmailJob($task, 'alice@example.com');

        $task->delete();

        // Should complete silently with no exception
        $job->failed(new \RuntimeException('irrelevant'));
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Group D — Configuration
    // -------------------------------------------------------------------------

    public function test_job_has_correct_retry_configuration(): void
    {
        $task = $this->makeTask();
        $job  = new SendBulkEmailJob($task, 'alice@example.com');

        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([30, 60, 120], $job->backoff());
        $this->assertSame('emails', $job->queue);
    }
}
