<?php

namespace App\Services;

use App\Enums\ConversionFormat;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\AnalyseDataJob;
use App\Jobs\ConvertFileJob;
use App\Jobs\GenerateReportJob;
use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class TaskService
{
    private const MIME_MAP = [
        'csv'  => 'text/csv',
        'json' => 'application/json',
        'xml'  => 'application/xml',
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'yaml' => 'application/yaml',
        'txt'  => 'text/plain',
        'zip'  => 'application/zip',
    ];

    /**
     * Resolve the download filename and Content-Type for a completed task.
     * Report tasks always return CSV. Conversion tasks derive both from the
     * result_path extension, falling back to application/octet-stream.
     *
     * @return array{filename: string, content_type: string}
     */
    public function resolveDownloadMeta(Task $task): array
    {
        if ($task->type === TaskType::UserExport->value) {
            return [
                'filename'     => 'report-' . $task->uuid . '.csv',
                'content_type' => 'text/csv',
            ];
        }

        if ($task->type === TaskType::DataAnalysis->value) {
            return [
                'filename'     => 'analysis-' . $task->uuid . '.json',
                'content_type' => 'application/json',
            ];
        }

        $ext = strtolower(pathinfo($task->result_path, PATHINFO_EXTENSION));

        return [
            'filename'     => 'conversion-' . $task->uuid . '.' . $ext,
            'content_type' => self::MIME_MAP[$ext] ?? 'application/octet-stream',
        ];
    }


    /**
     * Return a cloned Task with live batch progress injected when the underlying
     * batch is still in-flight. The original route-bound instance is never mutated.
     * Once the batch finishes, the then/catch callbacks own the final state in the
     * DB, so this method returns the original task unchanged.
     */
    public function withLiveProgress(Task $task): Task
    {
        $batchId = $task->payload['batch_id'] ?? null;

        if (! $batchId) {
            return $task;
        }

        $batch = Bus::findBatch($batchId);

        if (! $batch || $batch->finished()) {
            return $task;
        }

        $snapshot           = clone $task;
        $snapshot->status   = TaskStatus::Processing;
        $snapshot->progress = $batch->totalJobs > 0
            ? (int) round(($batch->processedJobs() / $batch->totalJobs) * 100)
            : 0;

        return $snapshot;
    }

    public function createTask(User $user, string $type, ?array $payload = null): Task
    {
        return Task::create([
            'user_id'  => $user->id,
            'type'     => $type,
            'status'   => TaskStatus::Pending,
            'progress' => 0,
            'payload'  => $payload,
        ]);
    }

    /**
     * Create a task, store uploaded files under the task UUID directory, build
     * ConvertFileJob instances, and dispatch the batch. If any step fails the
     * already-stored files and the task record are cleaned up before re-throwing,
     * so no orphaned state is left behind.
     *
     * @param  UploadedFile[]  $files
     */
    public function createConversionTask(User $user, array $files, ConversionFormat $targetFormat): Task
    {
        $task = $this->createTask(
            user:    $user,
            type:    TaskType::FileConversion->value,
            payload: ['target_format' => $targetFormat->value, 'files' => [], 'output_files' => []],
        );

        try {
            $uploadPaths = $this->storeUploadedFiles($task, $files);

            $task->update(['payload' => array_merge($task->payload, ['files' => $uploadPaths])]);

            $jobs = array_map(
                fn (string $path) => new ConvertFileJob($task, $path, $targetFormat),
                $uploadPaths,
            );

            return $this->dispatchBatchForTask($task, $jobs);
        } catch (\Throwable $e) {
            Storage::deleteDirectory('uploads/' . $task->uuid);
            $task->delete();
            throw $e;
        }
    }

    /**
     * Create a task, store the uploaded CSV under the task UUID directory, and
     * dispatch AnalyseDataJob. If storing or dispatching fails the upload directory
     * and the task record are cleaned up before re-throwing.
     */
    public function createAnalysisTask(User $user, UploadedFile $file): Task
    {
        $task = $this->createTask(
            user: $user,
            type: TaskType::DataAnalysis->value,
        );

        try {
            $path = $file->store('uploads/' . $task->uuid, 'local');

            if ($path === false) {
                throw new \RuntimeException('Failed to store uploaded file: ' . $file->getClientOriginalName());
            }

            $task->update(['payload' => ['file' => $path]]);

            AnalyseDataJob::dispatch($task);

            return $task;
        } catch (\Throwable $e) {
            Storage::deleteDirectory('uploads/' . $task->uuid);
            $task->delete();
            throw $e;
        }
    }

    /**
     * Store uploaded files under uploads/{task_uuid}/ and return their storage paths.
     * Throws a RuntimeException if any individual file fails to store.
     *
     * @param  UploadedFile[]  $files
     */
    private function storeUploadedFiles(Task $task, array $files): array
    {
        $paths = [];

        foreach ($files as $file) {
            $path = $file->store('uploads/' . $task->uuid, 'local');

            if ($path === false) {
                throw new \RuntimeException('Failed to store uploaded file: ' . $file->getClientOriginalName());
            }

            $paths[] = $path;
        }

        return $paths;
    }

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

        return $this->dispatchBatchForTask($task, $jobs);
    }

    /**
     * Dispatch a Bus::batch() of jobs for an already-created Task, storing the
     * batch ID back onto the task payload. Use this when the Task must exist
     * before the jobs are built (e.g. file uploads keyed on task UUID).
     *
     * @param  \Illuminate\Contracts\Queue\ShouldQueue[]  $jobs
     */
    public function dispatchBatchForTask(Task $task, array $jobs): Task
    {
        $batch = Bus::batch($jobs)
            ->then(function (Batch $batch) use ($task): void {
                if (! Task::find($task->id)) {
                    return;
                }

                $task->refresh();

                // Filter out any null, non-string, or missing entries written by cancelled jobs
                $outputFiles = array_filter(
                    $task->payload['output_files'] ?? [],
                    fn ($path) => is_string($path) && Storage::exists($path)
                );

                // Empty output is a failure, not a completed task
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
        $payload             = $task->payload ?? [];
        $payload['batch_id'] = $batch->id;
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

