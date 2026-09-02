<?php

declare(strict_types=1);

namespace App\Web;

/** Whether a workbench problem invalidates the tax result or only needs review. */
enum DiagnosticLevel: string
{
    case Blocking = 'blocking';
    case Review = 'review';
}
