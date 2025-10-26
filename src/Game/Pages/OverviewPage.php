<?php

namespace SPGame\Game\Pages;


use SPGame\Game\Repositories\Planets;

use SPGame\Game\Repositories\Queues;
use SPGame\Game\Repositories\Vars;

use SPGame\Game\Services\Helpers;
use SPGame\Game\Services\AccountData;
use SPGame\Game\Services\QueuesServices;

class OverviewPage extends AbstractPage
{
    public function render(AccountData &$AccountData): array
    {
        $User = &$AccountData['User'];
        $Planet = &$AccountData['Planet'];


        $BuildsQueue = Queues::getCurrentQueue(QueuesServices::BUILDS, $User['id'], $Planet['id']) ?: [];
        $TechsQueue  = Queues::getCurrentQueue(QueuesServices::TECHS, $User['id'], $Planet['id']) ?: [];
        $HangarQueue = Queues::getCurrentQueue(QueuesServices::HANGARS, $User['id'], $Planet['id']) ?: [];

        /*
        if (count($BuildsQueue) > 0) {
            $Element = $BuildsQueue[0]['object_id'];
            $BuildsQueue = [
                'id'         => $Element,
                'qid'        => $BuildsQueue[0]['id'],
                'name'       => Vars::$resource[$Element],
                'level'      => $BuildsQueue[0]['count'],
                'start_time' => $BuildsQueue[0]['start_time'],
                'end_time'   => $BuildsQueue[0]['end_time'],
                'action'     => $BuildsQueue[0]['action'],
                'status'     => $BuildsQueue[0]['status']
            ];
        }

        if (count($TechsQueue) > 0) {
            $QueuePlanet = Planets::findById($TechsQueue[0]['planet_id']);
            $Element = $TechsQueue[0]['object_id'];
            $TechsQueue = [
                'id'         => $Element,
                'qid'        => $TechsQueue[0]['id'],
                'name'       => Vars::$resource[$Element],
                'level'      => $TechsQueue[0]['count'],
                'start_time' => $TechsQueue[0]['start_time'],
                'end_time'   => $TechsQueue[0]['end_time'],
                'action'     => $TechsQueue[0]['action'],
                'status'     => $TechsQueue[0]['status'],
                'planet'     => $QueuePlanet
            ];
        }

        if (count($HangarQueue) > 0) {
            $Element = $HangarQueue[0]['object_id'];
            $HangarQueue = [
                'id'         => $Element,
                'qid'        => $HangarQueue[0]['id'],
                'name'       => Vars::$resource[$Element],
                'count'      => $HangarQueue[0]['count'],
                'time'       => $HangarQueue[0]['time'],
                'start_time' => $HangarQueue[0]['start_time'],
                'end_time'   => $HangarQueue[0]['end_time'],
                'status'     => $HangarQueue[0]['status']
            ];
        }
        */

        return [
            'page'          => 'overview',
            'UserName'      => $User['name'],
            'PlanetImage'   => $Planet['image'],
            'PlanetName'    => $Planet['name'],
            'diameter'      => $Planet['size'],
            'field_used'    => Helpers::getCurrentFields($AccountData),
            'field_current' => Helpers::getMaxFields($AccountData),
            'TempMin'       => $Planet['temp_min'],
            'TempMax'       => $Planet['temp_max'],
            'galaxy'        => $Planet['galaxy'],
            'system'        => $Planet['system'],
            'planet'        => $Planet['planet'],
            'Queues'        => [
                'Build'  => $this->formatQueue($BuildsQueue, 'build'),
                'Tech'   => $this->formatQueue($TechsQueue, 'tech'),
                'Hangar' => $this->formatQueue($HangarQueue, 'hangar'),
            ],
        ];
    }

    /**
     * Форматирует очередь для отображения в overview
     */
    private function formatQueue(array $queue, string $type): array
    {
        if (empty($queue)) {
            return [];
        }

        $q = $queue[0];
        $element = $q['object_id'] ?? null;

        if (!$element) {
            return [];
        }

        $result = [
            'id'         => $element,
            'qid'        => $q['id'],
            'name'       => Vars::$resource[$element] ?? 'Unknown',
            'start_time' => $q['start_time'],
            'end_time'   => $q['end_time'],
            'status'     => $q['status'] ?? null,
        ];

        switch ($type) {
            case 'build':
                $result += [
                    'level'  => $q['count'],
                    'action' => $q['action'] ?? null,
                ];
                break;

            case 'tech':
                $planet = Planets::findById($q['planet_id']);
                $result += [
                    'level'  => $q['count'],
                    'action' => $q['action'] ?? null,
                    'planet' => $planet,
                ];
                break;

            case 'hangar':
                $result += [
                    'count' => $q['count'],
                    'time'  => $q['time'],
                ];
                break;
        }

        return $result;
    }
}
