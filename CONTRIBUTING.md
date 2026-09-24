# Contributing

Thanks for helping with Tina4. The full contributor policy lives at
https://tina4.com/general/contributing.html - this is the short version.

- **Fork and open a pull request** against `v3`. Nobody pushes to the release branch
  directly, and a pull request merges only when the required checks are green.
- **Tests first, and real.** A test that touches a database, queue, cache, socket or mail
  server talks to the real thing. No mocks. Include a positive and a negative case, and a
  regression test for every bug fix.
- **Parity.** Tina4 is one framework in four languages. If the behaviour exists in Python,
  PHP, Ruby and Node.js, change all four or say why it belongs to one.
- **No new runtime dependencies.** Drivers and optional servers are the application's
  choice (ADR-0067). A new development dependency needs a written reason.
- **Sign off every commit** with `git commit -s`, and agree to the
  [Contributor Licence Agreement](https://tina4.com/general/contributor-licence-agreement.html)
  on your first pull request.
- **Security issues stay private.** Don't open an issue or pull request about a
  vulnerability - follow [SECURITY.md](SECURITY.md) and the
  [security research policy](https://tina4.com/general/security-research.html).
