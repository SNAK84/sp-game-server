<?php


namespace Core;


enum LogLevel: int
{
    case DEBUG = 1;
    case INFO = 2;
    case WARNING = 3;
    case ERROR = 4;
    case CRITICAL = 5;

    public function toString(): string
    {
        return match($this) {
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
    private bool $enabled;

    private function __construct()
    {
        $this->minLevel = LogLevel::from(
            Environment::getInt('LOG_LEVEL', LogLevel::INFO->value)
        );
        $this->logFile = Environment::get('LOG_FILE', dirname(__DIR__, 2) . '/logs/game.log');
        $this->enabled = Environment::getBool('DEBUG_MODE', false);
        
        // Create logs directory if it doesn't exist
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
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
        if (!$this->enabled || $level->value < $this->minLevel->value) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = empty($context) ? '' : ' ' . json_encode($context);
        $logEntry = "[{$timestamp}] [{$level->toString()}] {$message}{$contextStr}" . PHP_EOL;
        
        echo $logEntry;
        
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

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
            'trace' => $exception->getTraceAsString()
        ];
        
        $this->error($message, $context);
    }
}
