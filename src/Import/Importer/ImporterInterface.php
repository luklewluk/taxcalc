<?php

declare(strict_types=1);

namespace App\Import\Importer;

use App\Import\CsvFormat;
use App\Import\CsvSource;
use App\Import\ImportResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface ImporterInterface
{
    /**
     * Hard ceiling on data rows read from a single file.
     *
     * Applied while iterating so that an oversized upload is bounded before it
     * becomes an array of domain objects, rather than after.
     */
    public const int DEFAULT_MAX_ROWS_PER_FILE = 50000;

    public function supports(CsvFormat $format): bool;

    /**
     * Never throws for bad data: everything the file gets wrong comes back as
     * an {@see \App\Import\ImportMessage} so one broken row cannot take the
     * whole upload - or the HTTP request - down.
     */
    public function import(CsvSource $source): ImportResult;
}
