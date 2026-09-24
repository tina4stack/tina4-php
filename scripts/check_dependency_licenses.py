#!/usr/bin/env python3
# Copyright (c) 2026 Code Infinity
# SPDX-License-Identifier: MPL-2.0
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
"""Check resolved dependency licences against the reviewed source inventory."""
import json
from pathlib import Path
import tomllib
from release_evidence import locked_components


def check(root):
    data = json.loads((root / 'scripts/dependency-licenses.json').read_text())
    if data.get('schema') != 1:
        raise ValueError('Unsupported licence inventory schema')
    actual = set(locked_components(root))
    reviewed = set()
    for item in data['components']:
        declaration = item.get('upstream_declaration', '').strip()
        if declaration in ('', 'NOASSERTION', 'UNKNOWN', 'NONE') or not item.get('evidence', '').strip():
            raise ValueError('Missing upstream licence evidence: ' + item['name'])
        key = (item['ecosystem'], item['name'], item['version'], item['lock_license'], item['platform'])
        if key in reviewed:
            raise ValueError('Duplicate inventory component: ' + str(key))
        reviewed.add(key)
    if actual != reviewed:
        raise ValueError('Dependency licence review required. Unreviewed: '
                         + repr(sorted(actual - reviewed)) + '; stale: ' + repr(sorted(reviewed - actual)))
    # Optional providers are application dependencies, never required framework dependencies.
    if (root / 'pyproject.toml').exists():
        project = tomllib.loads((root / 'pyproject.toml').read_text())['project']
        if project.get('dependencies'):
            raise ValueError('Framework must not declare required runtime dependencies')
    elif (root / 'package.json').exists():
        package = json.loads((root / 'package.json').read_text())
        if package.get('dependencies') or package.get('bundledDependencies') or package.get('bundleDependencies'):
            raise ValueError('Framework must not declare or bundle required runtime dependencies')
    elif (root / 'composer.json').exists():
        package = json.loads((root / 'composer.json').read_text())
        required = [name for name in package.get('require', {}) if name != 'php' and not name.startswith('ext-')]
        if required:
            raise ValueError('Framework has required third-party runtime dependencies: ' + repr(required))
    print(f'Licence policy: {len(reviewed)} resolved components match reviewed declarations and evidence')


if __name__ == '__main__':
    check(Path(__file__).resolve().parents[1])
