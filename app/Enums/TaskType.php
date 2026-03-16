<?php

namespace App\Enums;

enum TaskType: string
{
    case UserExport     = 'user_export';
    case FileConversion = 'file_conversion';
}
