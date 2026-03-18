<?php

namespace App\Services;

use App\Models\CsvImport;
use App\Models\Task;

class CsvImportService
{
    /**
     * Validate the CSV structure and count data rows.
     * Returns ['headers' => string[], 'row_count' => int].
     * Throws \RuntimeException with a user-readable message on any structural issue.
     */
    public function validateAndCount(string $absolutePath): array
    {
        if (! file_exists($absolutePath)) {
            throw new \RuntimeException('Uploaded file not found — it may have been deleted before processing.');
        }

        $handle = fopen($absolutePath, 'r');

        if ($handle === false) {
            throw new \RuntimeException('Could not open CSV file for reading.');
        }

        try {
            $headers = fgetcsv($handle);

            if ($headers === false || $headers === null || count($headers) === 0) {
                throw new \RuntimeException('CSV file is empty or has no header row.');
            }

            $headers = array_map('trim', $headers);

            // Strip UTF-8 BOM (\xEF\xBB\xBF) from the first header if present
            if (isset($headers[0]) && str_starts_with($headers[0], "\xEF\xBB\xBF")) {
                $headers[0] = substr($headers[0], 3);
            }

            foreach ($headers as $index => $header) {
                if ($header === '') {
                    throw new \RuntimeException(
                        'CSV file contains an empty column name at position ' . ($index + 1) . '.'
                    );
                }
            }

            if (count($headers) !== count(array_unique($headers))) {
                throw new \RuntimeException('CSV file contains duplicate column names.');
            }

            $headerCount = count($headers);
            $rowCount    = 0;

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) !== $headerCount) {
                    throw new \RuntimeException(
                        'Row ' . ($rowCount + 2) . ' has ' . count($row) . " columns, expected {$headerCount}."
                    );
                }
                $rowCount++;
            }
        } finally {
            fclose($handle);
        }

        return ['headers' => $headers, 'row_count' => $rowCount];
    }

    /**
     * Persist a CsvImport record for a successfully processed file.
     */
    public function createRecord(
        Task   $task,
        string $importPath,
        string $originalFilename,
        array  $headers,
        int    $rowCount,
    ): CsvImport {
        return CsvImport::create([
            'task_id'           => $task->id,
            'user_id'           => $task->user_id,
            'original_filename' => $originalFilename,
            'file_path'         => $importPath,
            'headers'           => $headers,
            'row_count'         => $rowCount,
        ]);
    }
}
