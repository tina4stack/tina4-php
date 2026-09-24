#!/usr/bin/env python3
"""Create SPDX 2.3 release inventory and checksums using only the Python stdlib.

The SBOM identifies built artifacts and the resolved dependency inventory in the
source lockfile. Lockfile entries are explicitly OTHER relationships: optional
and development resolutions are not a claim of bundled runtime dependencies.
Unknown component licences remain NOASSERTION; this is evidence, not legal approval.
"""
import argparse
import datetime
import hashlib
import json
import re
import subprocess
import tomllib
from pathlib import Path
from urllib.parse import quote


def digest(path, algorithm='sha256'):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, algorithm).hexdigest()


def locked_components(root):
    """Return resolved manifest/lock components without installing or executing code."""
    if (root / 'uv.lock').is_file():
        lock = tomllib.loads((root / 'uv.lock').read_text())
        for p in lock.get('package', []):
            if p.get('source', {}).get('registry'):
                yield 'pypi', p['name'], p['version'], 'NOASSERTION', ''
    elif (root / 'package-lock.json').is_file():
        lock = json.loads((root / 'package-lock.json').read_text())
        for path, p in lock.get('packages', {}).items():
            if not path or p.get('link') or 'node_modules/' not in path:
                continue
            name = p.get('name') or path.rsplit('node_modules/', 1)[-1]
            if 'version' not in p:
                raise ValueError('Unversioned lock component: ' + path)
            yield 'npm', name, p['version'], p.get('license') or 'NOASSERTION', ''
    elif (root / 'composer.lock').is_file():
        lock = json.loads((root / 'composer.lock').read_text())
        for p in lock.get('packages', []) + lock.get('packages-dev', []):
            licences = p.get('license', [])
            yield 'composer', p['name'], p['version'], ' OR '.join(licences) or 'NOASSERTION', ''
    elif (root / 'Gemfile.lock').is_file():
        lines = (root / 'Gemfile.lock').read_text().splitlines()
        platforms = []
        in_platforms = False
        for line in lines:
            if line == 'PLATFORMS':
                in_platforms = True
            elif line and not line.startswith(' '):
                in_platforms = False
            elif in_platforms and line.strip():
                platforms.append(line.strip())
        # Longest suffix first: x86_64-linux-musl is not the version's
        # prerelease suffix, and must not collapse to x86_64-linux.
        platforms.sort(key=len, reverse=True)
        for line in lines:
            match = re.fullmatch(r'    ([\w.-]+) \(([^ ,)]+)\)', line)
            if match:
                version, platform = match[2], ''
                for candidate in platforms:
                    if version.endswith('-' + candidate):
                        version, platform = version[:-(len(candidate) + 1)], candidate
                        break
                yield 'gem', match[1], version, 'NOASSERTION', platform


def generate(root, output, name, version, licence, repository):
    artifacts = sorted(p for p in output.iterdir() if p.is_file() and p.name not in {'SHA256SUMS', 'sbom.spdx.json', '.gitignore'})
    if not artifacts:
        raise ValueError('No built release artifacts; refusing an empty SBOM')
    for p in artifacts:
        if not p.name.endswith(('.whl', '.tar.gz', '.tgz', '.gem', '.zip')):
            raise ValueError('Unexpected release artifact: ' + p.name)
        if p.is_symlink() or not re.fullmatch(r'[A-Za-z0-9_.+-]+', p.name):
            raise ValueError('Unsafe artifact filename: ' + p.name)
    commit = subprocess.check_output(['git', '-C', str(root), 'rev-parse', 'HEAD'], text=True).strip()
    namespace_hash = hashlib.sha256((repository + commit + ''.join(digest(p) for p in artifacts)).encode()).hexdigest()
    doc = {
        'spdxVersion': 'SPDX-2.3', 'dataLicense': 'CC0-1.0', 'SPDXID': 'SPDXRef-DOCUMENT',
        'name': f'{name}-{version}',
        'documentNamespace': f'https://spdx.org/spdxdocs/{quote(name, safe="")}-{namespace_hash}',
        'creationInfo': {'creators': ['Tool: tina4-release-evidence-1'],
                         'created': datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')},
        'comment': 'Built artifact inventory plus resolved lockfile components. OTHER relationships record build-input inventory only; optional and development packages are not asserted to be bundled at runtime. NOASSERTION licences require separate licence review.',
        'packages': [], 'relationships': [],
    }
    def package(identifier, pkg_name, pkg_version, declared, location):
        item = {'SPDXID': identifier, 'name': pkg_name, 'versionInfo': pkg_version,
                'downloadLocation': location, 'filesAnalyzed': False,
                'licenseDeclared': declared, 'licenseConcluded': 'NOASSERTION',
                'copyrightText': 'NOASSERTION'}
        doc['packages'].append(item)
        return item
    source = package('SPDXRef-Source', name, version, licence, f'git+https://github.com/{repository}@{commit}')
    source['primaryPackagePurpose'] = 'SOURCE'
    for i, artifact in enumerate(artifacts):
        identifier = f'SPDXRef-Artifact-{i}'
        item = package(identifier, artifact.name, version, licence, 'NOASSERTION')
        item['packageFileName'] = artifact.name
        item['checksums'] = [{'algorithm': 'SHA256', 'checksumValue': digest(artifact)}]
        doc['relationships'].extend([
            {'spdxElementId': 'SPDXRef-DOCUMENT', 'relationshipType': 'DESCRIBES', 'relatedSpdxElement': identifier},
            {'spdxElementId': identifier, 'relationshipType': 'GENERATED_FROM', 'relatedSpdxElement': 'SPDXRef-Source'},
        ])
    components = sorted(set(locked_components(root)))
    for i, (ecosystem, component, resolved, declared, platform) in enumerate(components):
        identifier = f'SPDXRef-Locked-{i}'
        item = package(identifier, component, resolved, declared, 'NOASSERTION')
        item['externalRefs'] = [{'referenceCategory': 'PACKAGE-MANAGER', 'referenceType': 'purl',
                                 'referenceLocator': f'pkg:{ecosystem}/{quote(component, safe="/")}@{quote(resolved, safe="")}' + (f'?platform={quote(platform, safe="")}' if platform else '') }]
        doc['relationships'].append({'spdxElementId': 'SPDXRef-Source', 'relationshipType': 'OTHER',
                                     'relatedSpdxElement': identifier,
                                     'comment': 'Resolved lockfile build-input inventory; not an assertion of bundled runtime dependency.'})
    (output / 'sbom.spdx.json').write_text(json.dumps(doc, indent=2) + '\n')
    assets = sorted(artifacts + [output / 'sbom.spdx.json'])
    (output / 'SHA256SUMS').write_text(''.join(f'{digest(p)}  {p.name}\n' for p in assets))
    print(f'Release evidence: {len(artifacts)} artifacts, {len(components)} resolved lock components')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path('.'))
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--name', required=True)
    parser.add_argument('--version', required=True)
    parser.add_argument('--license', required=True)
    parser.add_argument('--repository', required=True)
    args = parser.parse_args()
    if not re.fullmatch(r'[\w.-]+/[\w.-]+', args.repository):
        parser.error('repository must be owner/name')
    generate(args.root, args.output, args.name, args.version, args.license, args.repository)


if __name__ == '__main__':
    main()
