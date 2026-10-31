<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Export;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel through openspout, which the core already installs with Filament's exports.
 */
final class XlsxSurveyExport
{
    public function writeToFile(ExportTable $table, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        // openspout writes a text starting with "=" as a formula: guard values as the CSV does
        $writer->addRow(Row::fromValues(array_map(FormulaGuard::neutralise(...), $table->headers)));

        foreach ($table->rows as $row) {
            $writer->addRow(Row::fromValues(array_map(FormulaGuard::neutralise(...), $row)));
        }

        $writer->close();
    }
}
