#!/usr/bin/env python3
import runpy
import subprocess
import unittest
from pathlib import Path
from unittest.mock import patch


HELPER = Path(__file__).resolve().parents[4] / 'installer' / 'apply-schema.py'


class SchemaCompatibilityTest(unittest.TestCase):
    def execute(self, schema, responses):
        def legacy_run(arguments, input=None, universal_newlines=False,
                       stdout=None, stderr=None, check=False):
            self.assertTrue(universal_newlines)
            self.assertEqual(stdout, subprocess.PIPE)
            self.assertEqual(stderr, subprocess.PIPE)
            self.assertTrue(check)
            return subprocess.CompletedProcess(arguments, 0, next(responses), '')

        with patch('sys.argv', [str(HELPER), 'test_database', 'unused.sql']), \
                patch.object(Path, 'read_text', return_value=schema), \
                patch('subprocess.run', side_effect=legacy_run) as invocation:
            runpy.run_path(str(HELPER), run_name='__main__')
            return [call[1]['input'] for call in invocation.call_args_list]

    def test_create_with_python36_arguments(self):
        statements = self.execute('CREATE TABLE example (id INT);', iter(['']))
        self.assertEqual(statements, ['CREATE TABLE example (id INT)'])

    def test_column_added_only_when_absent(self):
        schema = 'ALTER TABLE example ADD COLUMN IF NOT EXISTS value INT;'
        statements = self.execute(schema, iter(['0', '']))
        self.assertEqual(statements[-1], 'ALTER TABLE `example` ADD COLUMN value INT')
        self.assertEqual(len(self.execute(schema, iter(['1']))), 1)


if __name__ == '__main__':
    unittest.main()
