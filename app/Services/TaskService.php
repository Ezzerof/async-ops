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
use App\Jobs\SendBulkEmailJob;
use App\Models\CsvImport;
use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
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
        return TaskType::from($task->type)->downloadMeta($task->uuid, $task->result_path);
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
        $task = Task::create([
            'user_id'  => $user->id,
            'type'     => $type,
            'status'   => TaskStatus::Pending,
            'progress' => 0,
            'payload'  => $payload,
        ]);

        Log::info('[TaskService] Task created.', [
            'task_uuid' => $task->uuid,
            'task_type' => $type,
            'user_id'   => $user->id,
        ]);

        return $task;
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
            Log::error('[TaskService] Task setup failed — compensating: deleting files and task record.', [
                'task_uuid' => $task->uuid,
                'task_type' => TaskType::FileConversion->value,
                'exception' => $e->getMessage(),
            ]);
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

            Log::info('[TaskService] AnalyseDataJob dispatched.', [
                'task_uuid' => $task->uuid,
                'task_type' => $task->type,
            ]);

            return $task;
        } catch (\Throwable $e) {
            Log::error('[TaskService] Task setup failed — compensating: deleting files and task record.', [
                'task_uuid' => $task->uuid,
                'task_type' => TaskType::DataAnalysis->value,
                'exception' => $e->getMessage(),
            ]);
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

            Log::info('[TaskService] GenerateInvoiceJob dispatched.', [
                'task_uuid' => $task->uuid,
                'task_type' => $task->type,
            ]);

            return $task;
        } catch (\Throwable $e) {
            Log::error('[TaskService] Task setup failed — compensating: deleting files and task record.', [
                'task_uuid' => $task->uuid,
                'task_type' => TaskType::InvoiceGeneration->value,
                'exception' => $e->getMessage(),
            ]);
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

            Log::info('[TaskService] ImportCsvJob dispatched.', [
                'task_uuid' => $task->uuid,
                'task_type' => $task->type,
            ]);

            return $task;
        } catch (\Throwable $e) {
            Log::error('[TaskService] Task setup failed — compensating: deleting files and task record.', [
                'task_uuid' => $task->uuid,
                'task_type' => TaskType::CsvImport->value,
                'exception' => $e->getMessage(),
            ]);
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

        Log::info('[TaskService] Import deleted.', [
            'task_uuid' => $import->task->uuid,
            'import_id' => $import->id,
        ]);
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

            Log::info('[TaskService] AnalyseDataJob dispatched (from import).', [
                'task_uuid' => $task->uuid,
                'task_type' => $task->type,
                'import_id' => $import->id,
            ]);

            return $task;
        } catch (\Throwable $e) {
            Log::error('[TaskService] Task setup failed — compensating: deleting files and task record.', [
                'task_uuid' => $task->uuid,
                'task_type' => TaskType::DataAnalysis->value,
                'exception' => $e->getMessage(),
            ]);
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

        Log::info('[TaskService] GenerateReportJob dispatched.', [
            'task_uuid' => $task->uuid,
            'task_type' => $task->type,
        ]);

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
                    Log::warning('[FileConversion] Batch then — task deleted, skipping.', [
                        'task_uuid' => $task->uuid,
                    ]);
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
                    Log::error('[FileConversion] Batch completed with no output files.', [
                        'task_uuid' => $task->uuid,
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

                Log::info('[FileConversion] Batch completed.', [
                    'task_uuid'   => $task->uuid,
                    'result_path' => $resultPath,
                ]);
            })
            ->catch(function (Batch $batch, \Throwable $e) use ($task): void {
                if (! Task::find($task->id)) {
                    Log::warning('[FileConversion] Batch catch — task deleted, skipping.', [
                        'task_uuid' => $task->uuid,
                    ]);
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

                Log::error('[FileConversion] Batch failed.', [
                    'task_uuid' => $task->uuid,
                    'exception' => $e->getMessage(),
                ]);
            })
            ->dispatch();

        // Store the batch ID so TaskController::show() can derive live progress
        $payload             = $task->payload ?? [];
        $payload['batch_id'] = $batch->id;
        $task->update(['payload' => $payload]);

        Log::info('[TaskService] Batch dispatched.', [
            'task_uuid' => $task->uuid,
            'batch_id'  => $batch->id,
            'job_count' => count($jobs),
        ]);

        return $task;
    }

    /**
     * Sanitise the body, optionally store a PDF attachment, dispatch one
     * SendBulkEmailJob per recipient via Bus::batch(), and return the Task.
     * On any failure, uploaded files and the task record are cleaned up.
     */
    /**
     * Read the stored CSV from the completed import, extract the `email` column,
     * and delegate to createBulkEmailTask. Throws InvalidArgumentException if the
     * import is not completed, the email column is missing, or no valid addresses
     * are found.
     */
    public function createBulkEmailTaskFromImport(
        User          $user,
        CsvImport     $import,
        string        $subject,
        string        $body,
        ?UploadedFile $attachment,
    ): Task {
        if ($import->task->status !== TaskStatus::Completed) {
            throw new \InvalidArgumentException('Cannot send emails from an import that has not completed successfully.');
        }

        $handle = Storage::disk('local')->readStream($import->file_path);

        if ($handle === null) {
            throw new \InvalidArgumentException('Import file could not be read.');
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);
            throw new \InvalidArgumentException('Import CSV is empty.');
        }

        $normalised = array_map('strtolower', array_map('trim', $header));
        $emailIndex = array_search('email', $normalised, strict: true);

        if ($emailIndex === false) {
            fclose($handle);
            throw new \InvalidArgumentException('Import CSV must contain an "email" column.');
        }

        $recipients = [];

        while (($row = fgetcsv($handle)) !== false) {
            $value = isset($row[$emailIndex]) ? strtolower(trim($row[$emailIndex])) : '';
            if ($value !== '') {
                $recipients[] = $value;
            }
        }

        fclose($handle);

        $recipients = array_values(array_unique($recipients));

        if (empty($recipients)) {
            throw new \InvalidArgumentException('No email addresses found in the import CSV.');
        }

        return $this->createBulkEmailTask(
            user:       $user,
            recipients: $recipients,
            subject:    $subject,
            body:       $body,
            attachment: $attachment,
        );
    }

    public function createBulkEmailTask(
        User          $user,
        array         $recipients,
        string        $subject,
        string        $body,
        ?UploadedFile $attachment,
    ): Task {
        if (empty($recipients)) {
            throw new \InvalidArgumentException('Recipients list must not be empty.');
        }

        $sanitizer = new HtmlSanitizer(
            (new HtmlSanitizerConfig())
                ->allowSafeElements()
                ->allowAttribute('href', ['a'])
                ->allowAttribute('src', ['img'])
                ->forceHttpsUrls()
        );

        $cleanBody = $sanitizer->sanitize($body);

        $task = $this->createTask(
            user:    $user,
            type:    TaskType::BulkEmail->value,
            payload: [
                'subject'         => $subject,
                'body'            => $cleanBody,
                'recipients'      => $recipients,
                'attachment_path' => null,
            ],
        );

        try {
            if ($attachment !== null) {
                $attachmentPath = 'emails/' . $task->uuid . '/attachment_' . Str::uuid() . '.pdf';
                $stored         = $attachment->storeAs(
                    path:     'emails/' . $task->uuid,
                    name:     basename($attachmentPath),
                    options:  'local',
                );

                if ($stored === false) {
                    throw new \RuntimeException('Failed to store attachment: ' . $attachment->getClientOriginalName());
                }

                $task->update(['payload' => array_merge($task->payload, ['attachment_path' => $attachmentPath])]);
            }

            $jobs = array_map(
                fn (string $email) => new SendBulkEmailJob($task, $email),
                $recipients,
            );

            $taskId = $task->id;

            $batch = Bus::batch($jobs)
                ->allowFailures()
                ->then(function (Batch $batch) use ($taskId): void {
                    $task = Task::find($taskId);

                    if ($task === null) {
                        Log::warning('[BulkEmail] Batch then — task deleted, skipping.', [
                            'task_id' => $taskId,
                        ]);
                        return;
                    }

                    $task     = $task->refresh();
                    $payload  = $task->payload ?? [];
                    $recipients      = $payload['recipients'] ?? [];
                    $deliveryStatus  = $payload['delivery_status'] ?? [];

                    // Build CSV rows from the original recipients list so every
                    // recipient appears even if no job wrote back a status.
                    $rows = [];
                    foreach ($recipients as $email) {
                        $rows[] = [$email, $deliveryStatus[$email] ?? 'unknown'];
                    }

                    // All-failed (or all-unknown) means no emails were delivered.
                    $anyDelivered = collect($rows)->contains(fn ($r) => $r[1] === 'sent');

                    if (! $anyDelivered) {
                        $task->update([
                            'status'        => TaskStatus::Failed,
                            'error_message' => 'No emails were delivered successfully.',
                        ]);
                        Log::error('[BulkEmail] Batch completed — no emails were delivered.', [
                            'task_uuid' => $task->uuid,
                        ]);
                        return;
                    }

                    // Write delivery report CSV.
                    $reportPath = 'emails/' . $task->uuid . '/report.csv';
                    $csv        = "recipient,status\n";
                    foreach ($rows as [$email, $status]) {
                        $csv .= $email . ',' . $status . "\n";
                    }
                    Storage::disk('local')->put($reportPath, $csv);

                    // Delete attachment at the exact stored path only — never a wildcard.
                    $attachmentPath = $payload['attachment_path'] ?? null;
                    if ($attachmentPath !== null) {
                        $expectedPrefix = 'emails/' . $task->uuid . '/';
                        if (str_starts_with($attachmentPath, $expectedPrefix)) {
                            Storage::disk('local')->delete($attachmentPath);
                        }
                    }

                    $task->update([
                        'status'      => TaskStatus::Completed,
                        'progress'    => 100,
                        'result_path' => $reportPath,
                    ]);

                    Log::info('[BulkEmail] Batch completed — delivery report generated.', [
                        'task_uuid'   => $task->uuid,
                        'result_path' => $reportPath,
                    ]);
                })
                ->catch(function (Batch $batch, \Throwable $e) use ($taskId): void {
                    $task = Task::find($taskId);

                    if ($task === null) {
                        Log::warning('[BulkEmail] Batch catch — task deleted, skipping.', [
                            'task_id' => $taskId,
                        ]);
                        return;
                    }

                    $task = $task->refresh();

                    if ($task->status === TaskStatus::Completed || $task->status === TaskStatus::Failed) {
                        Log::warning('[BulkEmail] Batch catch — status already settled, skipping.', [
                            'task_uuid' => $task->uuid,
                            'status'    => $task->status->value,
                        ]);
                        return;
                    }

                    $task->update([
                        'status'        => TaskStatus::Failed,
                        'error_message' => 'Batch processing failed unexpectedly.',
                    ]);

                    Log::error('[BulkEmail] Batch failed unexpectedly.', [
                        'task_uuid' => $task->uuid,
                        'exception' => $e->getMessage(),
                    ]);
                })
                ->dispatch();

            $payload             = $task->payload ?? [];
            $payload['batch_id'] = $batch->id;
            $task->update(['payload' => $payload]);

            Log::info('[TaskService] Bulk email batch dispatched.', [
                'task_uuid'       => $task->uuid,
                'batch_id'        => $batch->id,
                'recipient_count' => count($recipients),
            ]);

            return $task;
        } catch (\Throwable $e) {
            Log::error('[TaskService] Task setup failed — compensating: deleting files and task record.', [
                'task_uuid' => $task->uuid,
                'task_type' => TaskType::BulkEmail->value,
                'exception' => $e->getMessage(),
            ]);
            Storage::disk('local')->deleteDirectory('emails/' . $task->uuid);
            $task->delete();
            throw $e;
        }
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

