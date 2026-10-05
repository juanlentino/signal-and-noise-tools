#!/usr/bin/env bash
# Decides whether the Claude security scan can be skipped for a PR.
# stdin: the PR's files API rows, one JSON object per line ({filename, patch}).
# stdout: true ONLY when every file is docs/Markdown, or the plugin header
# whose every changed line is its `Version:` line or its `Front-End Change:
# yes|no` line (what tools/cut-release.sh writes). Anything else, a missing patch (GitHub omits it for large diffs),
# empty input or a jq error prints false: doubt means scan.
set -uo pipefail
rows=$(cat)
[ -n "$rows" ] || { echo false; exit 0; }
jq -rs '
  def docs: test("^(docs/.*|[^/]+\\.md)$");
  def version_only:
    (.patch // "") | split("\n")
    | map(select(test("^[-+]")))
    | (length > 0) and all(test("^[-+] \\* (Version: +[0-9]+\\.[0-9]+\\.[0-9]+|Front-End Change: (yes|no)|Front-End Baseline: [0-9]+\\.[0-9]+\\.[0-9]+)$"));
  (length > 0) and all((.filename | docs) or (.filename == "signal-and-noise-tools.php" and version_only))
' <<<"$rows" 2>/dev/null || echo false
