<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Models\Task;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateInvoiceJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 120;

    private const MAX_ROWS = 500;

    public function __construct(public readonly Task $task) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        // Guard: task deleted between dispatch and execution
        $task = Task::with('user')->find($this->task->id);

        if (! $task) {
            return;
        }

        $task->refresh();

        // Guard: duplicate dispatch or manual retry
        if ($task->status !== TaskStatus::Pending) {
            return;
        }

        $task->update([
            'status'   => TaskStatus::Processing,
            'progress' => 0,
        ]);

        $csvPath = $task->payload['file'];

        try {
            $stream = Storage::disk('local')->readStream($csvPath);


            if ($stream === null) {
                throw new \RuntimeException('Could not open stored CSV: ' . $csvPath);
            }

            // Skip header row
            fgetcsv($stream);

            $lineItems = [];
            $rowNumber = 0;

            while (($row = fgetcsv($stream)) !== false) {
                $rowNumber++;

                if ($rowNumber > self::MAX_ROWS) {
                    throw new \InvalidArgumentException(
                        'Invoice exceeds maximum of ' . self::MAX_ROWS . ' line items.'
                    );
                }

                [$description, $quantity, $unitPrice] = array_pad($row, 3, '');

                $description = trim($description);
                $quantity    = trim($quantity);
                $unitPrice   = trim($unitPrice);

                if ($description === '') {
                    throw new \InvalidArgumentException(
                        "Row {$rowNumber}: description must not be empty."
                    );
                }

                if (! ctype_digit($quantity) || (int) $quantity <= 0) {
                    throw new \InvalidArgumentException(
                        "Row {$rowNumber}: quantity must be a positive integer."
                    );
                }

                if (! is_numeric($unitPrice) || (float) $unitPrice <= 0) {
                    throw new \InvalidArgumentException(
                        "Row {$rowNumber}: unit_price must be a positive number."
                    );
                }

                $lineTotal   = round((int) $quantity * (float) $unitPrice, 2);
                $lineItems[] = [
                    'description' => $description,
                    'quantity'    => (int) $quantity,
                    'unit_price'  => (float) $unitPrice,
                    'line_total'  => $lineTotal,
                ];
            }

            fclose($stream);

            $grandTotal = array_sum(array_column($lineItems, 'line_total'));

            $task->update(['progress' => 50]);

            $html = view('invoices.template', [
                'task'       => $task,
                'user'       => $task->user,
                'lineItems'  => $lineItems,
                'grandTotal' => $grandTotal,
                'invoiceNumber' => strtoupper(substr($task->uuid, 0, 8)),
                'date'       => now()->format('d M Y'),
            ])->render();

            $pdf        = Pdf::loadHTML($html);
            $pdfContent = $pdf->output();

            $resultPath = 'invoices/' . $task->uuid . '/invoice.pdf';
            Storage::disk('local')->put($resultPath, $pdfContent);

            $task->update([
                'status'      => TaskStatus::Completed,
                'progress'    => 100,
                'result_path' => $resultPath,
            ]);
        } catch (\InvalidArgumentException $e) {
            // Expected business validation failure — mark Failed, do not retry
            $task->update([
                'status'        => TaskStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
        } finally {
            Storage::disk('local')->delete($csvPath);
        }
    }

    public function failed(Throwable $e): void
    {
        $task = Task::find($this->task->id);

        if (! $task) {
            return;
        }

        $task->update([
            'status'        => TaskStatus::Failed,
            'error_message' => $e->getMessage(),
        ]);
    }
}
