#!/bin/sh
set -eu
found=$(git ls-files -s | awk '$1=="120000"{print $4}')
if [ -n "$found" ]; then echo "ERROR: committed symlinks are not allowed (break Windows/Composer extraction; supply-chain risk):" >&2; echo "$found" >&2; exit 1; fi
echo "OK: no committed symlinks"
