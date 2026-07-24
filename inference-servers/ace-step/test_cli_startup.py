"""Regression test: the wrapper's CLI must build without crashing.

A literal ``%`` in an argparse ``help=`` string makes argparse raise
``ValueError: badly formed help string`` while *building* the parser (at import
time, inside ``add_argument`` -> ``_check_help``). That crashed the whole service
on startup before it could serve a single request — see the production traceback
``ValueError: unsupported format character 'n' (0x6e)``.

These tests import ``main.py`` with a Flask stub (so they don't require Flask to
be installed) and assert the parser builds and formats its help cleanly.
"""

import importlib
import os
import sys
import types
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))


def _import_main():
    # Stub Flask: main.py only needs Flask/request/jsonify to *define* its routes.
    # The crash happens during argparse construction, long before any route runs.
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
    # main.py parses argv at import time and --port is required.
    old_argv = sys.argv
    sys.argv = ['main.py', '--port', '0']
    sys.modules.pop('main', None)
    try:
        return importlib.import_module('main')
    finally:
        sys.argv = old_argv


class CliStartupTest(unittest.TestCase):
    def test_import_does_not_crash_on_help_strings(self):
        # If any argparse help= contains an unescaped '%', import_module raises
        # ValueError ("badly formed help string") and the service can never start.
        main = _import_main()
        self.assertTrue(hasattr(main, 'parser'))
        option_strings = {
            opt for action in main.parser._actions for opt in action.option_strings
        }
        self.assertIn('--cover_noise_strength', option_strings)
        self.assertIn('--cover_audio_strength', option_strings)

    def test_format_help_substitutes_without_error(self):
        # format_help() exercises '%' substitution on every action's help string.
        main = _import_main()
        help_text = main.parser.format_help()
        self.assertIn('--cover_noise_strength', help_text)
        self.assertIn('--cover_audio_strength', help_text)


if __name__ == '__main__':
    unittest.main()
