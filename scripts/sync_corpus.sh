#!/usr/bin/env bash
set -euo pipefail

# Script to sync or link text corpus from fahares-corpus repository
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
CORPUS_DIR="${PROJECT_ROOT}/../fahares-corpus"
TARGET_TEXT_DIR="${PROJECT_ROOT}/sources/text"

mkdir -p "$TARGET_TEXT_DIR"

if [ -d "$CORPUS_DIR/text" ]; then
    echo "Syncing text files from local fahares-corpus ($CORPUS_DIR/text) to $TARGET_TEXT_DIR ..."
    cp -p "$CORPUS_DIR"/text/fahares_vol_*.txt "$TARGET_TEXT_DIR/"
    echo "Successfully synced $(ls -1 "$TARGET_TEXT_DIR"/fahares_vol_*.txt | wc -l) volume text files."
else
    echo "Local corpus directory not found at $CORPUS_DIR."
    echo "You can clone it using:"
    echo "  git clone https://github.com/fahares/fahares-corpus.git \"$CORPUS_DIR\""
    echo "Or set REMOTE_CORPUS_URL to pull directly."
    exit 1
fi
