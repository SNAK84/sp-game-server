<?php

declare(strict_types=1);

namespace SPGame\Game\Pages;

use SPGame\Game\Repositories\BattleReports;
use SPGame\Game\Services\AccountData;
use SPGame\Game\Services\BattleReportService;

/** Вспомогательный диалог: не изменяет страницу-получателя ActualData. */
final class BattleReportPage extends AbstractPage
{
    public function render(AccountData &$AccountData): array
    {
        $userId = (int)$AccountData['User']['id'];
        $action = $this->Msg->getAction() ?: 'get';
        if ($action === 'list') {
            $page = $this->Msg->getData('page', 1);
            if (!is_int($page) || $page < 1) throw new \InvalidArgumentException('Некорректная страница архива.');
            return BattleReports::savedFor($userId, $page);
        }
        if (!in_array($action, ['get', 'save', 'unsave'], true)) {
            throw new \InvalidArgumentException('Неизвестное действие с боевым докладом.');
        }
        $id = BattleReports::validateId($this->Msg->getData('id'));
        if ($action !== 'get') BattleReports::setSaved($id, $userId, $action === 'save');
        $data = BattleReports::get($id, $userId);
        // После снятия последней закладки старый доклад сразу становится просроченным.
        if ($data === null && $action !== 'unsave') throw new \InvalidArgumentException('Боевой доклад недоступен или срок хранения истёк.');
        if (isset($data['report'])) {
            $data['report'] = BattleReportService::localizeNames($data['report'], (string)($AccountData['Account']['lang'] ?? 'ru'));
        }
        return $data ?? ['report' => null, 'savedByMe' => false, 'expiresAt' => null];
    }
}
