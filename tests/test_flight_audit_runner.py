#!/usr/bin/env python3
"""Проверки строгого результата runner, без запуска игровых тестов/контейнера."""
import contextlib
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

import run_flight_audit as audit


class FlightAuditRunnerTest(unittest.TestCase):
    def run_result(self, code=0, log=b"PASS contract\nRESULT core contracts: 1 passed / 0 failed\n", error=None):
        with tempfile.TemporaryDirectory(prefix="sp-game-runner-test-") as directory:
            argv = ["run_flight_audit.py", "--only", "CoreContracts", "--output", directory]
            result = subprocess.CompletedProcess([], code, stdout=log, stderr=b"")
            with patch.object(sys, "argv", argv), contextlib.redirect_stdout(io.StringIO()), \
                    patch.object(audit.subprocess, "run", side_effect=error, return_value=result) as child:
                exit_code = audit.main()
            summary = json.loads((Path(directory) / "results.json").read_text(encoding="utf-8"))
            self.assertEqual(child.call_args.args[0], ["php", "tests/run.php"])
            self.assertEqual(child.call_args.kwargs["env"]["SP_GAME_TEST_LOG_FILE"],
                             str(Path(directory) / "CoreContracts.runtime.log"))
            self.assertEqual(len(summary["suites"]), 1)
            return exit_code, summary

    def test_current_contracts_success(self):
        code, result = self.run_result()
        self.assertEqual(code, 0)
        self.assertEqual(result["passed"], 1)
        self.assertEqual(result["failed"], 0)
        self.assertEqual(result["suites"][0]["suite"], "CoreContracts")

    def test_former_25_failures_are_not_an_allowed_baseline(self):
        code, result = self.run_result(code=1, log=b"5 passed, 25 failed\n")
        self.assertEqual(code, 1)
        self.assertEqual(result["failed"], 1)
        self.assertFalse(result["suites"][0]["passed"])

    def test_failure_line_even_with_zero_exit_is_failure(self):
        code, result = self.run_result(log=b"FAIL contract: injected failure\n")
        self.assertEqual(code, 1)
        self.assertEqual(result["suites"][0]["failures"], ["FAIL contract: injected failure"])

    def test_skip_is_not_pass(self):
        code, result = self.run_result(log=b"SKIP missing dependency\n")
        self.assertEqual(code, 1)
        self.assertFalse(result["suites"][0]["passed"])

    def test_timeout_is_failure_with_partial_output(self):
        error = subprocess.TimeoutExpired(["php", "tests/run.php"], 180, output=b"PASS partial\n")
        code, result = self.run_result(error=error)
        self.assertEqual(code, 1)
        self.assertEqual(result["suites"][0]["exit_code"], -1)
        self.assertEqual(result["suites"][0]["ok_lines"], 1)

    def test_nonzero_exit_is_failure_even_without_fail_line(self):
        code, result = self.run_result(code=2, log=b"unexpected termination\n")
        self.assertEqual(code, 1)
        self.assertFalse(result["suites"][0]["passed"])

    def test_export_has_separate_runtime_log_and_keeps_json_input(self):
        with tempfile.TemporaryDirectory(prefix="sp-game-runner-export-") as directory:
            argv = ["run_flight_audit.py", "--only", "FleetFreeSpaceSwoole", "--output", directory]
            results = [subprocess.CompletedProcess([], 0, stdout=b"{}", stderr=b""),
                       subprocess.CompletedProcess([], 0, stdout=b"PASS native\n", stderr=b"")]
            with patch.object(sys, "argv", argv), contextlib.redirect_stdout(io.StringIO()), \
                    patch.object(audit.subprocess, "run", side_effect=results) as child:
                self.assertEqual(audit.main(), 0)
            export, native = child.call_args_list
            self.assertEqual(export.kwargs["env"]["SP_GAME_TEST_LOG_FILE"],
                             str(Path(directory) / "FleetFreeSpaceSwoole.export.runtime.log"))
            self.assertEqual(native.kwargs["env"]["SP_GAME_TEST_LOG_FILE"],
                             str(Path(directory) / "FleetFreeSpaceSwoole.runtime.log"))
            self.assertEqual(native.kwargs["input"], b"{}")
            # Host-артефакт не передаётся как путь внутри другого filesystem контейнера.
            self.assertNotIn("-e", native.args[0])


if __name__ == "__main__":
    unittest.main()
