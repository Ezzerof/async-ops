<?php

namespace App\Policies;

use App\Models\CsvImport;
use App\Models\User;

class CsvImportPolicy
{
    public function view(User $user, CsvImport $import): bool
    {
        return $user->id === $import->user_id;
    }

    public function delete(User $user, CsvImport $import): bool
    {
        return $user->id === $import->user_id;
    }
}
