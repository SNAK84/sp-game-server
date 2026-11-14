#!/usr/bin/env python3
"""B047: расчёт в текущем dev-контейнере, без Table/init, игровых команд и SQL-write."""
import json
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
    check(demand['ProtocolCommands'] == 2880000, '24 часа по2 команды/минуту на1000 онлайн.')
    check(demand['ProtocolCommandsBodies'] == demand['ProtocolCommands'], 'Каждая packed-квитанция384B занимает один блок.')
    check(demand['MessageBodiesOverflow'] == 175000, 'Четверть700000 писем по одному блоку.')
    check(demand['BattleReports'] == 34000, '14000 свежих плюс20000 отдельно сохранённых докладов.')
    check(demand['BattleReportsBodies'] == 272000, 'Каждый доклад8192B занимает8 блоков.')
    check(demand['BattleReportSaves'] == 40000, 'Две закладки на аккаунт.')
    check(demand['ProtocolSubscriptions'] == 8000, 'Контракт8 подписок на соединение прежний.')
    for profile in base['profiles']:
        budgets = base['profiles'][profile]['selectedBudgets']
        expected = {name: {'rows': count, 'budget': budgets[name]} for name, count in demand.items()
                    if count > budgets[name]}
        check(scene['profiles'][profile]['exceededBudgets'] == expected, 'Все превышения выведены точно: ' + profile)
        check(scene['profiles'][profile]['selectedBudgetsFit'] == (not expected), 'Нет ложного fit: ' + profile)

    inline = base['assumptions']['runtimeInlineBytes']
    check(inline == base['assumptions']['messageInlineBytes'], 'Порог сообщений и RuntimeRecords актуален.')
    for size, spills in ((inline, False), (inline + 1, True)):
        result = model('--accounts=20000', '--online=1000', '--receipt-packed-bytes=' + str(size),
                       '--message-overflow-bytes=' + str(size), '--report-packed-bytes=' + str(size))
        rows = result['scenarios'][0]['estimatedSelectedRows']
        check(rows['ProtocolCommandsBodies'] == (2880000 if spills else 0), 'Граница inline квитанций.')
        check(rows['MessageBodiesOverflow'] == (175000 if spills else 0), 'Граница inline почты.')
        check(rows['BattleReportsBodies'] == (34000 if spills else 0), 'Граница inline докладов.')

    rounded = model('--accounts=1', '--online=1', '--commands-per-online-minute=0.0001', '--messages-per-day=1',
                    '--message-overflow-fraction=0.333', '--message-overflow-bytes=1025',
                    '--receipt-packed-bytes=1025', '--report-packed-bytes=1025',
                    '--reports-per-account-day=0.01', '--saved-reports-per-account=0', '--bookmarks-per-account=0')
    rows = rounded['scenarios'][0]['estimatedSelectedRows']
    check(rows['ProtocolCommands'] == 1 and rows['ProtocolCommandsBodies'] == 2, 'Округление сначала записей, затем блоков каждой записи.')
    check(rows['MessageBodiesOverflow'] == 6, 'Три письма1025B требуют6 блоков, не3/4.')
    check(rows['BattleReports'] == 1 and rows['BattleReportsBodies'] == 2, 'Дробная частота докладов округлена вверх.')

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

    for argument in ('--history-commands-per-active=-1', '--message-overflow-fraction=1.01',
                     '--command-hours-per-day=25', '--receipt-packed-bytes=256.5',
                     '--report-packed-bytes=0', '--commands-per-online-minute=NaN',
                     '--saved-reports-per-account=3', '--unknown=1'):
        result = run(argument)
        check(result.returncode == 1 and not result.stdout, 'Некорректный параметр не даёт частичный JSON: ' + argument)
        check('SELECT ' not in result.stderr and 'Stack trace' not in result.stderr, 'В ошибке нет SQL/конфигурации.')

    help_result = run('--help')
    check(help_result.returncode == 0 and '--receipt-packed-bytes' in help_result.stdout, 'Справка содержит новые коэффициенты.')
    print('READ_ONLY_MEMORY_SCENARIOS_OK', json.dumps({'checks': checks, 'gameWorldCreated': False,
                                                    'profileChanged': False, 'loadTestPerformed': False}))


if __name__ == '__main__':
    main()
