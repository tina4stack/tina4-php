#!/usr/bin/env python3
# Copyright (c) 2026 Code Infinity
# SPDX-License-Identifier: MPL-2.0
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
"""
check_llms_txt.py -- fail when llms.txt does not follow the llmstxt.org spec.

The spec (https://llmstxt.org/) fixes the section order of an llms.txt file:

  1. an optional UTF-8 BOM, then an H1 with the project name;
  2. a ``>`` blockquote with a short summary (required here);
  3. zero or more free-form sections of any markdown EXCEPT headings;
  4. zero or more H2 sections that hold only file lists, one item per line:
     ``- [name](url)`` optionally followed by ``: notes``.

This gate also requires at least one link, and every H2 section to list at
least one. It reads the file only -- it never fetches a URL.

Zero dependencies (standard library only). Exit 0 when the file conforms,
1 with ``file:line: message`` diagnostics when it does not, 2 on usage errors.

    python3 scripts/check_llms_txt.py              # checks ./llms.txt
    python3 scripts/check_llms_txt.py path/to/llms.txt
    python3 scripts/check_llms_txt.py --self-test  # proves the gate is a gate
"""

from __future__ import annotations

import os
import re
import sys

ATX_HEADING = re.compile(r"^ {0,3}(#{1,6})(?:[ \t]+|$)(.*)$")
SETEXT_UNDERLINE = re.compile(r"^ {0,3}(=+|-+)[ \t]*$")
FENCE = re.compile(r"^ {0,3}(`{3,}|~{3,})")
LINK_ITEM = re.compile(r"^- \[([^\[\]]+)\]\(([^()\s]+)\)(?:: (\S.*))?$")


def check(text: str, name: str = "llms.txt") -> tuple[list[str], int]:
    """Return (violations, link_count) for the llms.txt content ``text``."""
    errors: list[str] = []

    def err(line_no: int, msg: str) -> None:
        errors.append(f"{name}:{line_no}: {msg}")

    if text.startswith("\ufeff"):
        text = text[1:]  # the spec allows an optional BOM
    lines = text.splitlines()

    # 1. H1 first.
    idx = 0
    while idx < len(lines) and not lines[idx].strip():
        idx += 1
    if idx == len(lines):
        err(1, "file is empty; expected an H1 with the project name")
        return errors, 0
    m = ATX_HEADING.match(lines[idx])
    if not m or len(m.group(1)) != 1 or not m.group(2).strip("# \t"):
        err(idx + 1, "first line must be an H1 with the project name ('# Name')")
    idx += 1

    # 2. Blockquote summary straight after the H1.
    while idx < len(lines) and not lines[idx].strip():
        idx += 1
    if idx < len(lines) and lines[idx].lstrip().startswith(">"):
        if not lines[idx].lstrip()[1:].strip():
            err(idx + 1, "blockquote summary is empty")
        while idx < len(lines) and lines[idx].strip():
            idx += 1  # the blockquote paragraph (lazy continuation allowed)
    else:
        err(idx + 1 if idx < len(lines) else len(lines),
            "missing '> summary' blockquote directly after the H1")

    # 3. Free-form zone: anything but headings, until the first H2.
    in_fence: str | None = None
    prev_text = False  # previous line is paragraph text (setext candidate)
    while idx < len(lines):
        line = lines[idx]
        fence = FENCE.match(line)
        if in_fence:
            if fence and fence.group(1)[0] == in_fence[0] \
                    and len(fence.group(1)) >= len(in_fence):
                in_fence = None
            idx += 1
            continue
        if fence:
            in_fence = fence.group(1)
            prev_text = False
            idx += 1
            continue
        h = ATX_HEADING.match(line)
        if h:
            if len(h.group(1)) == 2:
                break  # start of the file-list sections
            err(idx + 1, f"H{len(h.group(1))} heading not allowed before the "
                "H2 file-list sections (free-form text may not use headings)")
        elif prev_text and SETEXT_UNDERLINE.match(line):
            err(idx + 1, "setext heading (underlined text) not allowed in the "
                "free-form section")
        prev_text = bool(line.strip()) and not h \
            and not line.lstrip().startswith(("-", "*", "+", ">", "|"))
        idx += 1
    if in_fence:
        err(len(lines), "unclosed code fence")

    # 4. H2 sections: only '- [name](url): notes' items.
    links = 0
    section_line = 0
    section_links = 0
    section_title = ""

    def close_section() -> None:
        if section_line and section_links == 0:
            err(section_line, f"H2 section '{section_title}' lists no links")

    while idx < len(lines):
        line = lines[idx]
        h = ATX_HEADING.match(line)
        if h and len(h.group(1)) == 2:
            close_section()
            section_line, section_links = idx + 1, 0
            section_title = h.group(2).strip("# \t")
            if not section_title:
                err(idx + 1, "H2 section has no name")
        elif h:
            err(idx + 1, f"H{len(h.group(1))} heading not allowed inside the "
                "file-list sections (only H2s)")
        elif not line.strip():
            pass
        elif LINK_ITEM.match(line):
            links += 1
            section_links += 1
        else:
            err(idx + 1, "file-list sections may hold only "
                "'- [name](url)' or '- [name](url): notes' items")
        idx += 1
    close_section()

    if links == 0:
        err(len(lines), "no '- [name](url)' links found; an llms.txt must "
            "point at the documents it describes")
    return errors, links


