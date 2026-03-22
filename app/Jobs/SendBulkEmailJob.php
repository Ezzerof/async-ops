<?php

namespace App\Jobs;

use App\Mail\BulkEmailMailable;
use App\Models\Task;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendBulkEmailJob implements ShouldQueue
{
    use Queueable, Batchable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 30;

    public function __construct(
        public readonly Task   $task,
        public readonly string $recipient,
    ) {
        $this->onQueue('emails');
    }

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(Mailer $mailer): void
    {
        if ($this->batch()->cancelled()) {
            Log::warning('[BulkEmail] Idempotency skip — batch cancelled.', [
                'task_uuid' => $this->task->uuid,
                'recipient' => $this->recipient,
            ]);
            return;
        }

        $task = Task::find($this->task->id);

        if ($task === null) {
            Log::warning('[BulkEmail] Idempotency skip — task deleted.', [
                'task_uuid' => $this->task->uuid,
                'recipient' => $this->recipient,
            ]);
            return;
        }

        Log::info('[BulkEmail] Job started — sending to recipient.', [
            'task_uuid' => $this->task->uuid,
            'recipient' => $this->recipient,
        ]);

        $payload  = $task->payload ?? [];
        $mailable = new BulkEmailMailable(
            subject:        $payload['subject'],
            body:           $payload['body'],
            attachmentPath: $payload['attachment_path'] ?? null,
        );

        $mailer->to($this->recipient)->send($mailable);

        $task    = $task->refresh();
        $payload = $task->payload ?? [];
        $payload['delivery_status'][$this->recipient] = 'sent';
        $task->update(['payload' => $payload]);

        Log::info('[BulkEmail] Job completed — email sent.', [
            'task_uuid' => $this->task->uuid,
            'recipient' => $this->recipient,
        ]);
    }

    public function failed(Throwable $e): void
    {
        $task = Task::find($this->task->id);

        if ($task === null) {
            Log::warning('[BulkEmail] Job failed — task already deleted, skipping status update.', [
                'task_uuid' => $this->task->uuid,
                'recipient' => $this->recipient,
            ]);
            return;
        }

        $task    = $task->refresh();
        $payload = $task->payload ?? [];
        $payload['delivery_status'][$this->recipient] = 'failed';
        $task->update(['payload' => $payload]);

        Log::error('[BulkEmail] Job failed — delivery failed for recipient.', [
            'task_uuid' => $this->task->uuid,
            'recipient' => $this->recipient,
            'exception' => $e->getMessage(),
            'trace'     => $e->getTraceAsString(),
        ]);
    }
}
