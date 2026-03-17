<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\DataAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AnalyseDataJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(public readonly Task $task) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(DataAnalysisService $service): void
    {
        // Guard against the task being deleted between dispatch and execution
        if (! Task::find((int) $this->task->id)) {
            return;
        }

        $this->task->refresh();

        // Guard against re-processing if already past pending (duplicate dispatch or manual retry)
        if ($this->task->status !== TaskStatus::Pending) {
            return;
        }

        $this->task->update([
            'status'   => TaskStatus::Processing,
            'progress' => 0,
        ]);

        $csvPath    = Storage::disk('local')->path($this->task->payload['file']);
        $resultPath = 'analyses/' . $this->task->uuid . '/result.json';

        $stats = $service->analyse($csvPath);

        $this->task->update(['progress' => 50]);

        Storage::disk('local')->put($resultPath, json_encode($stats, JSON_PRETTY_PRINT));

        $this->task->update(['progress' => 90]);

        $this->task->update([
            'status'      => TaskStatus::Completed,
            'progress'    => 100,
            'result_path' => $resultPath,
        ]);
    }

    public function failed(Throwable $e): void
    {
        // Task may no longer exist if deleted while the job was queued; skip silently
        if (! Task::find((int) $this->task->id)) {
            return;
        }

        $this->task->update([
            'status'        => TaskStatus::Failed,
            'error_message' => $e->getMessage(),
        ]);
    }
}
