# `tina4 routes` safe discovery — 3.13.105

## Contract

`tina4 routes` is a read-only inspection command. It discovers canonical route
files, prints the registered method/path/auth data, and exits zero. It must not
execute the project's server entrypoint, open a browser, bind or take over a
port, or remain running.

## Implementation

- Reproduce with a real child CLI process and an `index.php` that must not run.
- Bootstrap the framework directly with the project's base path so normal route
  discovery occurs without including the server entrypoint.
- Keep existing route output stable.
- Run the targeted regression and the complete suite on the lab host as root.

## Verification

- Targeted route contract: 1 test, 4 assertions, all passed.
- Full suite: 5,455 tests and 18,783 assertions; 3 failures from the unavailable
  MSSQL lab connection. No route-discovery regression.

## Parity

The same observable contract is locked in Python, Ruby, and Node.js. Language
internals may differ; all four commands must remain finite and network-free.
