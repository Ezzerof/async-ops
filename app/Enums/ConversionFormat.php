<?php

namespace App\Enums;

enum ConversionFormat: string
{
    case Json  = 'json';
    case Csv   = 'csv';
    case Xml   = 'xml';
    case Pdf   = 'pdf';
    case Docx  = 'docx';
    case Xlsx  = 'xlsx';
    case Yaml  = 'yaml';
    case Txt   = 'txt';

    /**
     * Returns true when the conversion between the given source extension
     * and this target format is a real (non-stub) implementation.
     */
    public function isRealConversion(string $sourceExt): bool
    {
        $source = strtolower($sourceExt);
        if ($source === 'yml') {
            $source = 'yaml';
        }

        return match ($this) {
            self::Json => in_array($source, ['csv', 'xml'], true),
            self::Csv  => $source === 'json',
            default    => false,
        };
    }

    public function outputExtension(): string
    {
        return $this->value;
    }
}
