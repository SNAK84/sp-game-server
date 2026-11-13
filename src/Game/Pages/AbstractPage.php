<?php

namespace SPGame\Game\Pages;


use SPGame\Core\Logger;
use SPGame\Core\Message;

abstract class AbstractPage implements PageInterface
{
    protected Logger $logger;
    protected Message $Msg;

    
    public string $hangarMode = "Ships";

    public function __construct(Message $Msg)
    {
        $this->Msg = $Msg;
        $this->logger = Logger::getInstance();
    }

}
