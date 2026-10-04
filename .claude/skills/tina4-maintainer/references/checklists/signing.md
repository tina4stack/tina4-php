# Checklist: signing the skills installer (`install-skills.ps1`)

`install-skills.ps1` is served to users at a tag and must carry a VALID Code Infinity
Authenticode (EV) signature. BOTH the CI gate (`.github/workflows/ci.yml`, which validates it on
`windows-latest` with `Get-AuthenticodeSignature`) and the tina4.com runtime shim reject it
otherwise, and the Windows installer fails its own signature check. `install-skills.sh` needs no
signature — this checklist is the `.ps1` only.

**Any edit invalidates the signature.** Bumping the default `TINA4_SKILLS_REF`, adding or removing a
reference in the file list, or any byte change means re-sign in the SAME change, or CI goes red. The
file is marked `binary` in `.gitattributes` so signed bytes survive commit/checkout.

## 🔒 Only an authorized, logged-in signing session may sign — but that works on macOS OR Windows

The EV private key lives on the Certum SimplySign cloud HSM and never leaves it; signing just needs
**SimplySign Desktop / proCertumSmartSign OPEN and LOGGED IN** (it mounts the Code Infinity EV card
as a PKCS#11 token / Windows cert). EV signing is interactive (2FA), so it runs locally, not in CI.
We have scripts for BOTH platforms — **do not assume it is Windows-only; the Mac path is first-class
and CI proves the Mac-produced signature validates on Windows.** The only hard requirement is that
SimplySign is logged in on whatever machine you sign from.

## Sign on macOS (the day-to-day path)

```sh
brew install osslsigncode opensc libp11      # one-time toolchain
# proCertumSmartSign / SimplySign Desktop OPEN and LOGGED IN (mounts the EV card)
scripts/sign-installers-mac.sh install-skills.ps1
```

- Uses `osslsigncode` against the card via PKCS#11 (`libSimplySignPKCS.dylib`) + the
  `codeinfinity-chain.pem` chain + the Certum TSA; signs the PowerShell SIP block in place.
- Override points if the defaults do not match this Mac: `TINA4_PKCS11_MODULE`, `TINA4_PKCS11_ENGINE`,
  `TINA4_CERT_CHAIN`, `TINA4_TS_URL`.
- Alternative Mac signer: `scripts/sign-skills-installer-mac.sh` (uses `jsign` + the SimplySign
  PKCS#11 module; `--check` verifies the token/cert are available without signing).

## Sign on Windows

```powershell
# SimplySign Desktop open and logged in (cert lands in Cert:\CurrentUser\My) + Windows SDK signtool
pwsh ./scripts/sign-installers.ps1
```

- Drives `signtool.exe` against the EV cert in `Cert:\CurrentUser\My`; guards it is the Code Infinity cert.

## After signing (either platform)

- [ ] Confirm the signature: macOS `osslsigncode verify -in install-skills.ps1`; Windows
      `Get-AuthenticodeSignature .\install-skills.ps1` → `Valid`, signer Code Infinity. The final
      authority is the `windows-latest` CI job.
- [ ] `git add install-skills.ps1` (binary attribute keeps the exact signed bytes), commit.
- [ ] Sync the tina4.com shim: `tina4-documentation/docs/public/install-skills.ps1` must be the SAME
      signed bytes (it is the served copy). Bump its pinned ref too.
- [ ] (Re)create the skills tag `3.13.NNN` on the `tina4` repo at the SIGNED commit.

Full release flow: `tina4/scripts/RELEASING.md` and the `tina4` repo `tina4/CLAUDE.md` section
"Releasing the skills installer". See also [[release]].
