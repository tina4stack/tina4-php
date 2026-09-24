# Inbound dependency licence policy

Tina4 first-party code is MPL-2.0. The framework packages declare no required
third-party runtime dependencies. Optional database/server drivers belong to the
application; development and test dependencies are not bundled in framework packages.

The resolved development/optional dependency versions and their upstream licence
declarations are recorded in `dependency-licenses.json`. This is the reviewed
baseline for this release. Existing declarations (including copyleft licences for
separately installed test/application drivers) do not grant permission to bundle
those dependencies. Original third-party notices must be preserved whenever code
is copied or bundled, and the package must supply the applicable licence texts.

Every pull request checks its lockfile against that inventory and rejects missing,
new, removed or changed component versions, platforms or lockfile licence declarations.
An intentional dependency update requires a corresponding inventory review in the
same pull request: inspect the exact upstream version's licence, retain its evidence
URL and declaration, document why the dependency is needed, and review any change
in distribution or obligations. Missing or unknown declarations cannot be accepted.
Do not infer an SPDX identifier from a generic licence classifier. The inventory
preserves upstream wording, including alternative licences and exceptions.

These checks establish traceability and prohibit unreviewed dependency changes;
they do not replace the project's legal review or its OpenChain conformance assessment.
