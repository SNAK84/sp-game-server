<?php

namespace SPGame\Game\Pages;

interface PageInterface
{
    public function render(array &$AccountData): array;
}
