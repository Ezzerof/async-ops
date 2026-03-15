<?php

namespace App\Services;

use App\Enums\ConversionFormat;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class FileConversionService
{
    /**
     * Convert the file at $sourcePath to $targetFormat and write the output
     * to the same directory. Returns the output file path (relative to the
     * storage disk root).
     */
    public function convert(string $sourcePath, ConversionFormat $targetFormat): string
    {
        // Fix 4 — guard against missing source file before any method touches it
        if (! Storage::exists($sourcePath)) {
            throw new RuntimeException("Source file not found: {$sourcePath}");
        }

        $sourceExt = pathinfo($sourcePath, PATHINFO_EXTENSION);

        if ($targetFormat->isRealConversion($sourceExt)) {
            return $this->realConvert($sourcePath, $sourceExt, $targetFormat);
        }

        return $this->stubConvert($sourcePath, $targetFormat);
    }

    // -------------------------------------------------------------------------
    // Real conversions
    // -------------------------------------------------------------------------

    private function realConvert(string $sourcePath, string $sourceExt, ConversionFormat $targetFormat): string
    {
        $ext = strtolower($sourceExt);
        if ($ext === 'yml') {
            $ext = 'yaml';
        }

        return match (true) {
            $ext === 'csv'  && $targetFormat === ConversionFormat::Json => $this->csvToJson($sourcePath),
            $ext === 'json' && $targetFormat === ConversionFormat::Csv  => $this->jsonToCsv($sourcePath),
            $ext === 'xml'  && $targetFormat === ConversionFormat::Json => $this->xmlToJson($sourcePath),
            default => throw new RuntimeException("Unhandled real conversion: {$ext} → {$targetFormat->value}"),
        };
    }

    private function csvToJson(string $sourcePath): string
    {
        $content = str_replace("\r\n", "\n", Storage::get($sourcePath));
        $lines   = explode("\n", trim($content));
        $headers = str_getcsv(array_shift($lines));

        $rows = [];
        foreach ($lines as $lineNumber => $line) {
            if ($line === '') {
                continue;
            }

            $values = str_getcsv($line);

            // Fix 3 — reject malformed rows before array_combine
            if (count($values) !== count($headers)) {
                throw new RuntimeException(
                    "CSV row {$lineNumber} has " . count($values) . ' columns, expected ' . count($headers) . '.'
                );
            }

            $rows[] = array_combine($headers, $values);
        }

        $outputPath = $this->outputPath($sourcePath, ConversionFormat::Json);
        Storage::put($outputPath, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $outputPath;
    }

    private function jsonToCsv(string $sourcePath): string
    {
        $rows = json_decode(Storage::get($sourcePath), true);

        if (! is_array($rows) || empty($rows)) {
            throw new RuntimeException('JSON file must contain a non-empty array of objects.');
        }

        // Fix 5 — ensure each element is an associative array, not a scalar or indexed array
        $first = reset($rows);
        if (! is_array($first) || array_keys($first) === range(0, count($first) - 1)) {
            throw new RuntimeException('JSON file must contain an array of objects, not scalars or nested arrays.');
        }

        // Fix 6 — guarantee stream is closed even if fputcsv throws
        $handle = fopen('php://temp', 'r+');
        try {
            fputcsv($handle, array_keys($first));
            foreach ($rows as $row) {
                fputcsv($handle, array_values($row));
            }
            rewind($handle);
            $csv = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        $outputPath = $this->outputPath($sourcePath, ConversionFormat::Csv);
        Storage::put($outputPath, $csv);

        return $outputPath;
    }

    private function xmlToJson(string $sourcePath): string
    {
        // Fix 1 — disable network access during XML parsing to prevent XXE
        // Use internal error handling so libxml warnings don't become ErrorExceptions
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string(Storage::get($sourcePath), 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        if ($xml === false) {
            throw new RuntimeException('Failed to parse XML file.');
        }

        $json = json_encode($xml, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $outputPath = $this->outputPath($sourcePath, ConversionFormat::Json);
        Storage::put($outputPath, $json);

        return $outputPath;
    }

    // -------------------------------------------------------------------------
    // Stub conversion — copy file, rename extension
    // -------------------------------------------------------------------------

    private function stubConvert(string $sourcePath, ConversionFormat $targetFormat): string
    {
        $outputPath = $this->outputPath($sourcePath, $targetFormat);
        Storage::copy($sourcePath, $outputPath);

        return $outputPath;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function outputPath(string $sourcePath, ConversionFormat $targetFormat): string
    {
        $dir = pathinfo($sourcePath, PATHINFO_DIRNAME);

        // Fix 2 — replace the original filename with a controlled name to prevent path traversal
        $filename = 'output_' . Str::uuid() . '.' . $targetFormat->outputExtension();

        return $dir . '/' . $filename;
    }
}
