<?php

declare(strict_types=1);

namespace SPGame\Game\Space;

enum CoordinateType: int
{
    case OBJECT	= 1;
    case POINT	= 2;
    case FLEET	= 3;
}