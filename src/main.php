<?php

declare(strict_types=1);

namespace SPGame;

require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\Logger;
use SPGame\Core\Connect;
use SPGame\Core\Environment;
use SPGame\Core\WSocket;
use SPGame\Game\Repositories\Accounts;
use SPGame\Game\Repositories\Users;
use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\Resources;

use SPGame\Game\Repositories\Techs;
use SPGame\Game\Repositories\Builds;
use SPGame\Game\Repositories\Ships;
use SPGame\Game\Repositories\Defenses;

use SPGame\Game\Repositories\Galaxy;
use SPGame\Game\Repositories\GalaxyOrbits;
use SPGame\Game\Repositories\OrbitalResourceSites;
use SPGame\Game\Repositories\DebrisFields;
use SPGame\Game\Repositories\PlanetBattles;

use SPGame\Game\Repositories\Queues;
use SPGame\Game\Repositories\Messages;

use SPGame\Game\Repositories\EntitySettings;

use SPGame\Game\Repositories\Config;
use SPGame\Game\Repositories\FleetCommands;
use SPGame\Game\Repositories\Fleets;
use SPGame\Game\Repositories\FleetsShips;
use SPGame\Game\Repositories\Vars;
use SPGame\Game\Repositories\PlayerQueue;

use SPGame\Game\Services\RepositorySaver;
use SPGame\Game\Services\GalaxyGenerator;

ini_set('memory_limit', '2G');
set_time_limit(0);

try {
    Environment::init(__DIR__ . '/../.env');
    $logger = Logger::getInstance();

    if (Environment::getBool('DEBUG_MODE', false)) {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
    } else {
        error_reporting(E_ERROR | E_WARNING | E_PARSE);
        ini_set('display_errors', '0');
    }

    $logger->info('Starting SP-Game WebSocket Server');

    $saver = new RepositorySaver();

    Accounts::init($saver);
    Connect::init();

    Vars::init();
    Config::init($saver);

    Users::init($saver);
    \SPGame\Game\Repositories\Friends::init($saver);
    Planets::init($saver);
    Resources::init($saver);

    Techs::init($saver);
    Builds::init($saver);
    Ships::init($saver);
    Defenses::init($saver);

    Galaxy::init($saver);
    GalaxyOrbits::init($saver);
    OrbitalResourceSites::init($saver);
    \SPGame\Game\Repositories\ResourceBases::init($saver);
    DebrisFields::init($saver);
    PlanetBattles::init($saver);
    \SPGame\Game\Repositories\SpaceBattles::init($saver);
    \SPGame\Game\Repositories\SpaceDebrisFields::init($saver);
    \SPGame\Game\Repositories\BattleReports::init();
    GalaxyGenerator::ensureAllSpecialOrbits();
    GalaxyGenerator::reconcilePlanetOrbits();

    Fleets::init($saver);
    FleetsShips::init($saver);
    FleetCommands::init($saver);
    \SPGame\Game\Repositories\FleetExpeditions::init($saver);
    \SPGame\Game\Repositories\ResourceSurveys::init($saver);
    \SPGame\Game\Repositories\SurveyResults::init($saver);
    // Журнал восстанавливается до допуска игровых действий и общего saver.
    \SPGame\Game\Repositories\MiningCheckpoints::init();
    \SPGame\Game\Repositories\FleetAttackGroups::init($saver);

    Queues::init($saver);
    Messages::init($saver);

    EntitySettings::init($saver);
    \SPGame\Game\Repositories\PhalanxScans::init();
    \SPGame\Game\Repositories\SensorContacts::init();
    PlayerQueue::init();

    \SPGame\Game\Services\FleetMiningCheckpointService::recoverPending();
    PlayerQueue::restorePendingAfterRestart();

    $server = new WSocket(); // Создаём и запускаем сервер

} catch (\Exception $e) {
    error_log('Failed to start SP-Game WebSocket Server: ' . $e->getMessage());
    exit(1);
}
