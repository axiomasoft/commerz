#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE="$ROOT/.ai/guidelines/agent-entrypoint.md"
MAX_BYTES=2048
MODE="${1:-write}"

[ -f "$SOURCE" ] || { echo "Missing source: $SOURCE" >&2; exit 1; }
bytes="$(wc -c < "$SOURCE")"
[ "$bytes" -le "$MAX_BYTES" ] || {
    echo "Entrypoint is too large: $bytes > $MAX_BYTES bytes" >&2
    exit 1
}

render() {
    printf '<!-- generated from .ai/guidelines/agent-entrypoint.md by scripts/build-agent-docs.sh -->\n\n'
    cat "$SOURCE"
    # Preserve provider-managed blocks appended outside the generated context.
    [ -f "$1" ] || return 0
    awk '
        /^<!-- swissknifeman:hub:start -->/ || /^<!-- maind:healthcheck / { print ""; managed = 1 }
        managed { print }
        /^<!-- swissknifeman:hub:end -->/ || /^<!-- \/maind:healthcheck -->/ { managed = 0 }
    ' "$1"
}

case "$MODE" in
    write)
        output="$(mktemp)"
        trap 'rm -f "$output"' EXIT
        for target in CLAUDE.md AGENTS.md; do
            render "$ROOT/$target" > "$output"
            cat "$output" > "$ROOT/$target"
        done
        ;;
    --check)
        cmp -s <(render "$ROOT/CLAUDE.md") "$ROOT/CLAUDE.md" && cmp -s <(render "$ROOT/AGENTS.md") "$ROOT/AGENTS.md" || {
            echo "Agent entrypoints are stale; run bash scripts/build-agent-docs.sh" >&2
            exit 1
        }
        ;;
    *)
        echo "Usage: $0 [--check]" >&2
        exit 2
        ;;
esac
