<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Repositories\BaseRepository;

use Swoole\Timer;

class RepositorySaver
{
    /** @var class-string<BaseRepository>[] */
    private array $repositories = [];

    private Logger $logger;

    public function __construct()
    {
        $this->logger = Logger::getInstance();
    }

    /**
     * Регистрируем класс репозитория
     */
    public function register(string $repositoryClass): void
    {
        if (is_subclass_of($repositoryClass, BaseRepository::class)) {
            $this->repositories[] = $repositoryClass;
        } else {
            $this->logger->warning("Попытка зарегистрировать не-репозиторий: $repositoryClass");
        }
    }

    public function saveAll(bool $strict = false): void
    {
        FleetMiningCheckpointService::world(function () use ($strict): void {
            if ($strict) FleetsData::getInstance()->save();
            $this->flushRepositories($strict);
        });
    }

    private function flushRepositories(bool $strict): void
    {
        $failed = [];
        foreach ($this->repositories as $repoClass) {
            try {
                $repoClass::syncToDatabase();
                // syncToDatabase оставляет dirty markers при SQL-ошибке и может
                // не бросать исключение. При shutdown нельзя назвать это успехом.
                if ($strict) {
                    $pending = $repoClass::getPendingSync();
                    if ($pending['update'] !== [] || $pending['delete'] !== []) {
                        throw new \RuntimeException('Остались несохранённые изменения: '
                            . count($pending['update']) . ' update, ' . count($pending['delete']) . ' delete.');
                    }
                }
            } catch (\Throwable $e) {
                $failed[] = $repoClass;
                $this->logger->error("RepositorySaver error in $repoClass: " . $e->getMessage());
            }
        }
        if ($strict && $failed !== []) {
            throw new \RuntimeException('Не сохранены репозитории: ' . implode(', ', $failed));
        }
    }
}
