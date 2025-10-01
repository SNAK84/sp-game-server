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

    public function saveAll(): void
    {
        foreach ($this->repositories as $repoClass) {
            try {
                $repoClass::syncToDatabase();
            } catch (\Throwable $e) {
                $this->logger->error("RepositorySaver error in $repoClass: " . $e->getMessage());
            }
        }
    }
}
