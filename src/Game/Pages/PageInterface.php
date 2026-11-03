<?php

namespace SPGame\Game\Pages;


use SPGame\Game\Services\AccountData;

interface PageInterface
{
    public function render(AccountData &$AccountData): array;
}
