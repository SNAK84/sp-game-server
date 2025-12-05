<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;
use SPGame\Core\Defaults;
use SPGame\Core\Logger;

use SPGame\Game\Services\Helpers;
use SPGame\Game\Services\RepositorySaver;
use SPGame\Game\Services\AccountData;
use SPGame\Game\Services\BuildFunctions;
use Swoole\Table;


class PlanetResources extends BaseRepository
{
    /** @var Table Основная таблица */
    protected static Table $table;

    protected static string $className = 'PlanetResources';
    protected static string $tableName = 'resources_planet';

    /** @var Table */
    protected static Table $syncTable;

    protected static array $tableSchema = [
        'columns' => [
            'id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => 0],
            'metal'     => ['swoole' => [Table::TYPE_FLOAT], 'sql' => 'DOUBLE DEFAULT 0', 'default' => [Defaults::CALLABLE, "SPGame\Game\Repositories\Config::getValue:StartRes901"]],
            'crystal'   => ['swoole' => [Table::TYPE_FLOAT], 'sql' => 'DOUBLE DEFAULT 0', 'default' => [Defaults::CALLABLE, "SPGame\Game\Repositories\Config::getValue:StartRes902"]],
            'deuterium' => ['swoole' => [Table::TYPE_FLOAT], 'sql' => 'DOUBLE DEFAULT 0', 'default' => [Defaults::CALLABLE, "SPGame\Game\Repositories\Config::getValue:StartRes903"]],
            'energy'    => ['swoole' => [Table::TYPE_FLOAT], 'default' => 0.0],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
        ],
    ];
}


class UserResources extends BaseRepository
{
    /** @var Table Основная таблица */
    protected static Table $table;

    protected static string $className = 'UserResources';
    protected static string $tableName = 'resources_user';

    /** @var Table */
    protected static Table $syncTable;

    protected static array $tableSchema = [
        'columns' => [
            'id'  => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => 0],
            'credit'   => ['swoole' => [Table::TYPE_FLOAT], 'sql' => 'DOUBLE DEFAULT 0', 'default' => 0],
            'doubloon' => ['swoole' => [Table::TYPE_FLOAT], 'sql' => 'DOUBLE DEFAULT 0', 'default' => 0],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
        ],
    ];
}


class Resources
{

    //protected static array $Resources = [];
    protected static Logger $logger;

    public static function init(?RepositorySaver $saver = null): void
    {
        self::$logger = Logger::getInstance();

        PlanetResources::init($saver);
        UserResources::init($saver);
    }

    /*
    public static function getByUserId(int $userId): ?array
    {
        $user = Users::findById($userId);
        if (!$user) return null;

        $planetId = $user['current_planet'] ?? 0;

        $Resources = self::getByPlanetId($planetId);

        return $Resources;
    }
    */

    public static function get(AccountData $AccountData): ?array
    {
        $Planet = $AccountData['Planet'];
        if (!$Planet) return null;

        $User = $AccountData['User'] ?? 0;
        if (!$User) return null;

        $planetResources = PlanetResources::findById($Planet['id']);

        $userResources = UserResources::findById($User['id']);

        $Resources = [];

        foreach (Vars::$reslist['resstype'][1] as $ResID) {
            $Resources[$ResID] = [
                'count' => $planetResources[Vars::$resource[$ResID]],
                'max' => 0,
                'perhour' => 0,
                'time' => $Planet['update_time']
            ];
        }

        foreach (Vars::$reslist['resstype'][2] as $ResID) {
            $Resources[$ResID]  = array(
                'count' => $planetResources[Vars::$resource[$ResID]],
                'max' => 0,
                'used' => 0
            );
        }
        foreach (Vars::$reslist['resstype'][3] as $ResID) {
            $Resources[$ResID]  = array(
                'count' => $userResources[Vars::$resource[$ResID]]
            );
        }

        self::ReBuildRes($Resources, $AccountData);

        return $Resources;
    }

