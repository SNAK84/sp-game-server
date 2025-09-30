<?php

namespace SPGame\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    protected PHPMailer $mailer;
    protected Logger $logger;

    public function __construct()
    {
        $this->logger = Logger::getInstance();

        $this->mailer = new PHPMailer(true);

        try {
            // Настройки SMTP из Environment
            $this->mailer->isSMTP();
            $this->mailer->Host       = Environment::get('MAIL_HOST', 'localhost');
            $this->mailer->SMTPAuth   = Environment::getBool('MAIL_SMTP_AUTH', false);
            $this->mailer->Username   = Environment::get('MAIL_USERNAME', '');
            $this->mailer->Password   = Environment::get('MAIL_PASSWORD', '');
            $this->mailer->SMTPSecure = Environment::get('MAIL_ENCRYPTION', PHPMailer::ENCRYPTION_STARTTLS);
            $this->mailer->Port       = Environment::getInt('MAIL_PORT', 25);

            // Нормализация шифрования по порту: 465 => implicit TLS (ssl), 587 => STARTTLS (tls)
            // PHPMailer принимает строки 'ssl'/'tls' или константы ENCRYPTION_SMTPS/ENCRYPTION_STARTTLS
            $secure = strtolower((string)$this->mailer->SMTPSecure);
            if ($this->mailer->Port === 465 && $secure !== 'ssl') {
                $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // implicit TLS для 465
            } elseif ($this->mailer->Port === 587 && $secure !== 'tls') {
                $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // STARTTLS для 587
            }
            
            // Настройки таймаутов
            $this->mailer->Timeout = 30; // Общий таймаут
            $this->mailer->SMTPKeepAlive = false;
            $this->mailer->SMTPDebug = 0; // Отключаем отладку по умолчанию

            // Кодировка письма
            $this->mailer->CharSet = 'UTF-8';
            
            $this->mailer->setFrom(
                Environment::get('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
                Environment::get('MAIL_FROM_NAME', 'SP-Game')
            );
            
            $this->logger->info('Mailer initialized successfully', [
                'host' => $this->mailer->Host,
                'port' => $this->mailer->Port,
                'smtp_auth' => $this->mailer->SMTPAuth,
                'smtp_secure' => $this->mailer->SMTPSecure,
                'timeout' => $this->mailer->Timeout
            ]);
        } catch (Exception $e) {
            $this->logger->error('Mailer initialization failed: ' . $e->getMessage());
        }
    }

    public function send(string $to, string $subject, string $body, bool $isHtml = true): bool
    {
        try {
            $this->logger->info("Starting email send process", [
                'to' => $to,
                'subject' => $subject,
                'host' => $this->mailer->Host,
                'port' => $this->mailer->Port,
                'smtp_auth' => $this->mailer->SMTPAuth
            ]);

            $this->mailer->clearAddresses();
            $this->mailer->addAddress($to);
            $this->mailer->isHTML($isHtml);
            $this->mailer->Subject = $subject;
            $this->mailer->Body    = $body;

            $this->logger->info("Attempting to send email via SMTP");
            
            // Проверяем доступность SMTP сервера
            $this->logger->info("Testing SMTP connection to {$this->mailer->Host}:{$this->mailer->Port}");
            
            // Включаем отладку SMTP для диагностики
            /*$this->mailer->SMTPDebug = 2;
            $this->mailer->Debugoutput = function($str, $level) {
                $this->logger->info("SMTP Debug: " . trim($str));
            };*/
            
            $startTime = microtime(true);
            
            // Пробуем подключиться к SMTP серверу
            if (!$this->mailer->smtpConnect()) {
                throw new Exception("SMTP connection failed");
            }
            
            $this->logger->info("SMTP connection established successfully");
            $this->mailer->send();
            $this->mailer->smtpClose();
            
            $endTime = microtime(true);
            
            $this->logger->info("Email sent successfully to $to | Subject: $subject | Time: " . round(($endTime - $startTime) * 1000, 2) . "ms");
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to send email to $to", [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return false;
        }
    }

    /**
     * Отправка письма с 6-значным PIN-кодом для подтверждения e-mail
     */
    public function sendVerificationPin(string $to, string $login, string $pin): bool
    {
        $this->logger->info("Preparing verification PIN email", [
            'to' => $to,
            'login' => $login,
            'pin' => $pin
        ]);

        $subject = "Подтверждение e-mail для SP-Game";
        $body = "
            <p>Привет, <b>{$login}</b>!</p>
            <p>Спасибо за регистрацию в SP-Game.</p>
            <p>Ваш PIN-код для подтверждения e-mail: <b>{$pin}</b></p>
            <p>Введите этот код в форме подтверждения на сайте.</p>
            <p>Если вы не регистрировались, просто проигнорируйте это письмо.</p>
        ";

        $this->logger->info("Calling send method for verification PIN");
        $result = $this->send($to, $subject, $body, true);
        
        $this->logger->info("Verification PIN email result", ['success' => $result]);
        return $result;
    }

}
