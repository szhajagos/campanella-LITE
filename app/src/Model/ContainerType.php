<?php

declare(strict_types=1);

namespace Campanella\Lite\Model;

/**
 * The Bootstrap container of a region: fixed width or full width.
 */
enum ContainerType: string
{
    case Fixed = 'fixed';
    case Fluid = 'fluid';
}
