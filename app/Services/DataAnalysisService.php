<?php

namespace App\Services;

class DataAnalysisService
{
    /**
     * Parse a CSV file and return column-level statistics.
     *
     * @return array{row_count: int, columns: array<string, array>}
     * @throws \RuntimeException if the file cannot be opened
     */
    public function analyse(string $csvPath): array
    {
        $handle = fopen($csvPath, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV file for analysis: {$csvPath}");
        }

        try {
            return $this->process($handle);
        } finally {
            fclose($handle);
        }
    }

    // -------------------------------------------------------------------------

    /** @param resource $handle */
    private function process($handle): array
    {
        $headers = fgetcsv($handle);

        if ($headers === false) {
            return ['row_count' => 0, 'columns' => []];
        }

        // Strip BOM and surrounding whitespace from every header cell
        $headers    = array_map('trim', $headers);
        $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");

        $accumulators = $this->initialise($headers);
        $rowCount     = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowCount++;
            $this->accumulate($headers, $row, $accumulators);
        }

        return [
            'row_count' => $rowCount,
            'columns'   => $this->finalise($headers, $accumulators),
        ];
    }

    private function initialise(array $headers): array
    {
        $accumulators = [];

        foreach ($headers as $header) {
            $accumulators[$header] = [
                'null_count'    => 0,
                'all_numeric'   => true,
                'numeric_count' => 0,
                'min'           => null,
                'max'           => null,
                'sum'           => 0.0,
                'value_counts'  => [],
            ];
        }

        return $accumulators;
    }

    private function accumulate(array $headers, array $row, array &$accumulators): void
    {
        foreach ($headers as $i => $header) {
            $value = trim($row[$i] ?? '');
            $acc   = &$accumulators[$header];

            if ($value === '') {
                $acc['null_count']++;
                continue;
            }

            // Track every non-empty value for string stats
            $acc['value_counts'][$value] = ($acc['value_counts'][$value] ?? 0) + 1;

            // Only accumulate numeric stats while the column still qualifies
            if (! $acc['all_numeric']) {
                continue;
            }

            if (! is_numeric($value)) {
                $acc['all_numeric'] = false;
                continue;
            }

            $v = (float) $value;
            $acc['numeric_count']++;
            $acc['sum'] += $v;

            if ($acc['min'] === null || $v < $acc['min']) {
                $acc['min'] = $v;
            }
            if ($acc['max'] === null || $v > $acc['max']) {
                $acc['max'] = $v;
            }
        }

        unset($acc);
    }

    private function finalise(array $headers, array $accumulators): array
    {
        $columns = [];

        foreach ($headers as $header) {
            $a = $accumulators[$header];

            // A column is numeric only when every non-empty value was numeric.
            // All-empty columns default to string (safe, no division-by-zero risk).
            $isNumeric = $a['all_numeric'] && $a['numeric_count'] > 0;

            $columns[$header] = $isNumeric
                ? [
                    'type'       => 'numeric',
                    'min'        => $a['min'],
                    'max'        => $a['max'],
                    'sum'        => $a['sum'],
                    'average'    => round($a['sum'] / $a['numeric_count'], 4),
                    'null_count' => $a['null_count'],
                ]
                : [
                    'type'         => 'string',
                    'null_count'   => $a['null_count'],
                    'unique_count' => count($a['value_counts']),
                    'value_counts' => $a['value_counts'],
                ];
        }

        return $columns;
    }
}
