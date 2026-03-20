<?php

namespace App\Services;

use App\Enums\ConversionFormat;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\AnalyseDataJob;
use App\Jobs\ConvertFileJob;
use App\Jobs\GenerateInvoiceJob;
use App\Jobs\GenerateReportJob;
use App\Jobs\ImportCsvJob;
use App\Models\CsvImport;
use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class TaskService
{
    /**
     * Resolve the download filename and Content-Type for a completed task.
     * Delegates to the TaskType enum so no changes are needed here when new
     * task types are added — each type owns its own download metadata.
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

        if ($task->type === TaskType::InvoiceGeneration->value) {
            return [
                'filename'     => 'invoice-' . $task->uuid . '.pdf',
                'content_type' => 'application/pdf',
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
     * Validate the CSV header, create a task, store the uploaded CSV under the
     * task UUID directory, and dispatch GenerateInvoiceJob. Header validation
     * runs before the task is created so a bad file never produces a DB record.
     * If storing or dispatching fails, the upload directory and task record are
     * cleaned up before re-throwing.
     */
    public function createInvoiceTask(User $user, UploadedFile $file): Task
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw new \RuntimeException('Could not open uploaded file for header validation.');
        }

        $header = fgetcsv($handle);
        fclose($handle);

        $required = ['description', 'quantity', 'unit_price'];
        $missing  = array_diff($required, array_map('strtolower', $header ?: []));

        if (! empty($missing)) {
            throw ValidationException::withMessages([
                'file' => ['CSV is missing required columns: ' . implode(', ', $missing) . '.'],
            ]);
        }

        $task = $this->createTask(
            user: $user,
            type: TaskType::InvoiceGeneration->value,
        );

        try {
            $path = $file->store('uploads/' . $task->uuid, 'local');

            if ($path === false) {
                throw new \RuntimeException('Failed to store uploaded file: ' . $file->getClientOriginalName());
            }

            $task->update(['payload' => ['file' => $path]]);

            GenerateInvoiceJob::dispatch($task);

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

    /**
     * Create a task, store the uploaded CSV under the task UUID directory, and
     * dispatch ImportCsvJob. Cleans up uploaded file and task on failure.
     */
    public function createCsvImportTask(User $user, UploadedFile $file): Task
    {
        $task = $this->createTask(
            user: $user,
            type: TaskType::CsvImport->value,
        );

        try {
            $path = $file->store('uploads/' . $task->uuid, 'local');

            if ($path === false) {
                throw new \RuntimeException('Failed to store uploaded file: ' . $file->getClientOriginalName());
            }

            $task->update(['payload' => [
                'file'              => $path,
                'original_filename' => $file->getClientOriginalName(),
            ]]);

            ImportCsvJob::dispatch($task);

            return $task;
        } catch (\Throwable $e) {
            Storage::deleteDirectory('uploads/' . $task->uuid);
            $task->delete();
            throw $e;
        }
    }

    /**
     * Create a data_analysis task pointing to an already-stored import file
     * and dispatch the existing AnalyseDataJob. Reuses the analysis pipeline
     * without requiring the user to re-upload the file.
     */
    /**
     * Return true if the import has any pending or processing analysis tasks
     * that reference its stored file. Used to block deletion while derived
     * work is still in flight.
     */
    public function hasActiveAnalysisFor(CsvImport $import): bool
    {
        return Task::where('type', TaskType::DataAnalysis->value)
            ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Processing->value])
            ->where('payload->file', $import->file_path)
            ->exists();
    }

    public function deleteImport(CsvImport $import): void
    {
        Storage::disk('local')->deleteDirectory('imports/' . $import->task->uuid);
        $import->task->delete(); // cascade removes the csv_imports row
    }

    public function createAnalysisTaskFromImport(User $user, CsvImport $import): Task
    {
        if ($import->task->status !== TaskStatus::Completed) {
            throw new \InvalidArgumentException('Cannot analyse an import that has not completed successfully.');
        }

        $task = $this->createTask(
            user:    $user,
            type:    TaskType::DataAnalysis->value,
            payload: ['file' => $import->file_path],
        );

        try {
            AnalyseDataJob::dispatch($task);

            return $task;
        } catch (\Throwable $e) {
            $task->delete();
            throw $e;
        }
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

