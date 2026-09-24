# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 3.x (latest minor, currently 3.13.x) | Yes: all security fixes |
| 3.x (older minors) | Upgrade to the latest 3.x; fixes are not backported |
| 2.x | Critical and High fixes only, best effort |
| 1.x and earlier | No |

The Python, PHP, Ruby and Node.js frameworks share one version number and one release.

## Reporting a vulnerability

Please do not open a public issue, pull request or discussion for a security problem.

Report it privately, using either of these:

1. **GitHub private vulnerability reporting** (preferred): open the repository's **Security** tab and choose **Report a vulnerability**. You and the maintainers get a private advisory to work in.
2. **Email:** info@tina4.com, with `SECURITY` in the subject line.

Please include:

- the affected package and version (or commit)
- the language/framework and database engine, where relevant
- steps to reproduce, or a proof of concept
- the impact you believe it has

## What happens next

| Step | Target |
|---|---|
| Acknowledge your report | 3 business days |
| Triage and severity (CVSS v3.1) | 10 business days |
| Fix released: Critical / High | 30 days from triage |
| Fix released: Medium / Low | next scheduled release, at most 90 days |
| Public advisory (GitHub Security Advisory, CVE where applicable) | when the fix ships |

Tina4 ships one framework in four languages (Python, PHP, Ruby, Node.js). A vulnerability found in one is checked in all four, and fixed in every affected framework in the same release.

We practise coordinated disclosure. Please give us the time above before disclosing publicly; we will agree a date with you and credit you in the advisory unless you ask us not to.

## Scope

In scope: this repository's code, its published packages, and release artefacts built from it.

Out of scope: applications built with Tina4 (report those to their owners), and features that are only enabled in development mode (`TINA4_DEBUG=true`) when the report depends on exposing a development server to an untrusted network. Reports that show a development feature can be reached from a normal production configuration are in scope.
