#!/usr/bin/env bash
# Compatibility entry point for Packman's canonical AI artifacts.
# CLAUDE.md is host-owned; Packman manages AGENTS.md and the 32 companion artifacts.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODE="${1:-write}"

case "$MODE" in
    write)
        exec "$ROOT/bin/php84" "$ROOT/vendor/bin/packman" ai:sync --root="$ROOT"
        ;;
    --check)
        exec "$ROOT/bin/php84" "$ROOT/vendor/bin/packman" ai:sync --root="$ROOT" --dry-run
        ;;
    *)
        echo "Usage: $0 [--check]" >&2
        exit 2
        ;;
esac
