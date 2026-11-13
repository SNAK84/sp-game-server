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
use SPGame\Game\Services\CelestialNameGenerator;


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
        $ready = false;
        try {
            return \SPGame\Game\Services\FleetMiningCheckpointService::world(function () use ($response, &$ready): Message {
                $ready = true;
                return $this->buildPage($response);
            });
        } catch (\Throwable $error) {
            if ($ready) throw $error; // Не менять прежнюю обработку ошибок самой страницы.
            $this->logger->error('Не удалось допустить запрос к игровому состоянию', ['message' => $error->getMessage()]);
            $response->setError('world_checkpoint_pending', 'Игровое состояние обновляется. Повторите запрос.');
            return $response;
        }
    }

    private function buildPage(Message $response): Message
    {
        Logger::getInstance()->info("this->aid: $this->aid");
        $AccountData = new AccountData($this->aid);

        // обработка действий перед построением
        if (PageActionService::handle($this->Msg, $AccountData, $this->aid, $response)) {
            $response->setMode("none");
            return $response;
        }
        $result = [];
        $page = null;
        switch ($this->Msg->getMode()) {
            case 'friends':
                try {
                    $result = (new \SPGame\Game\Pages\FriendsPage($this->Msg))->render($AccountData);
                    $response->setData('Page', $result);
                } catch (\InvalidArgumentException $exception) {
                    $response->setError('friends_unavailable', $exception->getMessage());
                } catch (\Throwable $exception) {
                    $this->logger->error('Ошибка списка друзей', ['message' => $exception->getMessage()]);
                    $response->setError('friends_failed', 'Не удалось обновить список друзей.');
                }
                break;
            case 'battlereport':
                try {
                    $response->setData('Page', (new \SPGame\Game\Pages\BattleReportPage($this->Msg))->render($AccountData));
                } catch (\InvalidArgumentException $exception) {
                    $response->setError('battle_report_unavailable', $exception->getMessage());
                } catch (\Throwable $exception) {
                    $this->logger->error('Ошибка архива боевых докладов', ['message' => $exception->getMessage()]);
                    $response->setError('battle_report_failed', 'Не удалось загрузить или сохранить боевой доклад.');
                }
                break;
            case 'information':
                try {
                    $response->setData('Page', (new \SPGame\Game\Pages\InformationPage($this->Msg))->render($AccountData));
                } catch (\InvalidArgumentException $exception) {
                    $response->setError('information_unavailable', $exception->getMessage());
                } catch (\Throwable $exception) {
                    $this->logger->error('Ошибка формирования информации об объекте', ['message' => $exception->getMessage()]);
                    $response->setError('information_failed', 'Не удалось получить информацию об объекте.');
                }
                break;
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
                if ($this->Msg->getAction() === 'ActualData') {
                    $view = Connect::getGalaxyView($this->fd);
                    if ($view !== null) {
                        $this->Msg->setData('galaxy', $view['galaxy']);
                        $this->Msg->setData('system', $view['system']);
                    }
                }
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
            if ($this->Msg->getMode() === 'galaxy') {
                Connect::setGalaxyView(
                    $this->fd,
                    (int)$result['System']['galaxy'],
                    (int)$result['System']['system']
                );
            }
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
        $language = (string)($AccountData['Account']['lang'] ?? 'ru');
        foreach ($Planets as &$planet) {
            $planet['name'] = CelestialNameGenerator::planetDisplayName($planet, $language);
        }
        unset($planet);

        return [
            'current_planet'    => $AccountData['User']['current_planet'],
            'main_planet'       => $AccountData['User']['main_planet'],
            'Planets'           => $Planets
        ];
    }
}
