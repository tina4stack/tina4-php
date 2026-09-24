"""Exercise real filesystem artifacts and verify the release trust boundary."""
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from release_evidence import generate


class ReleaseEvidenceTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        subprocess.run(['git', 'init', '-q', str(self.root)], check=True)
        subprocess.run(['git', '-C', str(self.root), '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '--allow-empty', '-qm', 'fixture'], check=True)
        self.output = self.root / 'dist'
        self.output.mkdir()

    def emit(self):
        generate(self.root, self.output, 'example', '1.0.0', 'MIT', 'example/project')

    def test_empty_release_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'No built release artifacts'):
            self.emit()

    def test_inventory_matches_bytes_and_distinguishes_locked_inputs(self):
        artifact = self.output / 'example-1.0.0.tgz'
        artifact.write_bytes(b'actual package bytes\x00\xff')
        (self.root / 'package-lock.json').write_text(json.dumps({'packages': {
            '': {'name': 'example', 'version': '1.0.0'},
            'node_modules/optional-build-input': {'version': '2.0.0', 'license': 'MIT', 'optional': True},
        }}))
        self.emit()
        doc = json.loads((self.output / 'sbom.spdx.json').read_text())
        self.assertEqual(doc['spdxVersion'], 'SPDX-2.3')
        ids = {'SPDXRef-DOCUMENT'} | {p['SPDXID'] for p in doc['packages']}
        for relationship in doc['relationships']:
            self.assertIn(relationship['spdxElementId'], ids)
            self.assertIn(relationship['relatedSpdxElement'], ids)
        package = next(p for p in doc['packages'] if p.get('packageFileName') == artifact.name)
        self.assertEqual(package['checksums'][0]['checksumValue'], hashlib.sha256(artifact.read_bytes()).hexdigest())
        for line in (self.output / 'SHA256SUMS').read_text().splitlines():
            expected, name = line.split('  ', 1)
            self.assertEqual(expected, hashlib.sha256((self.output / name).read_bytes()).hexdigest())
        self.assertFalse(any(r['relationshipType'] == 'DEPENDS_ON' for r in doc['relationships']))
        original = package['checksums'][0]['checksumValue']
        artifact.write_bytes(b'tampered')
        self.assertNotEqual(original, hashlib.sha256(artifact.read_bytes()).hexdigest())

    def test_symlink_cannot_pull_outside_bytes_into_release(self):
        outside = self.root / 'outside'
        outside.write_text('not a release asset')
        (self.output / 'example.tgz').symlink_to(outside)
        with self.assertRaisesRegex(ValueError, 'Unsafe artifact'):
            self.emit()


if __name__ == '__main__':
    unittest.main()
