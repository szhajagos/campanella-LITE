<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Result;

enum LineStatus: string
{
    case Ok = 'ok';
    case Error = 'error';

    /** Not run, because an earlier line of the batch failed. */
    case Skipped = 'skipped';
}
