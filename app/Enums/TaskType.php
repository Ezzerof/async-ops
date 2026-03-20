<?php

namespace App\Enums;

enum TaskType: string
{
    case UserExport        = 'user_export';
    case FileConversion    = 'file_conversion';
    case DataAnalysis      = 'data_analysis';
    case InvoiceGeneration = 'invoice_generation';
}
