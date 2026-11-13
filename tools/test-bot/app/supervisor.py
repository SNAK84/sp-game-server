from __future__ import annotations

import os
import signal
import subprocess
import sys
import time
import urllib.error
import urllib.request


def _positive_float(name: str, default: float) -> float:
    try:
        return max(0.1, float(os.getenv(name, str(default))))
    except ValueError:
        return default


def _positive_int(name: str, default: int) -> int:
    try:
        return max(1, int(os.getenv(name, str(default))))
    except ValueError:
        return default


def health_ok(port: int, token: str, timeout: float) -> bool:
    request = urllib.request.Request(
        f"http://127.0.0.1:{port}/health",
        headers={"X-Bot-Token": token},
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return response.status == 200
    except (OSError, urllib.error.URLError):
        return False


def stop_child(child: subprocess.Popen[bytes], grace_seconds: float) -> None:
    if child.poll() is not None:
        return
    child.terminate()
    try:
        child.wait(timeout=grace_seconds)
    except subprocess.TimeoutExpired:
        child.kill()
        child.wait()


def main() -> int:
    port = _positive_int("BOT_API_PORT", 8095)
    token = os.getenv("BOT_API_TOKEN", "")
    interval = _positive_float("BOT_SUPERVISOR_INTERVAL_SECONDS", 15)
    startup_seconds = _positive_float("BOT_SUPERVISOR_STARTUP_SECONDS", 45)
    failures_before_restart = _positive_int("BOT_SUPERVISOR_FAILURES", 3)
    terminate_grace = _positive_float("BOT_SUPERVISOR_TERMINATE_SECONDS", 25)

    child = subprocess.Popen([sys.executable, "-m", "app.main"])
    stopping = False

    def forward_signal(signum: int, _frame: object) -> None:
        nonlocal stopping
        stopping = True
        if child.poll() is None:
            child.send_signal(signum)

    signal.signal(signal.SIGTERM, forward_signal)
    signal.signal(signal.SIGINT, forward_signal)

    startup_deadline = time.monotonic() + startup_seconds
    failures = 0
    while True:
        return_code = child.poll()
        if return_code is not None:
            return return_code
        if stopping:
            return child.wait()

        if time.monotonic() >= startup_deadline:
            if health_ok(port, token, timeout=min(5.0, interval)):
                failures = 0
            else:
                failures += 1
                print(
                    f"BOT SUPERVISOR: health probe failed ({failures}/{failures_before_restart})",
                    flush=True,
                )
                if failures >= failures_before_restart:
                    print("BOT SUPERVISOR: restarting unresponsive bot process", flush=True)
                    stop_child(child, terminate_grace)
                    return 1
        time.sleep(interval)


if __name__ == "__main__":
    raise SystemExit(main())
