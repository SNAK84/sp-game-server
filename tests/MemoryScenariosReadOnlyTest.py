#!/usr/bin/env python3
"""B047: расчёт в текущем dev-контейнере, без Table/init, игровых команд и SQL-write."""
import json
import math
import subprocess


def run(*arguments):
    return subprocess.run(['docker', 'exec', 'swoole_app', 'php', 'bin/memory-scenarios.php', *arguments],
                          text=True, capture_output=True, timeout=30)


def model(*arguments):
    result = run(*arguments)
    assert result.returncode == 0, result.stderr
    return json.loads(result.stdout)


def main():
    checks = 0

    def check(ok, label):
        nonlocal checks
        assert ok, label
        checks += 1

    base = model('--accounts=20000', '--online=1000')
    scene = base['scenarios'][0]
    demand = scene['estimatedSelectedRows']
    check(base['modelNotRss'] and not base['loadTestPerformed'], 'Расчёт не назван измерением нагрузки/RSS.')
    check(demand['FleetCommands'] == 480000, 'Учтены24 будущих и24 выполненных команды каждого активного флота.')
    check(scene['demandBreakdown']['futureCommands'] == 240000, 'Будущие команды выделены отдельно.')
    check(scene['demandBreakdown']['historyCommands'] == 240000, 'Выполненная история не потеряна.')
    check(demand['ProtocolCommands'] == 2881000, '2880000 terminal за24ч плюс1000 pending.')
    check(demand['ProtocolCommandsBodies'] == 2897000, '2880000 terminal-блоков плюс17000 резервных pending-блоков.')
    check(scene['demandBreakdown']['terminalReceipts'] == 2880000 and scene['demandBreakdown']['pendingReceipts'] == 1000,
          'Backlog не скрыт в суточной истории.')
    check(base['completionReservation'] == {'bytesPerPending': 16385, 'blocksPerPending': 17}, 'Физический резерв читается из констант.')
    check(demand['MessageBodiesOverflow'] == 175000, 'Четверть700000 писем по одному блоку.')
    check(demand['BattleReports'] == 34000, '14000 свежих плюс20000 отдельно сохранённых докладов.')
    check(demand['BattleReportsBodies'] == 272000, 'Каждый доклад8192B занимает8 блоков.')
    check(demand['BattleReportSaves'] == 40000, 'Две закладки на аккаунт.')
    check(demand['ProtocolSubscriptions'] == 8000, 'Контракт8 подписок на соединение прежний.')
    check(demand['PlayerQueue'] == 1000 and demand['PlayerQueuePayloads'] == 1000
          and demand['PlayerQueuePayloadAccounts'] == 1000, 'Ожидающие события и большие payload учтены отдельно.')
    check(scene['largePayloadAggregateQuotaFit'] and base['assumptions']['largePayloadsPerAccount'] == 4,
          'Агрегатная quota4 не потеряна и не подменяет вместимость.')
    check(base['assumptions']['targetMaxFill'] == 0.8, 'Целевой свободный резерв20%.')
    for profile in base['profiles']:
        for name, policy in base['profiles'][profile]['checkpointPolicies'].items():
            check(policy['rows'] == 128 and policy['syncRows'] == 256 and policy['maxBytes'] == 262144,
                  'Текущий бюджет checkpoint читается из схемы, не увеличен: ' + profile + '/' + name)
            check(policy['retainedUntilDeleteAck'] == (name == 'SpaceBattles'), 'Retained DELETE выделен отдельно: ' + name)
        mailbox = base['profiles'][profile]['workerMailboxPolicy']
        check(mailbox['reserveBeforeDispatch'] and not mailbox['lateReplyCreatesRow'], 'Mailbox публикуется только в резерв: ' + profile)
        check(mailbox['CombatMailbox'] == {'slots': 32, 'maxBytes': 262144}, 'Бюджет боевого mailbox прежний: ' + profile)
        check(mailbox['ExpeditionMailbox'] == {'slots': 16, 'maxBytes': 262144, 'workerLimit': 2}
              and mailbox['conflict'] == .2, 'Бюджет экспедиционного mailbox прежний: ' + profile)
        check(base['profiles'][profile]['playerQueuePayloadPolicy'] == {
            'slots': 8192 if profile == 'target' else 1024, 'maxBytes': 16384, 'perAccount': 4, 'ttlSeconds': 120},
              'Лимиты payload прочитаны из профиля: ' + profile)
        snapshot = base['profiles'][profile]['snapshotPolicy']
        check(snapshot['maxBytes'] >= 8 * 1048576 and snapshot['entryMaxBytes'] == 64 * 1048576,
              'Настоящий бюджет снимка и предел entry экспортированы: ' + profile)
        check(snapshot['sqlBatchRows'] == 100 and snapshot['format'] == 'packed-entry'
              and snapshot['budget'] == 'php-peak-delta-at-boundaries', 'SQL-партия и границы контроля памяти указаны явно.')
        budgets = base['profiles'][profile]['selectedBudgets']
        expected = {name: {'rows': count, 'budget': budgets[name]} for name, count in demand.items()
                    if count > budgets[name]}
        check(scene['profiles'][profile]['exceededBudgets'] == expected, 'Все превышения выведены точно: ' + profile)
        check(scene['profiles'][profile]['selectedBudgetsFit'] == (not expected), 'Нет ложного fit: ' + profile)
        reserve = {name: {'rows': count, 'budget': budgets[name], 'safeRows': math.floor(budgets[name] * .8)}
                   for name, count in demand.items() if count > math.floor(budgets[name] * .8)}
        check(scene['profiles'][profile]['insufficientReserveBudgets'] == reserve, 'Точно показаны дефициты резерва: ' + profile)
        check(scene['profiles'][profile]['selectedReserveFit'] == (not reserve), 'Заполнение и резерв — разные проверки: ' + profile)
        for name, minimum in (('ProtocolCommands', 512), ('BattleReports', 128)):
            policy = base['profiles'][profile]['cleanupPolicies'][name]
            check(policy['maxStepsPerCall'] == min(policy['capacity'], max(minimum, math.ceil(policy['capacity'] / 300))),
                  'Порция очистки соответствует реальному профилю: ' + profile + '/' + name)
            check(policy['idealFullPassCalls'] <= 300 and policy['budgetMs'] == 2,
                  'Порции ограничены временем, идеальный обход не более300 вызовов.')
            if profile == 'development':
                check(policy['maxStepsPerCall'] == minimum, 'Порция очистки development прежняя.')
    check(scene['profiles']['target']['selectedReserveFit'], 'Базовый20K/1K проходит выбранные target-бюджеты с резервом.')
    dev = base['profiles']['development']['selectedBudgets']
    for name, expected in {'FleetCommands': 16384, 'ProtocolCommands': 16384,
                           'ProtocolCommandsBodies': 16384, 'MessageBodiesOverflow': 1024,
                           'BattleReportsBodies': 32768, 'ProtocolSubscriptions': 8192,
                           'PlayerQueue': 16384, 'PlayerQueuePayloads': 1024, 'PlayerQueuePayloadAccounts': 1024}.items():
        check(dev[name] == expected, 'Development-бюджет не увеличен: ' + name)
    for history, reserve_ok in ((83, True), (84, False)):
        result = model('--accounts=20000', '--online=1000', '--commands-per-active=0',
                       '--history-commands-per-active=' + str(history))['scenarios'][0]['profiles']['target']
        check(result['selectedBudgetsFit'], '830000/840000 ещё помещаются в target.')
        check(result['selectedReserveFit'] == reserve_ok, '840000 уже нарушают резерв1М-таблицы.')

    inline = base['assumptions']['runtimeInlineBytes']
    check(inline == base['assumptions']['messageInlineBytes'], 'Порог сообщений и RuntimeRecords актуален.')
    for size, spills in ((inline, False), (inline + 1, True)):
        result = model('--accounts=20000', '--online=1000', '--receipt-packed-bytes=' + str(size),
                       '--message-overflow-bytes=' + str(size), '--report-packed-bytes=' + str(size))
        rows = result['scenarios'][0]['estimatedSelectedRows']
        check(rows['ProtocolCommandsBodies'] == (2880000 if spills else 0) + 17000, 'Inline terminal не отменяет резерв pending.')
        check(rows['MessageBodiesOverflow'] == (175000 if spills else 0), 'Граница inline почты.')
        check(rows['BattleReportsBodies'] == (34000 if spills else 0), 'Граница inline докладов.')

    rounded = model('--accounts=1', '--online=1', '--commands-per-online-minute=0.0001', '--messages-per-day=1',
                    '--pending-commands-per-online=0',
                    '--message-overflow-fraction=0.333', '--message-overflow-bytes=1025',
                    '--receipt-packed-bytes=1025', '--report-packed-bytes=1025',
                    '--reports-per-account-day=0.01', '--saved-reports-per-account=0', '--bookmarks-per-account=0')
    rows = rounded['scenarios'][0]['estimatedSelectedRows']
    check(rows['ProtocolCommands'] == 1 and rows['ProtocolCommandsBodies'] == 2, 'Округление сначала записей, затем блоков каждой записи.')
    check(rows['MessageBodiesOverflow'] == 6, 'Три письма1025B требуют6 блоков, не3/4.')
    check(rows['BattleReports'] == 1 and rows['BattleReportsBodies'] == 2, 'Дробная частота докладов округлена вверх.')

    pending = model('--accounts=20000', '--online=1000', '--commands-per-online-minute=0',
                    '--pending-commands-per-online=200')['scenarios'][0]
    check(pending['estimatedSelectedRows']['ProtocolCommandsBodies'] == 3400000, '200000 pending требуют3400000 блоков.')
    check('ProtocolCommandsBodies' not in pending['profiles']['target']['exceededBudgets']
          and 'ProtocolCommandsBodies' in pending['profiles']['target']['insufficientReserveBudgets'],
          'Квитанции помещаются, но честно нарушают20% резерв.')
    check(not pending['largePayloadAggregateQuotaFit'] and not pending['profiles']['target']['selectedBudgetsFit']
          and 'PlayerQueue' in pending['profiles']['target']['exceededBudgets'],
          '200000 ожидающих событий не объявляются пригодными только по вместимости квитанций.')
    quota = model('--accounts=20000', '--online=1000', '--pending-commands-per-online=4')['scenarios'][0]
    check(quota['estimatedSelectedRows']['PlayerQueuePayloads'] == 4000
          and quota['estimatedSelectedRows']['PlayerQueuePayloadAccounts'] == 1000, 'Полная quota1000 online требует4000 payload и1000 счётчиков.')
    check(quota['largePayloadAggregateQuotaFit'] and quota['profiles']['target']['selectedReserveFit'], 'Target сохраняет20% резерв при полной quota.')
    check('PlayerQueuePayloads' in quota['profiles']['development']['exceededBudgets'], 'Development не растёт до target молча.')
    small = model('--accounts=20000', '--online=1000', '--large-command-fraction=0')['scenarios'][0]
    check(small['estimatedSelectedRows']['PlayerQueue'] == 1000 and small['estimatedSelectedRows']['PlayerQueuePayloads'] == 0
          and small['estimatedSelectedRows']['PlayerQueuePayloadAccounts'] == 0, 'Мелкие события не занимают большой буфер.')

    zero = model('--accounts=0', '--online=0')
    check(all(n == 0 for n in zero['scenarios'][0]['estimatedSelectedRows'].values()), 'Пустой сценарий не создаёт спрос.')
    for profile in base['profiles']:
        check(zero['profiles'][profile]['sharedAllocationModelBytes'] == base['profiles'][profile]['sharedAllocationModelBytes'],
              'Выделение Table не зависит от заполнения: ' + profile)

    legacy = model('--accounts=20000', '--online=1000', '--active-fraction=0.02',
                   '--history-commands-per-active=0', '--commands-per-online-minute=0',
                   '--message-overflow-fraction=0', '--reports-per-account-day=0',
                   '--saved-reports-per-account=0', '--bookmarks-per-account=0')
    scene = legacy['scenarios'][0]
    check(scene['estimatedSelectedRows']['FleetCommands'] == 48000, 'Старый сценарий воспроизводится явными нулями.')
    check(scene['profiles']['target']['selectedBudgetsFit'], 'Fit относится только к выбранным бюджетам, не SLO.')

    for argument in ('--history-commands-per-active=-1', '--message-overflow-fraction=1.01', '--large-command-fraction=1.01',
                     '--command-hours-per-day=25', '--receipt-packed-bytes=256.5',
                     '--report-packed-bytes=0', '--commands-per-online-minute=NaN',
                     '--saved-reports-per-account=3', '--unknown=1'):
        result = run(argument)
        check(result.returncode == 1 and not result.stdout, 'Некорректный параметр не даёт частичный JSON: ' + argument)
        check('SELECT ' not in result.stderr and 'Stack trace' not in result.stderr, 'В ошибке нет SQL/конфигурации.')

    help_result = run('--help')
    check(help_result.returncode == 0 and '--receipt-packed-bytes' in help_result.stdout
          and '--large-command-fraction' in help_result.stdout, 'Справка содержит новые коэффициенты.')

    # Меняется только process-local env-map диагностики. Файл .env и профиль
    # работающего main не затрагиваются; preflight проверяет до первой Table.
    guard_code = r'''
require 'vendor/autoload.php'; SPGame\Core\Environment::init('.env');
$model=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR)['bytes'];
$physical=0;
if(preg_match('/MemTotal:\s+(\d+) kB/',file_get_contents('/proc/meminfo'),$m))$physical=(int)$m[1]*1024;
$limit=@file_get_contents('/sys/fs/cgroup/memory.max');
if(is_string($limit)&&ctype_digit(trim($limit)))$physical=$physical>0?min($physical,(int)trim($limit)):(int)trim($limit);
if($physical<=0||$model<=$physical*.7){echo json_encode(['checked'=>false,'physical'=>$physical]);exit;}
$property=new ReflectionProperty(SPGame\Core\Environment::class,'env');
$property->setValue(null,array_replace($property->getValue(),['SP_GAME_CAPACITY_PROFILE'=>'target']));
try{SPGame\Game\Repositories\RuntimeCapacity::preflight();throw new LogicException('Target guard не сработал.');}
catch(OverflowException $e){if(!str_contains($e->getMessage(),'модель Table'))throw $e;}
echo json_encode(['checked'=>true,'targetRejectedBeforeAllocation'=>true,'physical'=>$physical]);
'''
    result = subprocess.run(['docker', 'exec', '-i', 'swoole_app', 'php', '-r', guard_code],
                            input=json.dumps({'bytes': base['profiles']['target']['sharedAllocationModelBytes']}),
                            text=True, capture_output=True, timeout=30)
    check(result.returncode == 0, 'Проверка guard завершилась без аллокации target.')
    guard = json.loads(result.stdout)
    check(not guard['checked'] or guard['targetRejectedBeforeAllocation'], 'На малом хосте target запрещён до Table.')
    print('READ_ONLY_MEMORY_SCENARIOS_OK', json.dumps({'checks': checks, 'gameWorldCreated': False,
                                                    'profileChanged': False, 'loadTestPerformed': False,
                                                    'targetGuardChecked': guard['checked']}))


if __name__ == '__main__':
    main()
