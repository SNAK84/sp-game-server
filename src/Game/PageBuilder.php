<?php

namespace SPGame\Game;

use SPGame\Core\Connect;
use SPGame\Core\Message;

use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\Users;

class PageBuilder
{
    private Message $Msg;
    private int $fd;
    private int $aid;

    public function __construct(Message $Msg, int $fd)
    {
        $this->fd = $fd;
        $this->Msg = $Msg;
        $this->aid = Connect::getAccount($fd);
    }

    /**
     * Сборка ответа для клиента на запрошенную страницу
     */
    public function build(string $page): array
    {
        $result = [];
        switch ($page) {
            case 'overview':
                $result =  $this->buildOverview();
                break;
            case 'resources':
                $result =  $this->buildResources();
                break;
            case 'buildings':
                $result =  $this->buildBuildings();
                break;
            default:
                $result =  [
                    'error' => 'Unknown page: ' . $page,
                ];
                break;
        }

        return $result;
    }

    private function buildOverview(): array
    {


        $User = Users::findByAccount($this->aid);
        $Planet = Planets::findByUserId($User['id']);

        return [
            'page' => 'overview',
            'UserName' => $User['name'],
            'PlanetImage' => $Planet['image'],
            "PlanetName" => $Planet['name'],
            "diameter" => $Planet['size'],
            "field_used" => 0,
            "field_current" => $Planet['fields'],
            "TempMin" => $Planet['temp_min'],
            "TempMax" => $Planet['temp_max'],
            "galaxy" => $Planet['galaxy'],
            "system" => $Planet['system']
        ];
    }

    private function buildResources(): array
    {
        return [
            'page' => 'resources',
            'storage' => $this->vars['resource_storage'] ?? [],
            'production' => $this->vars['resource_production'] ?? [],
        ];
    }

    private function buildBuildings(): array
    {
        return [
            'page' => 'buildings',
            'buildings' => $this->vars['buildings'] ?? [],
        ];
    }
}
