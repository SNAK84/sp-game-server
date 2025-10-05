<?php

declare(strict_types=1);

namespace SPGame\Core;

use SPGame\Core\Input;

/*
{
  "status": "ok|error",  // только для ответов
  "requestId": "string", // уникальный идентификатор запроса
  "token": "string (128 chars)",
  "mode": "connect | build | research | fleet | chat | system",
  "action": "list | start | cancel | move | send | message",
  "data": {
    "...": "зависит от mode/action"
  }
  "error": {             // если status = "error"
    "code": "string",
    "message": "string"
  }
}
*/

class Message
{
    private string $status = "ok";     // "ok|error"
    private ?string $requestId = null;
    private ?string $token = null;
    private ?string $mode = null;
    private ?string $action = null;
    private ?string $lang = null;
    private float $serverTime = 0.0;
    private array $data = [];
    private array $error = [
        "code" => "",
        "message" => ""
    ];

    public function __construct(?array $raw = null)
    {

        if ($raw) {

            $this->status = Input::get($raw, 'status', '');
            $this->requestId = Input::get($raw, 'requestId', '');
            $this->token = Input::get($raw, 'token', '');
            $this->mode = Input::get($raw, 'mode', '');
            $this->action = Input::get($raw, 'action', '');
            $this->lang = Input::get($raw, 'lang', '');
            $this->data = $raw['data'] ?? [];
        } else {
            $this->requestId = $this->generateRequestId();
        }
    }

    private function generateRequestId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function setRequestId(string $requestId)
    {
        $this->requestId = $requestId;
        return $this;
    }
    public function setToken(string $token)
    {
        $this->token = $token;
        return $this;
    }
    public function setMode(string $mode)
    {
        $this->mode = $mode;
        return $this;
    }
    public function setAction(string $action)
    {
        $this->action = $action;
        return $this;
    }
    public function setlang(string $lang)
    {
        $this->lang = $lang;
        return $this;
    }
    public function setServerTime(float $serverTime)
    {
        $this->serverTime = $serverTime;
        return $this;
    }
    public function setData($key, $data)
    {
        $this->data[$key] = $data;
        return $this;
    }

    public function setError(string $code, string $message)
    {
        $this->status = "error";
        $this->error["code"] = $code;
        $this->error["message"] = $message;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
    public function getMode(): string
    {
        return $this->mode;
    }
    public function getAction(): string
    {
        return $this->action ?? "";
    }
    public function getLang(): string
    {
        return $this->lang;
    }
    public function getToken(): string
    {
        return $this->token;
    }
    public function getRequestId()
    {
        return $this->requestId ?? $this->generateRequestId();
    }

    public function getData(string $key, mixed $default = null): mixed
    {
        return Input::get($this->data, $key, $default);
    }

    public function cleatData(): void
    {
        $this->data = [];
    }

    public function source()
    {
        $source = [
            'status' => $this->status,
            'requestId' => $this->requestId
        ];
        if ($this->token) $source['token'] = $this->token;
        if ($this->mode) $source['mode'] = $this->mode;
        if ($this->action) $source['action'] = $this->action;
        if ($this->lang) $source['lang'] = $this->lang;
        $source['serverTime'] = microtime(true);
        //if (!empty($this->data)) 
        $source['data'] = $this->data;

        if ($this->status === "error") {
            $source['error'] = $this->error;
        }

        return $source;
    }
}
