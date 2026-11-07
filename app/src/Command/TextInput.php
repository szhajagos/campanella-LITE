<?php

declare(strict_types=1);

namespace Campanella\Lite\Command;

/**
 * Whether a command takes multi-line text between "<<<" and ">>>".
 */
enum TextInput
{
    case None;
    case Optional;
    case Required;
}
