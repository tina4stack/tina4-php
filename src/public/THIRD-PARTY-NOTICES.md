# Bundled browser component notices

These components retain their stated licences; the repository's MPL-2.0 licence
for first-party source does not replace these grants. The standalone Tina4 JS bundle is updated as noted below; other generated browser
files are preserved unchanged by the licence migration.

## Swagger UI — Apache-2.0

`swagger/oauth2-redirect.html` is adapted from Swagger UI 3.10.0. Its executable
text matches upstream after whitespace and semicolon normalization. Formatting
and semicolon removal are local modifications. Upstream commit:
https://github.com/swagger-api/swagger-ui/tree/1013d16d9eb0bb851b30d38d355a58639baf0f3a

Original copyright: Copyright 2018 SmartBear Software. The original notice is
retained in `licenses/swagger-ui-3.10.0-original-notice.txt` and the full Apache
License 2.0 in `licenses/swagger-ui-Apache-2.0.txt`.

`swagger/index.html` loads Swagger UI 5.17.14 JavaScript and CSS from a CDN; those
remote files are not bundled in this package. Its upstream tag resolves to:
https://github.com/swagger-api/swagger-ui/tree/74ed0adebfc9c8dd0de2bf8e81495b022a66c083
The upstream notice, Copyright 2020-2021 SmartBear Software Inc., is retained in
`licenses/swagger-ui-5.17.14-NOTICE.txt`.

## Tina4 CSS / Frond browser helper — MIT

The `js/frond.js`, `js/frond.min.js`, `js/tina4.min.js`, and `css/tina4*.css`
browser assets originate from the separate Tina4 CSS project. Its original MIT
licence and copyright notice are retained in `licenses/tina4-css-MIT.txt`.
The shipped Frond minified file is byte-identical to `dist/frond.min.js` at:
https://github.com/tina4stack/tina4-css/tree/fab8e670b0c84c44b6e4c657aa87951bbed64824
The other CSS/legacy helper files are earlier generated snapshots; this reference
does not claim they match that revision byte-for-byte.

## Tina4 JS 1.7.2 — MPL-2.0

`js/tina4js.min.js` is the verified browser build from Tina4 JS 1.7.2,
including the rendering and request-origin security corrections. Source:
https://github.com/tina4stack/tina4-js/tree/bb426862b062db0dd6d89577cae997efcba86116
Copyright (c) 2026 Code Infinity. The full licence and source-disclosure notice
are included in the package's LICENSE and NOTICE. Previously published Tina4 JS
releases retain their original licences; the retained MIT text is also used by
the dashboard's conservative dependency inventory.

`bundled-component-provenance.json` records the exact shipped asset hashes covered
by these notices. Remote assets are not classified as bundled dependencies.

## Tina4 development dashboard — retained component licences

`js/tina4-dev-admin.min.js` is byte-identical to `dist/tina4-dev-admin.js` at:
https://github.com/tina4stack/tina4-dev-admin/tree/21700e0
Its resolved production component inventory is preserved in
`licenses/tina4-dev-admin-components.json`; upstream licence notices and terms are
in `licenses/tina4-dev-admin-third-party.txt`. No dashboard code was rebuilt or
relicensed by this notice update. The old Tina4 JS packages declare MIT but supply
no copyright notice; no upstream copyright holder has been invented here.
