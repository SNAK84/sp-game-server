<?php

namespace SPGame\Game\Services;

use SPGame\Core\Connect;
use SPGame\Core\Logger;
use SPGame\Core\Message;
use SPGame\Core\WSocket;
use SPGame\Core\Environment;
use SPGame\Game\Repositories\Accounts;
use SPGame\Game\Repositories\Users;

/** Игровой мир не читает БД кабинета и не получает пароль игрока. */
final class CabinetWorldService
{
    private static ?array $config=null;

    private static function config(): array
    {
        if (self::$config===null) {
            $path=dirname(__DIR__,3).'/.secrets/cabinet-world.json';
            self::$config=is_file($path)?json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR):['enabled'=>false];
        }
        return self::$config;
    }

    public static function enabled(): bool { return (self::config()['enabled']??false)===true; }

    public static function worldId(): string { return (string)(self::config()['worldId']??''); }

    private static function call(string $operation,array $data): array
    {
        $config=self::config();
        $body=json_encode($data,JSON_THROW_ON_ERROR);
        $context=stream_context_create(['http'=>['method'=>'POST','timeout'=>2,'ignore_errors'=>true,'follow_location'=>0,
            'header'=>"Content-Type: application/json\r\nAuthorization: World ".$config['serviceKey']."\r\nX-World-ID: ".$config['worldId'],
            'content'=>$body],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $apiUrl = Environment::get('CABINET_WORLD_INTERNAL_URL', $config['apiUrl']);
        if (!in_array(parse_url($apiUrl, PHP_URL_SCHEME), ['http', 'https'], true)) throw new \RuntimeException('cabinet_url_invalid');
        $raw=@file_get_contents(rtrim($apiUrl, '/').'/api/v1/internal/game/'.$operation,false,$context);
        if ($raw===false) throw new \RuntimeException('cabinet_unavailable');
        $result=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (($result['status']??'')!=='ok') throw new \RuntimeException(($result['error']['code']??'')==='session_replaced'?'session_replaced':'cabinet_unavailable');
        return $result['data'];
    }

    public static function authorize(string $token,int $fd,WSocket $socket): array
    {
        if (!preg_match('/^[a-f0-9]{128}$/D',$token)) throw new \RuntimeException('session_replaced');
        $identity=self::call('validate',['tokens'=>[$token]])['valid'][0]??null;
        if (!is_array($identity)) throw new \RuntimeException('session_replaced');
        $session=['accountId'=>$identity['accountId'],'sessionId'=>$identity['sessionId'],'playerId'=>$identity['playerId']];
        $current=Accounts::findByToken($token);
        if ($current && $current['cabinet_id']===$identity['accountId'] && (int)$current['frame']===$fd && Connect::getRawAccount($fd)===(int)$current['id']) {
            Connect::markCabinetSession($fd);
            return ['success'=>true,'id'=>(int)$current['id'],'login'=>$current['login'],'token'=>$token,'verify_email'=>false]+$session;
        }
        return \SPGame\Game\Repositories\RepositoryGuard::run('cabinet-player-'.$identity['accountId'], static function () use ($identity, $token, $fd, $socket, $session): array {
            $result=\SPGame\Game\Repositories\MiningCheckpoints::worldLocked(
                fn() => self::attach($identity,$token,$fd,$socket), true);
            $session['playerId']=(int)(Users::findByIndex('account',(int)$result['id'])['id']??0);
            return $result+$session;
        });
    }

    private static function attach(array $identity,string $token,int $fd,WSocket $socket): array
    {
        $account=Accounts::findByCabinetId($identity['accountId']);
        if ($account===null && $identity['localAccountId']!==null) {
            $account=Accounts::findExistingById((int)$identity['localAccountId']);
            if ($account!==null && (($account['cabinet_id']??'')!=='' && $account['cabinet_id']!==$identity['accountId'])) {
                throw new \RuntimeException('player_binding_conflict');
            }
        }
        if ($account===null) {
            $key=str_replace('-','',$identity['accountId']);
            $new = ['login'=>'cab_'.$key,'email'=>$key.'@cabinet.invalid','password'=>'',
                'cabinet_id'=>$identity['accountId'],'verify_email'=>1,'lang'=>$identity['language'],
                'reg_ip'=>Connect::getIp($fd),'last_ip'=>Connect::getIp($fd)];
            // Кабинет мог подтвердить первую привязку после последнего снимка.
            // После crash воссоздаём те же зарезервированные identity ID, не чужие данные.
            if ($identity['localAccountId'] !== null) $new['id'] = (int)$identity['localAccountId'];
            $account=Accounts::castRowToSchema($new,true);
            $boundUser = $identity['playerId'] !== null ? Users::findExistingById((int)$identity['playerId']) : null;
            if ($boundUser !== null && (int)$boundUser['account_id'] !== (int)$account['id']) throw new \RuntimeException('player_binding_conflict');
            Accounts::add($account);
            if ($identity['playerId'] !== null && Users::findByIndex('account', (int)$account['id']) === null) {
                try { Users::add(['id' => (int)$identity['playerId'], 'account_id' => (int)$account['id'], 'name' => 'Player' . $account['id']]); }
                catch (\Throwable $error) { Accounts::discardUncommittedRow($account); throw $error; }
            }
        }
        if (($account['cabinet_id']??'')!==$identity['accountId']) {
            $account['cabinet_id']=$identity['accountId'];
            $account['verify_email']=1;
            Accounts::update($account);
        }
        Accounts::persistCabinetIdentity($account);
        $oldFd=(int)($account['frame']??0);
        if ($oldFd>0 && $oldFd!==$fd && Connect::getRawAccount($oldFd)===(int)$account['id']) {
            self::detach($oldFd,'session_replaced',$socket);
        }
        $beforeUser=Users::findByIndex('account',(int)$account['id']);
        // Используем прежний путь рождения игрока/домашней планеты, не собственный генератор.
        $data=new AccountData((int)$account['id']);
        $user=$data['User']->toArray();
        if ($identity['playerId']!==null && $identity['playerId']!==(int)$user['id']) throw new \RuntimeException('player_binding_conflict');
        if ($identity['playerId'] === null || $identity['localAccountId'] !== (int)$account['id'] || $beforeUser === null) {
            self::call('bind',['token'=>$token,'localAccountId'=>(int)$account['id'],'playerId'=>(int)$user['id']]);
        }
        $account['token']=$token;
        $account['frame']=$fd;
        $account['last_time']=time();
        $account['last_ip']=Connect::getIp($fd);
        $account['lang']=$identity['language'];
        Accounts::update($account);
        Connect::setAccount($fd,(int)$account['id']);
        Connect::markCabinetSession($fd);
        return ['success'=>true,'id'=>(int)$account['id'],'login'=>$account['login'],'token'=>$token,'verify_email'=>false];
    }

    public static function revoke(string $token): void { self::call('revoke',['token'=>$token]); }

    /** Выполняется в HTTP/WS worker, не в процессе игрового расчёта. */
    public static function poll(WSocket $socket): void
    {
        $fds=Connect::getAllFds();
        $tokens=[];
        foreach ($fds as $fd) {
            $id=Connect::getRawAccount($fd);
            $account=$id?Accounts::findExistingById($id):null;
            if ($account && (int)$account['frame']===$fd && $account['token']!=='') $tokens[$fd]=$account['token'];
        }
        foreach (array_chunk($tokens,256,true) as $chunk) {
            try { $valid=self::call('validate',['tokens'=>array_values($chunk)])['valid']; }
            catch (\Throwable $error) {
                // Lease истечёт; новые команды уже закрыты центральной проверкой.
                Logger::getInstance()->warning('Кабинет недоступен при проверке игровых сессий');
                return;
            }
            $hashes=array_column($valid,'tokenHash');
            foreach ($chunk as $fd=>$token) {
                $account=Accounts::findExistingById(Connect::getRawAccount($fd)??0);
                if (!$account || $account['token']!==$token || (int)$account['frame']!==$fd) continue;
                if (in_array(hash('sha256',$token),$hashes,true)) Connect::markCabinetSession($fd);
                else self::detach($fd,'session_replaced',$socket);
            }
        }
    }

    public static function detach(int $fd,string $code,WSocket $socket): void
    {
        $id=Connect::getRawAccount($fd);
        Connect::setAccount($fd,null);
        if ($id) {
            self::clearClosedFrame($id, $fd);
        }
        $socket->Send($fd,(new Message())->setMode('connect')->setAction('sessionReplaced')->setError($code,
            'Игровая сессия завершена. Войдите в выбранный мир через кабинет.'));
        $socket->closeSession($fd);
    }

    /** Смена frame сериализована с сохранением AccountData игрового процесса. */
    public static function clearClosedFrame(int $accountId, int $fd, int $attempt = 0): void
    {
        try {
            \SPGame\Game\Repositories\MiningCheckpoints::worldLocked(static function () use ($accountId, $fd): void {
                // FD мог быть повторно использован после закрытия старого соединения.
                if (Connect::getRawAccount($fd) === $accountId) return;
                $account = Accounts::findExistingById($accountId);
                if ($account !== null && (int)$account['frame'] === $fd) Accounts::update(['id' => $accountId, 'frame' => 0]);
            }, true);
        } catch (\Throwable $error) {
            if ($error->getCode() === \SPGame\Game\Repositories\MiningCheckpoints::LOCK_BUSY && $attempt < 40) {
                \Swoole\Timer::after(250, fn() => self::clearClosedFrame($accountId, $fd, $attempt + 1));
                return;
            }
            Logger::getInstance()->warning('Очистка frame отложена до следующего входа', ['accountId' => $accountId, 'fd' => $fd]);
        }
    }
}
