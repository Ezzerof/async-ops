<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\CsvImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ImportCsvJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(public readonly Task $task) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(CsvImportService $service): void
    {
        // Guard against the task being deleted between dispatch and execution
        if (! $this->taskExists()) {
            return;
        }

        $this->task->refresh();

        // Guard against re-processing if already past pending
        if ($this->task->status !== TaskStatus::Pending) {
            return;
        }

        $this->task->update([
            'status'   => TaskStatus::Processing,
            'progress' => 0,
        ]);

        $uploadPath       = $this->task->payload['file'];
        $originalFilename = $this->task->payload['original_filename'];
        $absolutePath     = Storage::disk('local')->path($uploadPath);

        // Validation errors are deterministic — retrying will not fix them.
        // Call failed() directly to update task state and return cleanly so
        // the queue does not schedule another attempt.
        try {
            ['headers' => $headers, 'row_count' => $rowCount] = $service->validateAndCount($absolutePath);
        } catch (\RuntimeException $e) {
            $this->failed($e);
            return;
        }

        $importPath = 'imports/' . $this->task->uuid . '/' . basename($uploadPath);
        Storage::disk('local')->move($uploadPath, $importPath);

        $import = $service->createRecord($this->task, $importPath, $originalFilename, $headers, $rowCount);

        Storage::disk('local')->deleteDirectory('uploads/' . $this->task->uuid);

        $this->task->update([
            'status'   => TaskStatus::Completed,
            'progress' => 100,
            'payload'  => array_merge($this->task->payload ?? [], ['csv_import_id' => $import->id]),
        ]);
    }

    public function failed(Throwable $e): void
    {
        // Task may no longer exist if deleted while the job was queued; skip silently
        if (! $this->taskExists()) {
            return;
        }

        // Only propagate messages from intentional RuntimeExceptions thrown by
        // CsvImportService. All other exceptions get a generic message to avoid
        // leaking internal details (e.g. storage paths) to the API client.
        $message = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'An unexpected error occurred during import.';

        $this->task->update([
            'status'        => TaskStatus::Failed,
            'error_message' => $message,
        ]);

        // Clean up any raw upload that may still be on disk
        Storage::disk('local')->deleteDirectory('uploads/' . $this->task->uuid);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function taskExists(): bool
    {
        return Task::find($this->task->id) !== null;
    }
}
