<?php

declare(strict_types=1);

namespace App\Web\Upload;

use App\Import\CsvSource;

final readonly class UploadReadResult
{
    /**
     * @param list<CsvSource>       $sources
     * @param list<UploadRejection> $rejections
     */
    public function __construct(
        public array $sources,
        public array $rejections,
    ) {
    }

    /**
     * @return list<string>
     */
    public function rejectionMessages(): array
    {
        return array_map(static fn (UploadRejection $r): string => $r->describe(), $this->rejections);
    }
}
