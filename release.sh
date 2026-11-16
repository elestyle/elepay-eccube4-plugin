#!/bin/bash
#
# Builds the release package (tar.gz) of the elepay payment plugin.
#
# Follows the EC-CUBE plugin spec:
#   https://doc4.ec-cube.net/plugin_spec
#   - Distributed as tar.gz (the format the spec recommends)
#   - Plugin files sit directly at the archive root (the folder itself is not archived)
#   - composer.json is required
#   - .git / .DS_Store / .gitignore / macOS resource forks (._*, __MACOSX) are not included
#
set -euo pipefail

# Run in the script's directory (same result wherever it is called from)
cd "$(dirname "$0")"

# --- Plugin components included in the package ----------------------------
# List explicitly only the directories/files the plugin needs.
# (.idea, docs, README, old zips etc. are not needed for distribution. LICENSE is shipped as the MIT terms require)
INCLUDE=(
  Controller
  Entity
  Form
  Repository
  Resource
  Service
  Util
  composer.json
  Event.php
  PluginManager.php
  LICENSE
)

# --- File patterns excluded from the package ------------------------------
# Besides .git / .DS_Store, which the spec requires to be excluded, also exclude .gitignore.
EXCLUDE=(
  --exclude='.git'
  --exclude='*/.git'
  --exclude='.DS_Store'
  --exclude='.gitignore'
)

# --- Output file name (with the version from composer.json) ---------------
VERSION="$(jq -r '.version // empty' composer.json 2>/dev/null || true)"
ARCHIVE="elepay-eccube4-plugin${VERSION:+-v${VERSION}}.tar.gz"

# --- Check that the required files/directories exist ----------------------
for item in "${INCLUDE[@]}"; do
  if [ ! -e "$item" ]; then
    echo "エラー: '$item' が見つかりません。パッケージを作成できません。" >&2
    exit 1
  fi
done

# --- Delete the existing archive, then create it --------------------------
rm -f "$ARCHIVE"

# Create it in the tar.gz format the spec recommends.
#   COPYFILE_DISABLE=1 : keep macOS AppleDouble files (._*) out of the archive
#   -c create / -z gzip / -v list files / -f output file
COPYFILE_DISABLE=1 tar "${EXCLUDE[@]}" -czvf "$ARCHIVE" "${INCLUDE[@]}"

echo ""
echo "作成しました: $ARCHIVE"
echo "  サイズ : $(du -h "$ARCHIVE" | cut -f1)"
echo "  件数   : $(tar -tzf "$ARCHIVE" | grep -vc '/$') ファイル"

# As a safeguard, check that no excluded file slipped in
if tar -tzf "$ARCHIVE" | grep -qiE 'DS_Store|\.gitignore|__MACOSX|/\._|(^|/)\.git(/|$)'; then
  echo "警告: 除外対象のファイルがパッケージに含まれています。" >&2
  tar -tzf "$ARCHIVE" | grep -iE 'DS_Store|\.gitignore|__MACOSX|/\._|(^|/)\.git(/|$)' >&2
  exit 1
fi