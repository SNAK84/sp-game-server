<?php

namespace SPGame\Game\Services;

use SPGame\Game\Repositories\PlayerQueue;
use SPGame\Game\Repositories\Messages;
use SPGame\Game\Repositories\MessageType;
use SPGame\Game\Repositories\Vars;
use SPGame\Core\Logger;

use SPGame\Game\Services\Lang;

class Notification
{
    /**
     * Отправить системное сообщение
     */
    public static function sendSystem(AccountData $AccountData, int $Type, string $From, string $Subject, string $Sample, array $Data, float $Time = null): void
    {
        $Time = $Time ?? microtime(true);

        $userId = $AccountData['User']['id'] ?? 0;
        if (!$userId) {
            Logger::getInstance()->error("Notification::system() — не удалось определить ID пользователя");
            return;
        }

        $Message = [
            'to_id' => $userId,
            'type'  => $Type,
            'from' => $From,
            'subject' => $Subject,
            'sample' => $Sample,
            'data' => json_encode($Data),
            'time' => $Time
        ];

        Messages::add($Message);

        PlayerQueue::addQueue($AccountData['Account']['id'], $userId, $AccountData['Planet']['id'], PlayerQueue::ActionMessagesNew);
    }

    /**
     * Уведомление от строительства
     */
    public static function sendBuildBuyable(AccountData $AccountData, array $cost, string $QueueAction, int $Element, float $Time = null): void
    {
        $Time = $Time ?? microtime(true);

        $Resources = [];
        foreach ($cost as $resid => $count) {
            $Resources[$resid] = $AccountData['Resources'][$resid]['count'];
        }

        $Data = [
            'lang' => [
                'PlanetType' => ($AccountData['Planet']['planet_type'] === 1) ? 'TypePlanet' : 'TypeMoon',
                'queueProcessGen' => 'queueProcessGen.' . $QueueAction,
                'ElementId' => "Element." . $Element,
            ],
            'PlanetName' => $AccountData['Planet']['name'],
            'PlanetGalaxy' => $AccountData['Planet']['galaxy'],
            'PlanetSystem' => $AccountData['Planet']['system'],
            'Resources' => $Resources,
            'ResourcesCost' => $cost,
        ];

        $MessageSample = 'BuildBuyable';

        $MessageType = Messages::Type::Build->value;

        //$LangSubject = Lang::get($AccountData['Account']['lang'], "notifications")['subjects'];
        $MessageSubject = "";
        $MessageFrom = '';
        switch (QueuesServices::QueueType($Element)) {
            case QueuesServices::BUILDS:
                $MessageSubject = 'subject.Build.BuildBuildingCant';
                $MessageFrom = 'from.Build.BuildBuilding';
                break;
            case QueuesServices::TECHS:
                $MessageSubject = 'subject.Build.BuildTechCant';
                $MessageFrom = 'from.Build.BuildTech';
                break;
            case QueuesServices::HANGARS:
                $MessageSubject = 'subject.Build.BuildHangarCant';
                $MessageFrom = 'from.Build.BuildHangar';
                break;
        }

        self::sendSystem($AccountData, $MessageType, $MessageFrom, $MessageSubject, $MessageSample, $Data, $Time);
    }

    public static function NewMessages(int $userId): int
    {
        $count = Messages::findByIndex("to_read", [$userId, 0]) ?? [];

        return count($count);
    }

    public static function getAllMessages(int $userId): ?array
    {
        $Messages = Messages::findByIndex("to", [$userId]);

        if (!$Messages) {
            return null;
        }

        // Сортируем по времени (от новых к старым)
        usort($Messages, static function ($a, $b) {
            // time хранится как float (microtime)
            return $b['time'] <=> $a['time'];
        });

        return $Messages;
    }

    /**
     * Получить все сообщения пользователя указанного типа
     *
     * @param int $userId ID пользователя
     * @param MessageType $Type Тип сообщений (enum)
     * @return array|null
     */
    public static function getTypeMessages(int $userId, MessageType $Type): ?array
    {
        $Messages = Messages::findByIndex("to_type", [$userId, $Type->value]);

        if (!$Messages) {
            return null;
        }

        // Сортируем по времени (от новых к старым)
        usort($Messages, static function ($a, $b) {
            // time хранится как float (microtime)
            return $b['time'] <=> $a['time'];
        });

        return $Messages;
    }

    public static function setReadMessages(int $userId, ?array $ReadId): void
    {
        if (!$ReadId) return;

        foreach ($ReadId as $id) {
            $Message = Messages::findById($id);
            if (!$Message || $Message['to_id'] !== $userId)
                continue;

            $Message['read'] = 1;
            Messages::update($Message);
        }
        //Logger::getInstance()->info("setReadMessages $userId", [$ReadId]);
    }
}
