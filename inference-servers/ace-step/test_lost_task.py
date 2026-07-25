"""Regression tests for the ace-step wrapper's handling of a lost task.

When the ACE-Step API server restarts it loses its in-memory job store. It still
answers /query_result for the now-unknown task_id, but with status 0 (queued/running)
and an empty result ("[]") — so without detection the wrapper would poll uselessly
until its timeout. These tests pin the fast-fail behaviour plus tolerance of a brief
connection blip during a restart.
"""

import importlib
import os
import sys
import types
import unittest
import urllib.error

HERE = os.path.dirname(os.path.abspath(__file__))


def _import_main():
    # Stub Flask: main.py only needs Flask/request/jsonify to define its routes; the
    # functions under test (wait_for_task, result_is_empty) don't touch the HTTP layer.
    if 'flask' not in sys.modules:
        flask_stub = types.ModuleType('flask')

        class _Flask:
            def __init__(self, *args, **kwargs):
                pass

            def route(self, *args, **kwargs):
                def decorator(fn):
                    return fn
                return decorator

            def run(self, *args, **kwargs):
                pass

        flask_stub.Flask = _Flask
        flask_stub.request = types.SimpleNamespace(json=None)
        flask_stub.jsonify = lambda *a, **k: {}
        sys.modules['flask'] = flask_stub

    sys.path.insert(0, HERE)
    old_argv = sys.argv
    sys.argv = ['main.py', '--port', '0']
    sys.modules.pop('main', None)
    try:
        return importlib.import_module('main')
    finally:
        sys.argv = old_argv


class ResultIsEmptyTest(unittest.TestCase):
    def setUp(self):
        self.main = _import_main()

    def test_empty_list_string_is_empty(self):
        self.assertTrue(self.main.result_is_empty('[]'))

    def test_populated_list_string_is_not_empty(self):
        self.assertFalse(self.main.result_is_empty('[{"file": "/a.wav"}]'))

    def test_none_is_empty(self):
        self.assertTrue(self.main.result_is_empty(None))

    def test_blank_string_is_empty(self):
        self.assertTrue(self.main.result_is_empty('   '))

    def test_already_parsed_empty_list_is_empty(self):
        self.assertTrue(self.main.result_is_empty([]))

    def test_non_json_string_is_not_empty(self):
        # A non-empty, non-JSON string is treated as payload (not a ghost marker).
        self.assertFalse(self.main.result_is_empty('something'))


class WaitForTaskTest(unittest.TestCase):
    def setUp(self):
        self.main = _import_main()
        # Keep the tests fast and deterministic without patching the shared time module.
        self.main.args.timeout = 5
        self.main.args.poll_interval = 0.001

    def _set_responses(self, responses):
        it = iter(responses)

        def fake_query_result(task_id):
            resp = next(it)
            if isinstance(resp, BaseException):
                raise resp
            return dict(resp, task_id=task_id)

        self.main.query_result = fake_query_result

    def test_lost_task_fails_fast_instead_of_polling_until_timeout(self):
        # After a restart the server answers every poll with status 0 + empty result.
        self._set_responses([{'status': 0, 'result': '[]'}] * 100)
        with self.assertRaises(RuntimeError) as ctx:
            self.main.wait_for_task('deadbeef')
        self.assertIn('unknown to the ACE-Step API server', str(ctx.exception))

    def test_queued_with_payload_keeps_polling_then_succeeds(self):
        # A genuine queued task carries a populated result and must not be mistaken
        # for a ghost.
        self._set_responses([
            {'status': 0, 'result': '[{"progress": 0.0}]'},
            {'status': 0, 'result': '[{"progress": 0.5}]'},
            {'status': 1, 'result': '[{"file": "/out.wav"}]'},
        ])
        item = self.main.wait_for_task('abc')
        self.assertEqual(item['file'], '/out.wav')

    def test_failed_status_raises(self):
        self._set_responses([{'status': 2, 'result': '[{"error": "boom"}]'}])
        with self.assertRaises(RuntimeError):
            self.main.wait_for_task('abc')

    def test_transient_connection_error_then_lost_task_is_detected(self):
        # While the server restarts the polls fail with a connection error (URLError),
        # then the server comes back but no longer knows our task_id.
        self._set_responses([
            urllib.error.URLError('Connection refused'),
            urllib.error.URLError('Connection refused'),
            {'status': 0, 'result': '[]'},
        ])
        with self.assertRaises(RuntimeError) as ctx:
            self.main.wait_for_task('abc')
        self.assertIn('unknown to the ACE-Step API server', str(ctx.exception))

    def test_transient_connection_error_then_success(self):
        # A brief blip must not abort a job that is still tracked once the server is back.
        self._set_responses([
            urllib.error.URLError('Connection refused'),
            {'status': 1, 'result': '[{"file": "/out.wav"}]'},
        ])
        item = self.main.wait_for_task('abc')
        self.assertEqual(item['file'], '/out.wav')


if __name__ == '__main__':
    unittest.main()
