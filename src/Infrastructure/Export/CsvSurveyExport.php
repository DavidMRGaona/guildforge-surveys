<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Export;

/**
 * CSV for spreadsheets: UTF-8 with BOM, so accents read right, and `;` as separator, which
 * Excel expects with Spanish regional settings.
 */
final class CsvSurveyExport
{
    /**
     * @param  resource  $output
     */
    public function write(ExportTable $table, $output): void
    {
        fwrite($output, "\u{FEFF}");
        fputcsv($output, array_map(FormulaGuard::neutralise(...), $table->headers), separator: ';', escape: '');

        foreach ($table->rows as $row) {
            fputcsv($output, array_map(FormulaGuard::neutralise(...), $row), separator: ';', escape: '');
        }
    }
}
