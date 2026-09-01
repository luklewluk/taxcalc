<?php

declare(strict_types=1);

namespace App\Web\Upload;

/**
 * A file that was refused, described in terms the user can act on.
 *
 * Only the client-supplied basename is echoed back - never a server path.
 */
final readonly class UploadRejection
{
    public function __construct(
        public string $filename,
        public string $reason,
    ) {
    }

    public function describe(): string
    {
        return sprintf('%s: %s', $this->filename, $this->reason);
    }
}
