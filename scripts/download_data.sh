#!/usr/bin/env bash
set -euo pipefail

# Script to download and extract official Fahares 34-volume JSON dataset from GitHub Releases
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
TARGET_DIR="${PROJECT_ROOT}/sources/json"
RELEASE_URL="https://github.com/fahares/fahares-corpus/releases/download/v1.1.2/fahares_json_v1.1.2.tar.gz"

mkdir -p "$TARGET_DIR"

echo "Downloading Fahares 34-volume JSON dataset (v1.1.2)..."
TEMP_TAR="$(mktemp /tmp/fahares_json_XXXXXX.tar.gz)"

curl -fSL "$RELEASE_URL" -o "$TEMP_TAR"

echo "Extracting JSON files to ${TARGET_DIR}..."
tar -xzf "$TEMP_TAR" -C "$TARGET_DIR"
rm -f "$TEMP_TAR"

COUNT=$(ls -1 "$TARGET_DIR"/*vol_*.json "$TARGET_DIR"/fankha/fankha_vol_*.json 2>/dev/null | wc -l || echo 0)
echo "Successfully downloaded and extracted ${COUNT} volume JSON files into ${TARGET_DIR}."
