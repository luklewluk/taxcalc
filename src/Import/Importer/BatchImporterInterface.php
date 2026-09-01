<?php

declare(strict_types=1);

namespace App\Import\Importer;

use App\Import\CsvSource;
use App\Import\ImportResult;

/**
 * An importer whose records can span more than one uploaded file, and which
 * therefore has to see the whole batch before it decides what a record is.
 *
 * The DEGIRO account statement is the case this exists for: a dividend and the
 * tax withheld on it are two separate rows, and someone exporting month by
 * month - or re-exporting a period that overlaps - easily puts them in different
 * files. Aggregating per file would report the withholding as zero and overstate
 * the tax due.
 *
 * Every file is still parsed on its own, so each may have its own header,
 * language and number notation; only the *records* are assembled across the
 * batch.
 */
interface BatchImporterInterface extends ImporterInterface
{
    /**
     * @param list<CsvSource> $sources every uploaded file of this format, in
     *                                upload order
     */
    public function importMany(array $sources): ImportResult;
}
