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
use SPGame\Game\Pages\ResearchPage;
use SPGame\Game\Pages\ShipyardPage;
use SPGame\Game\Pages\DefensePage;

use SPGame\Game\Services\PageActionService;


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
        $AccountData = [
            'Account'   => Accounts::findById($this->aid),
            'User'      => Users::findByAccount($this->aid),
        ];

        $AccountData['Planet']      = Planets::findByUserId($AccountData['User']['id']);
        $AccountData['Builds']      = Builds::findById($AccountData['Planet']['id']);
        $AccountData['Techs']       = Techs::findById($AccountData['User']['id']);
        $AccountData['Resources']   = Resources::get($AccountData);

        // обработка действий перед построением
        PageActionService::handle($this->Msg, $AccountData, $this->aid);

        $result = [];
        switch ($this->Msg->getMode()) {
            case 'buildings':
                $page = new BuildingsPage();
                break;
            case 'researchs':
                $page = new ResearchPage();
                break;
            case 'shipyard':
                $page = new ShipyardPage();
                break;
            case 'defense':
                $page = new DefensePage();
                break;
            case 'overview':
            default:
                $page = new OverviewPage();
                $this->Msg->setMode('overview');
                break;
        }

        $result = $page->render($AccountData);

        $response->setData('Page', $result);

        $response->setMode($this->Msg->getMode());
        $response->setData('TopNav', $this->GetTopNav($AccountData));
        $response->setData('PlanetList', $this->GetPlanetList($AccountData));

        return $response;
    }

    public function GetTopNav(array $AccountData)
    {

        $Resources = Resources::get($AccountData);

        return [
            'Resources' => $Resources
        ];
    }

    public function GetPlanetList(array $AccountData)
    {

        $Planets = Planets::getPlanetsList($AccountData['User']);

        return [
            'current_planet'    => $AccountData['User']['current_planet'],
            'main_planet'       => $AccountData['User']['main_planet'],
            'Planets'           => $Planets
        ];
    }
}
