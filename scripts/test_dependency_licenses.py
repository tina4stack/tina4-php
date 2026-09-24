# Copyright (c) 2026 Code Infinity
# SPDX-License-Identifier: MPL-2.0
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
import json
from pathlib import Path
import tempfile
import unittest
from check_dependency_licenses import check


class LicenceGateTest(unittest.TestCase):
    def test_lock_change_and_missing_declaration_fail(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'scripts').mkdir()
            (root / 'package.json').write_text('{"devDependencies":{"example":"1.0.0"}}')
            lock = {'packages': {'node_modules/example': {'version': '1.0.0', 'license': 'MIT'}}}
            (root / 'package-lock.json').write_text(json.dumps(lock))
            item = {'ecosystem': 'npm', 'name': 'example', 'version': '1.0.0', 'lock_license': 'MIT', 'platform': '', 'upstream_declaration': 'MIT', 'evidence': 'package-lock.json'}
            inventory = root / 'scripts/dependency-licenses.json'
            inventory.write_text(json.dumps({'schema': 1, 'components': [item]}))
            check(root)
            lock['packages']['node_modules/example']['version'] = '2.0.0'
            (root / 'package-lock.json').write_text(json.dumps(lock))
            with self.assertRaisesRegex(ValueError, 'licence review required'):
                check(root)
            item['version'] = '2.0.0'
            item['upstream_declaration'] = 'NOASSERTION'
            inventory.write_text(json.dumps({'schema': 1, 'components': [item]}))
            with self.assertRaisesRegex(ValueError, 'Missing upstream'):
                check(root)

    def test_required_runtime_dependency_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'scripts').mkdir()
            (root / 'scripts/dependency-licenses.json').write_text('{"schema":1,"components":[]}')
            (root / 'package-lock.json').write_text('{"packages":{}}')
            (root / 'package.json').write_text('{"dependencies":{"example":"1.0.0"}}')
            with self.assertRaisesRegex(ValueError, 'required runtime dependencies'):
                check(root)


if __name__ == '__main__':
    unittest.main()
