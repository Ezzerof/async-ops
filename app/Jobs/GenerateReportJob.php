<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public readonly Task $task) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        // Fix #2 — guard against the task being deleted between dispatch and execution
        if (! Task::find((int) $this->task->id)) {
            return;
        }

        $this->task->refresh();

        // Fix #3 — prevent re-processing if already past pending (duplicate dispatch or manual retry)
        if ($this->task->status !== TaskStatus::Pending) {
            return;
        }

        $this->task->update([
            'status'   => TaskStatus::Processing,
            'progress' => 0,
        ]);

        $total      = User::count();
        $threshold  = max(1, (int) ceil($total / 10));
        $nextUpdate = $threshold;
        $rowCount   = 0;

        // Fix #4 — guarantee stream is closed even if an exception is thrown mid-loop
        $stream = fopen('php://temp', 'r+');

        try {
            fputcsv($stream, ['id', 'name', 'email', 'created_at']);

            User::select('id', 'name', 'email', 'created_at')->cursor()->each(
                function (User $user) use ($stream, $total, $threshold, &$rowCount, &$nextUpdate): void {
                    fputcsv($stream, [
                        $user->id,
                        $user->name,
                        $user->email,
                        $user->created_at,
                    ]);

                    $rowCount++;

                    if ($rowCount >= $nextUpdate) {
                        $progress = (int) min(99, round($rowCount / $total * 100));
                        $this->task->update(['progress' => $progress]);
                        $nextUpdate += $threshold;
                    }
                }
            );

            rewind($stream);

            // Fix #1 — explicitly cast ID to int, defensive against any future path construction using user input
            $path = 'reports/' . ((int) $this->task->id) . '.csv';
            Storage::disk('local')->put($path, $stream);

            $this->task->update([
                'status'      => TaskStatus::Completed,
                'progress'    => 100,
                'result_path' => $path,
            ]);
        } finally {
            fclose($stream);
        }
    }

    public function failed(Throwable $e): void
    {
        // Fix #2 — task may no longer exist if the user was deleted; skip silently
        if (! Task::find((int) $this->task->id)) {
            return;
        }

        $this->task->update([
            'status'        => TaskStatus::Failed,
            'error_message' => $e->getMessage(),
        ]);
    }
}
