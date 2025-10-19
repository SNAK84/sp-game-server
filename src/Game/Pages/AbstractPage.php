<?php

namespace SPGame\Game\Pages;


use SPGame\Core\Logger;

abstract class AbstractPage implements PageInterface
{
    protected Logger $logger;
    public string $hangarMode = "Ships";

    public function __construct()
    {
        $this->logger = Logger::getInstance();
    }

}
