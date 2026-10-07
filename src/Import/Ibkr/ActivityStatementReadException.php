<?php

declare(strict_types=1);

namespace App\Import\Ibkr;

/**
 * The statement as a whole cannot be used. Raised by the reader, turned into an
 * {@see \App\Import\ImportMessage} by the importer - it never leaves the Import
 * module.
 */
final class ActivityStatementReadException extends \RuntimeException
{
}
