<?php

namespace Game;

use Swoole\WebSocket\Frame;

class Account
{
    private int $id;
    private string $login;
    private string $email;
    private string $password;
    private ?string $token = null;
    private bool $verifyEmail = false;
    private ?int $lastSendVerifyMail = null;
    private int $level = 5;
    private int $regTime;
    private int $lastTime;
    private string $regIp;
    private string $lastIp;
    private int $credit = 0;
    private string $lang = 'ru';

    /** @var Frame|null WebSocket Frame для отправки сообщений */
    private ?Frame $frame = null;

    /** @var Player[] Массив игроков, привязанных к аккаунту */
    private array $players = [];

    public function __construct(array $data)
    {
        $this->id = (int)$data['id'];
        $this->login = $data['login'];
        $this->email = $data['email'] ?? '';
        $this->password = $data['password'];
        $this->token = $data['token'] ?? null;
        $this->verifyEmail = !empty($data['verify_email']);
        $this->lastSendVerifyMail = $data['last_send_verify_mail'] ?? null;
        $this->level = (int)($data['level'] ?? 5);
        $this->regTime = (int)$data['reg_time'];
        $this->lastTime = (int)$data['last_time'];
        $this->regIp = $data['reg_ip'] ?? '';
        $this->lastIp = $data['last_ip'] ?? '';
        $this->credit = (int)($data['credit'] ?? 0);
        $this->lang = $data['lang'] ?? 'ru';
    }

    
    // =============================
    // Frame
    // =============================

    public function setFrame(Frame $frame): void
    {
        $this->frame = $frame;
    }

    public function getFrame(): ?Frame
    {
        return $this->frame;
    }


    // =============================
    // Getters / Setters
    // =============================

    public function getId(): int
    {
        return $this->id;
    }
    public function getLogin(): string
    {
        return $this->login;
    }
    public function getEmail(): string
    {
        return $this->email;
    }
    public function getPassword(): string
    {
        return $this->password;
    }
    public function getToken(): ?string
    {
        return $this->token;
    }
    public function setToken(?string $token): void
    {
        $this->token = $token;
    }
    public function isVerifyEmail(): bool
    {
        return $this->verifyEmail;
    }
    public function getLastSendVerifyMail(): ?int
    {
        return $this->lastSendVerifyMail;
    }
    public function getLevel(): int
    {
        return $this->level;
    }
    public function getRegTime(): int
    {
        return $this->regTime;
    }
    public function getLastTime(): int
    {
        return $this->lastTime;
    }
    public function getRegIp(): string
    {
        return $this->regIp;
    }
    public function getLastIp(): string
    {
        return $this->lastIp;
    }
    public function getCredit(): int
    {
        return $this->credit;
    }
    public function getLang(): string
    {
        return $this->lang;
    }

    // =============================
    // Players
    // =============================

    /*    public function addPlayer(Player $player): void
    {
        $this->players[$player->getId()] = $player;
    }*/

    /**
     * @return Player[]
     */
    public function getPlayers(): array
    {
        return $this->players;
    }
}
