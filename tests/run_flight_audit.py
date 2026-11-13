#!/usr/bin/env python3
"""Повторяемый аудит полётов: отдельные процессы/Tables, без игровых команд.

Запуск с хоста: python3 tests/run_flight_audit.py
SQL-проверки используют только TEMPORARY таблицы своего соединения.
tests/run.php проверяет текущие базовые контракты; любое падение — ошибка аудита.
Browser-проверки запускаются отдельно: они используют общую Selenium-сессию.
"""
import argparse
import concurrent.futures
import fnmatch
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time


ROOT = Path(__file__).resolve().parent.parent
HOST = """CoreContracts FleetStage3 FleetTransport FleetSpeed FleetFuelConsumption FleetLimits
FleetManagement FleetExperience FleetExperiencePersistence FleetSpy FleetRecycle
FleetColonization FleetAttack FleetBattle FleetCombat FleetCombatRounds
FleetCombatOptimization FleetCombatBalance FleetSpecialMissions FleetGroupInvitations
FleetExpedition FleetFreeSpace FleetPlan FleetSegmentCoordinates FleetPush
GalaxyFleetVisibility GalaxyPlayerStatus BattleReportPipeline CombatBonuses
DebrisOverview MoonFormation""".split()
NATIVE = """FleetColonizationStorage FleetFuelSwoole FleetAttackSwoole
FleetBattleSwoole FleetCombatFuelSwoole FleetDefendSwoole FleetExpeditionSwoole
FleetExpeditionClockSwoole FleetExpeditionVisibilitySwoole FleetFreeSpaceSwoole
FleetPlanSwoole FleetPlanAvailabilitySwoole FleetRecallSwoole FleetRecallTimingSwoole
FleetResumeCargoSwoole FleetResumeModesSwoole FleetRouteReturnLedgerSwoole
FleetRendezvousFuelSwoole FleetSpecialMissionsSwoole CombatBonusesSwoole
PlayerQueuePayloadsSwoole PlayerQueueSafetySwoole FleetExpeditionPersistence""".split()
NATIVE += ["FleetFlightAdversarialSwoole", "FleetFuelNoticeSwoole", "FleetTechnologySwoole",
           "FleetRemovedShipSwoole", "FleetRemovedShipMysql", "FleetLoadingSwoole", "FleetRouteProjectionSwoole", "FleetMergeSwoole"]
NATIVE += ["PhalanxSwoole"]
NATIVE += ["FleetSensorSwoole"]
NATIVE += ["FleetInterceptionSwoole"]
NATIVE += ["FleetSpaceBattleSwoole"]
NATIVE += ["SpaceBattlePersistenceMysql"]
NATIVE += ["FleetPointBoundsSwoole"]
EXPORTS = {
    "FleetAttackSwoole": ("FleetAttack", "--export-fixture"),
    "FleetFreeSpaceSwoole": ("FleetSpecialMissions", "--export-special"),
    "FleetSpecialMissionsSwoole": ("FleetSpecialMissions", "--export-special"),
}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--only", default="*", help="Glob имени набора без Test.php")
    parser.add_argument("--jobs", type=int, choices=(1, 2), default=1,
                        help="На стенде с 4 GiB RAM использовать 1: native fixtures выделяют большие Tables")
    parser.add_argument("--container", default="swoole_app")
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    output = args.output or Path(tempfile.mkdtemp(prefix="sp-game-flight-audit-"))
    output.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(output, 0o700)
    suites = [(name, False, []) for name in HOST] + [(name, True, []) for name in NATIVE]
    suites += [("FleetAttackSwoole", True, ["--moon"]),
               ("FleetSpecialMissionsSwoole", True, ["--mysql"]),
               ("FleetSpecialMissionsSwoole", True, ["--destroy-target", "--mysql"])]
    suites = [s for s in suites if fnmatch.fnmatch(s[0], args.only)]
    if not suites:
        parser.error("Ни один набор не выбран")
    print(f"ARTIFACTS {output}", flush=True)

    def run(suite):
        name, native, flags = suite
        label = name + "".join("_" + f.removeprefix("--") for f in flags)
        started = time.monotonic()
        # Host-процесс и экспорт fixture имеют отдельные Logger-артефакты.
        # В native CLI bootstrap выделит свой временный файл внутри контейнера.
        environment = dict(os.environ, SP_GAME_TEST_LOG_FILE=str((output / (label + ".runtime.log")).resolve()))
        command = (["docker", "exec", "-i", "-w", "/var/www/html", args.container,
                    "timeout", "--kill-after=5s", "175s", "php"]
                   if native else ["php"])
        command += ["tests/run.php" if name == "CoreContracts" else f"tests/{name}Test.php"]
        command += [f for f in flags if f != "--destroy-target"]
        stdin = None
        try:
            if name in EXPORTS:
                source, flag = EXPORTS[name]
                export_flags = ["--destroy-target"] if "--destroy-target" in flags else []
                fixture = subprocess.run(["php", f"tests/{source}Test.php", flag, *export_flags],
                                         cwd=ROOT, capture_output=True, timeout=120, check=True,
                                         env=dict(environment, SP_GAME_TEST_LOG_FILE=str((output / (label + ".export.runtime.log")).resolve())))
                json.loads(fixture.stdout)  # Не отправлять PHP notices вместо fixture.
                stdin = fixture.stdout
            result = subprocess.run(command, cwd=ROOT, input=stdin, capture_output=True, timeout=180, env=environment)
            log = (result.stdout + result.stderr).decode("utf-8", errors="replace")
            code = result.returncode
        except (subprocess.SubprocessError, ValueError, OSError) as error:
            code, log = -1, f"HARNESS ERROR {type(error).__name__}: {error}\n"
            if isinstance(error, subprocess.TimeoutExpired):
                log += ((error.stdout or b"") + (error.stderr or b"")).decode("utf-8", errors="replace")
        (output / (label + ".log")).write_text(log, encoding="utf-8")
        failures = [line for line in log.splitlines() if re.match(r"^(FAIL\b|PHP (?:Fatal|Parse) error)", line)]
        passed = code == 0 and not failures and "SKIP" not in log
        record = {"suite": label, "environment": "native" if native else "host",
                  "passed": passed, "exit_code": code, "seconds": round(time.monotonic()-started, 3),
                  "failures": failures,
                  "result_lines": [line for line in log.splitlines()
                                   if line.startswith("RESULT") or re.search(r"\d+ passed.*\d+ failed", line)],
                  "ok_lines": sum(bool(re.match(r"^(OK\b|PASS\b)", line)) for line in log.splitlines())}
        print(f"{'PASS' if passed else 'FAIL'} {label} ({record['seconds']}s)", flush=True)
        return record

    with concurrent.futures.ThreadPoolExecutor(max_workers=args.jobs) as pool:
        results = list(pool.map(run, suites))
    summary = {"suites": results, "passed": sum(r["passed"] for r in results),
               "failed": sum(not r["passed"] for r in results),
               "note": "ok_lines включают повторно запускаемые inherited fixtures; это не число уникальных сценариев."}
    (output / "results.json").write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"RESULT {summary['passed']} suites passed / {summary['failed']} suites failed; {output}")
    return int(any(not r["passed"] for r in results))


if __name__ == "__main__":
    raise SystemExit(main())