    public static function ReBuildRes(array &$Resources, AccountData $AccountData)
    {

        $Planet     = $AccountData['Planet'];
        $Techs      = $AccountData['Techs'];
        $Builds     = $AccountData['Builds'];

        $BasicIncome = [
            901 => $Planet['planet_type'] == 3 ? 0 : Config::getValue('metalBasicIncome'),
            902 => $Planet['planet_type'] == 3 ? 0 : Config::getValue('crystalBasicIncome'),
            903 => $Planet['planet_type'] == 3 ? 0 : Config::getValue('deuteriumBasicIncome'),
        ];

        $temp    = array(
            901    => array('max'    => 0, 'plus'    => 0, 'minus'    => 0,),
            902    => array('max'    => 0, 'plus'    => 0, 'minus'    => 0,),
            903    => array('max'    => 0, 'plus'    => 0, 'minus'    => 0,),
            911    => array('plus'    => 0, 'minus'    => 0,)
        );

        $BuildTemp      = $Planet['temp_max'];
        $BuildEnergy    = $Techs[Vars::$resource[113]];

        foreach (Vars::$reslist['storage'] as $ProdID) {
            foreach (Vars::$reslist['resstype'][1] as $ID) {
                if (!isset(Vars::$storage[$ProdID][$ID]))
                    continue;

                $BuildLevel         = $Builds[Vars::$resource[$ProdID]];
                $temp[$ID]['max']  += round(eval(self::getProd(Vars::$storage[$ProdID][$ID])));
            }
        }

        $ressIDs = array_merge(Vars::$reslist['resstype'][1], Vars::$reslist['resstype'][2]);

        foreach (Vars::$reslist['prod'] as $ProdID) {

            $BuildLevelFactor   = EntitySettings::get($Planet['id'], $ProdID)['efficiency'];
            $BuildLevel         = BuildFunctions::getElementLevel($ProdID, $AccountData);

            foreach ($ressIDs as $ID) {
                if (!isset(Vars::$production[$ProdID][$ID]))
                    continue;

                $eval = self::getProd(Vars::$production[$ProdID][$ID], $ProdID, $AccountData);
                $Production = eval($eval);



                if ($Production > 0) {
                    $temp[$ID]['plus']    += $Production;
                } else {

                    //if (in_array($ID, Vars::$reslist['resstype'][1]) && $Builds[Vars::$resource[$ID]] == 0) {
                    if (in_array($ID, Vars::$reslist['resstype'][1]) && $Resources[$ID]['count'] == 0) {
                        continue;
                    }
                    $temp[$ID]['minus']   += $Production;
                }
            }
        }

        $Resources[901]['max'] = $temp[901]['max'] * Config::getValue('StorageMultiplier') * (1 + Factors::getFactor('ResourceStorage', $AccountData));
        $Resources[902]['max'] = $temp[902]['max'] * Config::getValue('StorageMultiplier') * (1 + Factors::getFactor('ResourceStorage', $AccountData));
        $Resources[903]['max'] = $temp[903]['max'] * Config::getValue('StorageMultiplier') * (1 + Factors::getFactor('ResourceStorage', $AccountData));

        $Resources[911]['max'] = round($temp[911]['plus'] * Config::getValue('EnergySpeed')) * (1 + Factors::getFactor('Energy', $AccountData));
        $Resources[911]['used'] = $temp[911]['minus'] * Config::getValue('EnergySpeed');
        $Resources[911]['count'] = $Resources[911]['max'] + $Resources[911]['used'];

        $prodLevel = ($temp[911]['minus'] == 0) ? 0 : min(1, $temp[911]['plus'] / abs($temp[911]['minus']));

        $Resources[901]['perhour'] = (
            $temp[901]['plus'] *
            (1 + Factors::getFactor('Resource', $AccountData) + 0.02 * $Techs[Vars::$resource[131]]) * $prodLevel +
            $temp[901]['minus'] + $BasicIncome[901]) * Config::getValue('ResourceMultiplier');


        $Resources[902]['perhour'] = (
            $temp[902]['plus'] *
            (1 + Factors::getFactor('Resource', $AccountData) + 0.02 * $Techs[Vars::$resource[132]]) * $prodLevel +
            $temp[902]['minus'] + $BasicIncome[902]) * Config::getValue('ResourceMultiplier');


        $Resources[903]['perhour'] = (
            $temp[903]['plus'] *
            (1 + Factors::getFactor('Resource', $AccountData) + 0.02 * $Techs[Vars::$resource[133]]) * $prodLevel +
            $temp[903]['minus'] + $BasicIncome[903]) * Config::getValue('ResourceMultiplier');
        //}

        return $Resources;
    }

