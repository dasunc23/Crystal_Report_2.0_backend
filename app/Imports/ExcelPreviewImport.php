<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ExcelPreviewImport implements ToArray, WithHeadingRow
{
    /**
     * This method receives the Excel rows.
     *
     * The toArray() method in the controller
     * will return these rows to us.
     */
    public function array(array $array): void
    {
        // No database operation is needed for preview.
    }
}
