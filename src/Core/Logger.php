<?php


namespace SPGame\Core;



enum LogLevel: int
{
    case DEBUG = 1;
    case INFO = 2;
    case WARNING = 3;
    case ERROR = 4;
    case CRITICAL = 5;

    public function toString(): string
    {
        return match ($this) {
            self::DEBUG => 'DEBUG',
            self::INFO => 'INFO',
            self::WARNING => 'WARNING',
            self::ERROR => 'ERROR',
            self::CRITICAL => 'CRITICAL',
        };
    }
}

class Logger
{
    private static ?Logger $instance = null;
    private LogLevel $minLevel;
    private string $logFile;
    private bool $islogFile = false;
    //private bool $enabled;
    private bool $echoToConsole;
    private string $dateFormat;

    private string $rotationMode;
    private int $maxSize;

    private function __construct()
    {
        $this->minLevel = LogLevel::from(
            Environment::getInt('LOG_LEVEL', LogLevel::INFO->value)
        );

        $this->logFile = $this->resolveLogFile();
        //$this->enabled = Environment::getBool('DEBUG_MODE', false);
        $this->echoToConsole = Environment::getBool('LOG_ECHO', true); // новый параметр
        
        $this->dateFormat = Environment::get('LOG_DATE_FORMAT', 'H:i:s d.m.y');

        $this->rotationMode = Environment::get('LOG_ROTATION_MODE', 'none');
        $this->maxSize = Environment::getInt('LOG_MAX_SIZE', 10 * 1024 * 1024);

        $this->updateLogFile();

        // Создаем папку для логов безопасно
        $this->safeMkdir(dirname($this->logFile), 0755);

        
    }

    private function updateLogFile(): void
    {
        if(!$this->islogFile) return;

        $baseFile = $this->resolveLogFile();

        if ($this->rotationMode === 'daily') {
            $this->logFile = preg_replace('/\.log$/', '_' . date("Y-m-d") . '.log', $baseFile);
        } else {
            $this->logFile = $baseFile;
        }
    }

    /** CLI-проверки сохраняют свой путь даже после повторной загрузки игрового .env. */
    private function resolveLogFile(): string
    {
        $testFile = PHP_SAPI === 'cli' ? getenv('SP_GAME_TEST_LOG_FILE') : false;
        if ($testFile !== false && $testFile !== '') return $testFile;
        return Environment::get('LOG_FILE', dirname(__DIR__, 2) . '/logs/game.log');
    }

    private function rotateLogs(): void
    {
        if ($this->rotationMode === 'size' && file_exists($this->logFile)) {
            clearstatcache();
            if (filesize($this->logFile) >= $this->maxSize) {
                $backup = $this->logFile . '.' . date('Ymd_His');
                rename($this->logFile, $backup);
                touch($this->logFile);
                $this->info("Log rotated: {$backup}");
            }
        }
    }

    public static function getInstance(): Logger
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function log(LogLevel $level, string $message, array $context = []): void
    {
        if ($level->value < $this->minLevel->value) {
            return;
        }

        $this->updateLogFile(); // актуализируем путь (для daily)
        $this->rotateLogs();    // проверяем ротацию по размеру

        $timestamp =  Time::FormatDateTime(null, $this->dateFormat);
        $message = $this->redactContext($message);
        $contextStr = empty($context) ? '' : ' ' . json_encode($this->redactContext($context), JSON_UNESCAPED_UNICODE);
        $logEntry = "[{$timestamp}] [{$level->toString()}] {$message}{$contextStr}" . PHP_EOL;

        // Вывод в консоль
        if ($this->echoToConsole) {
            echo $logEntry;
        }

        // Безопасная запись в файл
        $this->safeFilePutContents($this->logFile, $logEntry);
    }

    /** Фильтруем копию контекста; исходные игровые данные не изменяются. */
    private function redactContext(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 12) return '[TRUNCATED]';
        if (is_string($value)) {
            // MySQL может вставить значение токена в текст ошибки UNIQUE.
            return preg_replace("/(Duplicate entry )'[^\\r\\n]*'( for key )/i", "$1'[REDACTED]'$2", $value);
        }
        if (is_object($value)) {
            try {
                $value = $value instanceof \JsonSerializable ? $value->jsonSerialize() : get_object_vars($value);
            } catch (\Throwable) {
                return '[OBJECT]';
            }
            return $this->redactContext($value, $depth + 1);
        }
        if (!is_array($value)) return $value;

        $safe = [];
        foreach ($value as $key => $item) {
            $field = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$key));
            $sensitive = preg_match('/(?:password(?:hash|confirmation)?|passwd|pwd|passphrase|token|secret(?:key)?|apikey|privatekey|authorization|cookie|sessionid|pin|pincode|otp|verificationcode)$/', $field);
            $safe[$key] = $sensitive ? '[REDACTED]' : $this->redactContext($item, $depth + 1);
        }
        return $safe;
    }

    // Удобные методы для каждого уровня
    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }
    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }
    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }
    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }


    public function logException(\Throwable $exception, string $message = 'Exception occurred'): void
    {
        $context = [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            // Аргументы функций могут содержать пароль или весь запрос.
            'trace' => array_map(static fn(array $frame): array => array_intersect_key($frame,
                array_flip(['file', 'line', 'class', 'type', 'function'])), $exception->getTrace())
        ];

        $this->error($message, $context);
    }

    // Безопасное создание папки
    private function safeMkdir(string $path, int $mode = 0755): void
    {
        if (is_dir($path)) {
            $this->islogFile = true;
            return;
        }

        $parent = dirname($path);
        if (!is_writable($parent)) {
            // Папка родителя недоступна, выводим прямо в консоль
            echo "Cannot create directory {$path}, parent {$parent} is not writable" . PHP_EOL;

            $this->islogFile = false;
            return;
        }

        if (!mkdir($path, $mode, true)) {
            echo "Failed to create directory {$path}" . PHP_EOL;
            $this->islogFile = false;
            return;
        }
    }



    // Безопасная запись в файл
    private function safeFilePutContents(string $file, string $data): void
    {
        if(!$this->islogFile) return;

        if (file_put_contents($file, $data, FILE_APPEND | LOCK_EX) === false) {
            echo "Failed to write to file: {$file}" . PHP_EOL;
            $this->islogFile = false;
            return;
        }
    }

}
