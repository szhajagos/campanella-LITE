<?php

declare(strict_types=1);

namespace Campanella\Lite\Model;

enum PageStatus: string
{
    /** Visible only in the admin preview; never generated for visitors. */
    case Draft = 'draft';

    /** Generated and served to visitors. */
    case Published = 'published';
}