    public static function getProd($Calculation, $Element = false, ?AccountData $AccountData = null)
    {

        if ($Element) {

            $Techs      = $AccountData['Techs'];
            $Builds     = $AccountData['Builds'];
            $Fleet      = [];

            // функция подстановки
            $replaced = preg_replace_callback(
                '/__([A-Z]+)__([0-9]+)__/',
                function ($matches) use ($Builds, $Techs, $Fleet) {
                    $type = strtoupper($matches[1]);
                    $id   = (int)$matches[2];

                    // сначала пробуем получить "имя" элемента через Vars::$resource, иначе используем id
                    $key = Vars::$resource[$id] ?? $id;

                    switch ($type) {
                        case 'BUILD':
                            // Builds может иметь ключи либо по имени либо по id
                            return (string)($Builds[$key] ?? $Builds[$id] ?? 0);
                        case 'TECH':
                            return (string)($Techs[$key] ?? $Techs[$id] ?? 0);
                        case 'FLEET':
                            return (string)($Fleet[$key] ?? $Fleet[$id] ?? 0);
                        default:
                            return '0';
                    }
                },
                $Calculation
            );
        }

        return 'return ' . trim((empty($replaced)) ? $Calculation : $replaced) . ';';
    }

    public static function processResources(float $StatTimeTick, AccountData &$AccountData): void
    {
        if ($AccountData['Planet']['update_time'] < $AccountData['Planet']['create_time']) {
            $AccountData['Planet']['update_time'] = $AccountData['Planet']['create_time'];
        }

        $ProductionTime = ($StatTimeTick - $AccountData['Planet']['update_time']);

        if ($ProductionTime > 0) {
            $AccountData['Planet']['update_time'] = $StatTimeTick;
            $Resources = &$AccountData['Resources'];

            //if ($Planets[$PID]['PlanetType'] == 3)
            //    return;

            foreach (Vars::$reslist['resstype'][1] as $ResID) {
                $Theoretical = $ProductionTime * ($Resources[$ResID]['perhour']) / 3600;
                if ($Theoretical < 0) {
                    $Resources[$ResID]['count'] = max($Resources[$ResID]['count'] + $Theoretical, 0);
                } elseif ($Resources[$ResID]['count'] <= $Resources[$ResID]['max']) {
                    $Resources[$ResID]['count'] = min($Resources[$ResID]['count'] + $Theoretical, $Resources[$ResID]['max']);
                }
                $Resources[$ResID]['count'] = max($Resources[$ResID]['count'], 0);
            }

            //Resources::updateByPlanetId($AccountData['Planet']['id'], $Resources);
        }
    }
    
    public static function updateByPlanetId(int $planetId, array $resources): void
    {
        if (empty($resources)) {
            return;
        }

        // Планета
        if ($planetId) {
            $planetRes = PlanetResources::findById($planetId);
            if ($planetRes) {
                foreach (Vars::$reslist['resstype'][1] as $resId) {
                    $key = Vars::$resource[$resId];
                    if (isset($resources[$resId]['count'])) {
                        $planetRes[$key] = $resources[$resId]['count'];
                    }
                }
                PlanetResources::update($planetRes);
            }
        }

        // Пользовательские ресурсы (если есть)
        $userId = Planets::findById($planetId)['owner_id'];
        if ($userId) {
            $userRes = UserResources::findById($userId);
            if ($userRes) {
                foreach (Vars::$reslist['resstype'][3] as $resId) {
                    $key = Vars::$resource[$resId];
                    if (isset($resources[$resId]['count'])) {
                        $userRes[$key] = $resources[$resId]['count'];
                    }
                }
                UserResources::update($userRes);
            }
        } else {
            Logger::getInstance()->warning("not find userId");
        }
    }
}
