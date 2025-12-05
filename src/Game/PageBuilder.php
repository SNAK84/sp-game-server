<?php

namespace SPGame\Game;

use SPGame\Core\Connect;
use SPGame\Core\Logger;
use SPGame\Core\Message;

use SPGame\Game\Repositories\Accounts;
use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\Users;

use SPGame\Game\Repositories\Builds;
use SPGame\Game\Repositories\Techs;
use SPGame\Game\Repositories\Resources;

use SPGame\Game\Pages\OverviewPage;
use SPGame\Game\Pages\BuildingsPage;
use SPGame\Game\Pages\FleetsPage;
use SPGame\Game\Pages\ResearchPage;
use SPGame\Game\Pages\HangarPage;

use SPGame\Game\Pages\GalaxyPage;

use SPGame\Game\Pages\MessagesPage;

use SPGame\Game\Services\PageActionService;
use SPGame\Game\Services\Notification;
use SPGame\Game\Services\AccountData;


class PageBuilder
{
    protected Logger $logger;

    private Message $Msg;
    private int $fd;
    private int $aid;

    public function __construct(Message $Msg, int $fd)
    {
        $this->logger = Logger::getInstance();

        $this->fd = $fd;
        $this->Msg = $Msg;
        $this->aid = Connect::getAccount($fd) ?? 0;
    }

    /**
     * Сборка ответа для клиента на запрошенную страницу
     */
    public function build(Message $response): Message
    {
        Logger::getInstance()->info("this->aid: $this->aid");
        $AccountData = new AccountData($this->aid);

        // обработка действий перед построением
        if (PageActionService::handle($this->Msg, $AccountData, $this->aid)) {
            $response->setMode("none");
            return $response;
        }
        $result = [];
        $page = null;
        switch ($this->Msg->getMode()) {
            case 'buildings':
                $page = new BuildingsPage($this->Msg);
                break;
            case 'researchs':
                $page = new ResearchPage($this->Msg);
                break;
            case 'shipyard':
                $page = new HangarPage($this->Msg);
                $page->hangarMode = "Ships";
                break;
            case 'defense':
                $page = new HangarPage($this->Msg);
                $page->hangarMode = "Defenses";
                break;
            case 'galaxy':
                $page = new GalaxyPage($this->Msg);
                break;

            case 'fleets':
                $page = new FleetsPage($this->Msg);
                break;

            case 'messages':
                $page = new MessagesPage($this->Msg);
                break;
            case 'overview':
            default:
                $page = new OverviewPage($this->Msg);
                $this->Msg->setMode('overview');
                break;
        }

        if ($page) {
            $result = $page->render($AccountData);
            $response->setData('Page', $result);
        }

        $response->setMode($this->Msg->getMode());
        $response->setData('TopNav', $this->GetTopNav($AccountData));
        $response->setData('PlanetList', $this->GetPlanetList($AccountData));

        return $response;
    }

    public function GetTopNav(AccountData $AccountData)
    {

        $Resources = Resources::get($AccountData);

        $NewMessages = Notification::NewMessages($AccountData['User']['id']);

        return [
            'NewMessages' => $NewMessages,
            'Resources' => $Resources
        ];
    }

    public function GetPlanetList(AccountData $AccountData)
    {

        $Planets = Planets::getPlanetsList($AccountData['User']);

        return [
            'current_planet'    => $AccountData['User']['current_planet'],
            'main_planet'       => $AccountData['User']['main_planet'],
            'Planets'           => $Planets
        ];
    }
}
