<?php

namespace App\Jobs;

use App\Enums\ConversionFormat;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\FileConversionService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ConvertFileJob implements ShouldQueue
{
    use Queueable, Batchable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly Task             $task,
        public readonly string           $sourcePath,
        public readonly ConversionFormat $targetFormat,
    ) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(FileConversionService $service): void
    {
        // Returning here (rather than throwing) counts this job as succeeded in the
        // batch progress. This is intentional — a cancelled batch is already failed;
        // the catch callback handles the task status transition.
        if ($this->batch()->cancelled()) {
            Log::warning('[FileConversion] Idempotency skip — batch cancelled.', [
                'task_uuid'   => $this->task->uuid,
                'source_path' => $this->sourcePath,
            ]);
            return;
        }

        if (! $this->taskExists()) {
            Log::warning('[FileConversion] Idempotency skip — task deleted.', [
                'task_uuid'   => $this->task->uuid,
                'source_path' => $this->sourcePath,
            ]);
            return;
        }

        Log::info('[FileConversion] Job started.', [
            'task_uuid'     => $this->task->uuid,
            'source_path'   => $this->sourcePath,
            'target_format' => $this->targetFormat->value,
        ]);

        $outputPath = $service->convert($this->sourcePath, $this->targetFormat);

        // Accumulate output paths in the payload so the batch then() callback
        // can build the zip without needing to scan the conversions directory.
        $task    = $this->task->refresh();
        $payload = $task->payload ?? [];
        $payload['output_files'][] = $outputPath;
        $task->update(['payload' => $payload]);

        Log::info('[FileConversion] Job completed.', [
            'task_uuid'   => $this->task->uuid,
            'output_path' => $outputPath,
        ]);
    }

    public function failed(Throwable $e): void
    {
        if (! $this->taskExists()) {
            Log::warning('[FileConversion] Job failed — task already deleted, skipping status update.', [
                'task_uuid'   => $this->task->uuid,
                'source_path' => $this->sourcePath,
            ]);
            return;
        }

        // Only propagate messages from intentional RuntimeExceptions thrown by
        // FileConversionService. All other exceptions get a generic message to
        // avoid leaking internal details to the API client.
        $message = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'An unexpected error occurred during file conversion.';

        $this->task->update([
            'status'        => TaskStatus::Failed,
            'error_message' => $message,
        ]);

        Log::error('[FileConversion] Job failed.', [
            'task_uuid'   => $this->task->uuid,
            'source_path' => $this->sourcePath,
            'exception'   => $e->getMessage(),
            'trace'       => $e->getTraceAsString(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function taskExists(): bool
    {
        return Task::find((int) $this->task->id) !== null;
    }
}
