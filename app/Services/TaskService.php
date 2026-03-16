<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Jobs\GenerateReportJob;
use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class TaskService
{
    public function createAndDispatch(User $user, string $type, ?array $payload = null): Task
    {
        $task = Task::create([
            'user_id'  => $user->id,
            'type'     => $type,
            'status'   => TaskStatus::Pending,
            'progress' => 0,
            'payload'  => $payload,
        ]);

        GenerateReportJob::dispatch($task);

        return $task;
    }

    /**
     * Create a Task, dispatch a Bus::batch() of jobs, store the batch ID on the
     * task payload, and return the Task. The then/catch callbacks handle the
     * Completed/Failed status transitions so the controller stays thin.
     *
     * @param  \Illuminate\Contracts\Queue\ShouldQueue[]  $jobs
     */
    public function createAndDispatchBatch(User $user, string $type, array $jobs, ?array $payload = null): Task
    {
        $task = Task::create([
            'user_id'  => $user->id,
            'type'     => $type,
            'status'   => TaskStatus::Pending,
            'progress' => 0,
            'payload'  => $payload,
        ]);

        $batch = Bus::batch($jobs)
            ->then(function (Batch $batch) use ($task): void {
                if (! Task::find($task->id)) {
                    return;
                }

                $task->refresh();

                // Fix 3 — filter out any null, non-string, or missing entries written by cancelled jobs
                $outputFiles = array_filter(
                    $task->payload['output_files'] ?? [],
                    fn ($path) => is_string($path) && Storage::exists($path)
                );

                // Fix 5 — empty output is a failure, not a completed task
                if (empty($outputFiles)) {
                    $task->update([
                        'status'        => TaskStatus::Failed,
                        'error_message' => 'No output files were produced.',
                    ]);
                    return;
                }

                $resultPath = count($outputFiles) === 1
                    ? array_values($outputFiles)[0]
                    : $this->zipOutputFiles($task->uuid, $outputFiles);

                $task->update([
                    'status'      => TaskStatus::Completed,
                    'result_path' => $resultPath,
                ]);
            })
            ->catch(function (Batch $batch, \Throwable $e) use ($task): void {
                if (! Task::find($task->id)) {
                    return;
                }

                // Guard against race with ConvertFileJob::failed() — only write
                // if a per-job failed() hook hasn't already set the status.
                $task->refresh();
                if ($task->status !== TaskStatus::Failed) {
                    $task->update([
                        'status'        => TaskStatus::Failed,
                        'error_message' => 'One or more files could not be converted.',
                    ]);
                }
            })
            ->dispatch();

        // Store the batch ID so TaskController::show() can derive live progress
        $payload              = $task->payload ?? [];
        $payload['batch_id']  = $batch->id;
        $task->update(['payload' => $payload]);

        return $task;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function zipOutputFiles(string $taskUuid, array $outputFiles): string
    {
        $zipPath    = 'conversions/' . $taskUuid . '/result.zip';
        $zipAbsPath = Storage::path($zipPath);

        $zip    = new ZipArchive();
        $result = $zip->open($zipAbsPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // Fix 2 — open() returns true on success or an integer error code on failure
        if ($result !== true) {
            throw new \RuntimeException("Failed to create zip archive (ZipArchive error code: {$result}).");
        }

        $allowedBase = Storage::path('conversions/' . $taskUuid);

        foreach ($outputFiles as $filePath) {
            $absPath     = Storage::path($filePath);
            $resolvedDir = realpath(dirname($absPath)) ?: dirname($absPath);

            // Fix 1 — reject any path that escapes the conversions directory
            if (! str_starts_with($resolvedDir, $allowedBase)) {
                $zip->close();
                throw new \RuntimeException("Output file path escapes conversions directory: {$filePath}");
            }

            $zip->addFile($absPath, basename($filePath));
        }

        $zip->close();

        return $zipPath;
    }
}

