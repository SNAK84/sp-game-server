<?php

namespace SPGame\Game\Repositories;

use Swoole\Table;
use SPGame\Core\Defaults;
use SPGame\Game\Services\RepositorySaver;
use SPGame\Game\Services\QueuesServices;


enum MessageType: int
{
    case Build          = 1;    // Постройки
    case System         = 2;    // Системные
    case News           = 3;    // Новости
    case Players        = 11;   // Игроки
    case Alliance       = 12;   // Альянс
    case Spy            = 21;   // Шпионаж
    case Fights         = 22;   // Битвы
    case Transport      = 23;   // Транспорт
    case Expeditions    = 24;   // Экспедиции
    case All            = 99;   // Все сообщения

    public function toString(): string
    {
        return match ($this) {
            self::Build         => 'Build',
            self::System        => 'System',
            self::News          => 'News',
            self::Players       => 'Players',
            self::Alliance      => 'Alliance',
            self::Spy           => 'Spy',
            self::Fights        => 'Fights',
            self::Transport     => 'Transport',
            self::Expeditions   => 'Expeditions',
            self::All           => 'All',
        };
    }
}

class Messages extends BaseRepository
{
    public const Type = MessageType::class;
    
    protected static string $className = 'Messages';
    protected static string $tableName = 'messages';
    protected static int $tableSize = 1024 * 8;

    /** @var Table Основная таблица */
    protected static Table $table;

    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    /** @var Table */
    protected static Table $syncTable;

    /**
     * Схема таблицы сообщений
     */
    protected static array $tableSchema = [
        'columns' => [
            'id'       => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => Defaults::AUTOID],
            'from_id'  => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => 0],
            'from'     => ['swoole' => [Table::TYPE_STRING, 64], 'sql' => 'VARCHAR(64) NOT NULL', 'default' => ''],
            'to_id'    => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => 0],
            'type'     => ['swoole' => [Table::TYPE_INT, 1], 'sql' => "INT UNSIGNED NOT NULL", 'default' => 0],
            'subject'  => ['swoole' => [Table::TYPE_STRING, 128], 'sql' => "VARCHAR(128) NOT NULL", 'default' => ''],
            'text'     => ['swoole' => [Table::TYPE_STRING, 1024], 'sql' => "TEXT NOT NULL", 'default' => ''],
            'sample'   => ['swoole' => [Table::TYPE_STRING, 64], 'sql' => "VARCHAR(64) NOT NULL", 'default' => ''],
            'data'     => ['swoole' => [Table::TYPE_STRING, 1024], 'sql' => "TEXT NOT NULL", 'default' => ''],
            'time'     => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE NOT NULL', 'default' => Defaults::MICROTIME],
            'read'     => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1) DEFAULT 0', 'default' => 0], // прочитано или нет
        ],
        'indexes' => [
            ['type' => 'PRIMARY', 'fields' => ['id']],
            ['type' => 'INDEX', 'name' => 'idx_from', 'fields' => ['from_id']],
            ['type' => 'INDEX', 'name' => 'idx_to', 'fields' => ['to_id']],
            ['type' => 'INDEX', 'name' => 'idx_type', 'fields' => ['type']],
            ['type' => 'INDEX', 'name' => 'idx_time', 'fields' => ['time']],
            ['type' => 'INDEX', 'name' => 'idx_read', 'fields' => ['read']],
        ],
    ];

    /** Индексы Swoole */
    protected static array $indexes = [
        'to'            => ['key' => ['to_id'], 'Unique' => false],               // сообщения к пользователю
        'from_to'       => ['key' => ['from_id', 'to_id'], 'Unique' => false],    // сообщения от пользователя к пользователю
        'to_read'       => ['key' => ['to_id', 'read'], 'Unique' => false],       // выборка непрочитанных сообщений
        'to_type'       => ['key' => ['to_id', 'type'], 'Unique' => false],       // сообщения к пользователю по типу
        'type_time'     => ['key' => ['type', 'time'], 'Unique' => false],        // сортировка по типу и времени
        'from_type'     => ['key' => ['from_id', 'type'], 'Unique' => false],     // сообщения от пользователя по типу
        'to_type_read'  => ['key' => ['to_id', 'type', 'read'], 'Unique' => false] // сообщения пользователю по типу и статусу прочтения
    ];
}
