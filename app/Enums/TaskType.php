<?php

namespace App\Enums;

enum TaskType: string
{
    case UserExport     = 'user_export';
    case FileConversion = 'file_conversion';
    case DataAnalysis   = 'data_analysis';
    case CsvImport      = 'csv_import';

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
     * Return the download filename and Content-Type for a completed task of this type.
     * Throws \LogicException for task types that produce no downloadable file.
     *
     * @return array{filename: string, content_type: string}
     */
    public function downloadMeta(string $uuid, ?string $resultPath): array
    {
        return match ($this) {
            self::UserExport     => [
                'filename'     => 'report-' . $uuid . '.csv',
                'content_type' => 'text/csv',
            ],
            self::DataAnalysis   => [
                'filename'     => 'analysis-' . $uuid . '.json',
                'content_type' => 'application/json',
            ],
            self::FileConversion => $this->conversionMeta($uuid, $resultPath),
            self::CsvImport      => throw new \LogicException(
                'CsvImport tasks do not produce a downloadable file.'
            ),
        };
    }

    private function conversionMeta(string $uuid, ?string $resultPath): array
    {
        $ext = strtolower(pathinfo($resultPath ?? '', PATHINFO_EXTENSION));

        return [
            'filename'     => 'conversion-' . $uuid . '.' . $ext,
            'content_type' => self::MIME_MAP[$ext] ?? 'application/octet-stream',
        ];
    }
}
