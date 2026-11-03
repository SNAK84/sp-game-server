<?php

declare(strict_types=1);

namespace SPGame\Game\Space;

enum ObjectType: int
{
    case PLANET = 1;
    case DEBRIS = 2;
    case MOON   = 3;
    case GAS_GIANT = 4;
    case ASTEROID_SECTOR = 5;
}
