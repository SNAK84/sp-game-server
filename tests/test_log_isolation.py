#!/usr/bin/env python3
"""T002: настоящий Logger в коротких PHP-процессах, без игры/БД и реальных логов."""
import hashlib
import itertools
import json
from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parent.parent
PROBE = r"""
require 'vendor/autoload.php';
$args=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
putenv('SP_GAME_TEST_LOG_FILE');
SPGame\Core\Environment::init($args['env']);
if($args['warm']) SPGame\Core\Logger::getInstance();
if($args['explicit']) putenv('SP_GAME_TEST_LOG_FILE='.$args['runtime']);
if($args['bootstrap']) require 'tests/bootstrap.php';
$logger=SPGame\Core\Logger::getInstance();
$logger->error('T002 first marker');
SPGame\Core\Environment::init($args['reloadEnv']);
$logger->warning('T002 second marker');
echo json_encode(['file'=>(new ReflectionProperty($logger,'logFile'))->getValue($logger),
    'override'=>getenv('SP_GAME_TEST_LOG_FILE')]);
"""


class TestLogIsolation(unittest.TestCase):
    def run_probe(self, root, rotation, explicit, warm, bootstrap=True, echo='false'):
        game = root / 'game'
        runtime = root / 'runtime'
        game.mkdir()
        runtime.mkdir()
        envs = []
        for name in ['game', 'reload']:
            log = game / (name + '.log')
            log.write_text('T002 untouched sentinel\n', encoding='utf-8')
            env = root / (name + '.env')
            env.write_text(f'LOG_FILE={log}\nLOG_ECHO={echo}\nLOG_LEVEL=1\nLOG_ROTATION_MODE={rotation}\nLOG_MAX_SIZE=1\n', encoding='utf-8')
            envs.append(str(env))
        before = {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in game.iterdir()}
        args = {'env': envs[0], 'reloadEnv': envs[1], 'runtime': str(runtime / 'test.log'),
                'explicit': explicit, 'warm': warm, 'bootstrap': bootstrap}
        result = subprocess.run(['php', '-d', 'sys_temp_dir=' + str(runtime), '-r', PROBE],
                                input=json.dumps(args), text=True, cwd=ROOT, capture_output=True, timeout=15)
        self.assertEqual(result.returncode, 0, result.stderr)
        state = json.loads(result.stdout)
        after = {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in game.iterdir()}
        return runtime, before, after, state

    def test_bootstrap_isolates_cold_warm_reload_and_rotation(self):
        cases = itertools.product(['none', 'daily', 'size'], [False, True], [False, True], ['false', '0'])
        for rotation, explicit, warm, echo in cases:
            with self.subTest(rotation=rotation, explicit=explicit, warm=warm, echo=echo), \
                    tempfile.TemporaryDirectory(prefix='sp-game-log-test-') as directory:
                runtime, before, after, state = self.run_probe(Path(directory), rotation, explicit, warm, echo=echo)
                self.assertEqual(before, after, 'Тест изменил файл/ротации игрового surrogate-log')
                self.assertTrue(Path(state['file']).is_relative_to(runtime))
                self.assertTrue(Path(state['override']).is_relative_to(runtime))
                content = '\n'.join(p.read_text(encoding='utf-8') for p in runtime.iterdir())
                self.assertIn('T002 first marker', content)
                self.assertIn('T002 second marker', content)

    def test_no_override_preserves_regular_logger(self):
        with tempfile.TemporaryDirectory(prefix='sp-game-log-control-') as directory:
            root = Path(directory)
            runtime, before, after, state = self.run_probe(root, 'none', False, False, bootstrap=False)
            self.assertNotEqual(before, after)
            self.assertFalse(state['override'])
            self.assertEqual(Path(state['file']), root / 'game' / 'reload.log')
            self.assertEqual(list(runtime.iterdir()), [])
            self.assertIn('T002 first marker', (root / 'game' / 'game.log').read_text())
            self.assertIn('T002 second marker', (root / 'game' / 'reload.log').read_text())


if __name__ == '__main__':
    unittest.main()