GOOD = """# Demo

> One-line summary.

Free text, a list and a fenced block with a shell comment:

- not a link list item, which is fine here

```sh
# this is a comment, not a heading
```

## Docs

- [Routing](https://example.com/routing.md): how routes work
- [ORM](https://example.com/orm.md)

## Optional

- [Full](https://example.com/llms-full.txt)
"""

BAD = {
    "no H1": GOOD.replace("# Demo\n", "Demo\n"),
    "H1 is an H2": GOOD.replace("# Demo\n", "## Demo\n", 1),
    "no blockquote": GOOD.replace("> One-line summary.\n", ""),
    "H3 in free-form": GOOD.replace("Free text", "### Sub\n\nFree text"),
    "setext in free-form": GOOD.replace("Free text, a list",
                                        "Title\n=====\n\nFree text, a list"),
    "prose in H2 section": GOOD.replace("- [ORM]", "Some prose.\n- [ORM]"),
    "code in H2 section": GOOD + "\n```py\nx = 1\n```\n",
    "H3 in H2 section": GOOD + "\n### Extra\n",
    "bare url item": GOOD.replace("- [ORM](https://example.com/orm.md)",
                                  "- https://example.com/orm.md"),
    "empty H2 section": GOOD + "\n## Empty\n",
    "no links": "# Demo\n\n> Summary.\n\nJust prose.\n",
}


def self_test() -> int:
    failures = []
    errors, links = check(GOOD, "good")
    if errors or links != 3:
        failures.append(f"known-good sample rejected: {errors} links={links}")
    if check("\ufeff" + GOOD, "bom")[0]:
        failures.append("BOM-prefixed known-good sample rejected")
    for label, sample in BAD.items():
        if not check(sample, label)[0]:
            failures.append(f"known-bad sample accepted: {label}")
    for f in failures:
        print(f"self-test FAIL: {f}", file=sys.stderr)
    if failures:
        return 1
    print(f"self-test OK: 1 good sample passes, {len(BAD)} bad samples fail")
    return 0


def main(argv: list[str]) -> int:
    if argv and argv[0] == "--self-test":
        return self_test()
    if len(argv) > 1 or (argv and argv[0].startswith("-")):
        print(__doc__, file=sys.stderr)
        return 2
    path = argv[0] if argv else os.path.join(
        os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "llms.txt")
    try:
        with open(path, encoding="utf-8") as fh:
            text = fh.read()
    except (OSError, UnicodeDecodeError) as exc:
        print(f"{path}: cannot read as UTF-8: {exc}", file=sys.stderr)
        return 1
    errors, links = check(text, os.path.basename(path))
    for e in errors:
        print(e)
    if errors:
        print(f"FAIL: {len(errors)} llmstxt.org spec violation(s) in {path}")
        return 1
    print(f"OK: {path} follows the llmstxt.org spec ({links} links)")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
