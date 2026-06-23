#!/bin/bash
#
# elepay 決済プラグインのリリース用パッケージ(zip)を作成するスクリプト。
#
# EC-CUBE プラグイン仕様に準拠:
#   https://doc4.ec-cube.net/plugin_spec
#   - プラグインのファイルは zip のルートに直接配置する(フォルダごと圧縮しない)
#   - composer.json は必須
#   - .git / .DS_Store / .gitignore / macOS のリソースフォーク(._*, __MACOSX)は含めない
#
set -euo pipefail

# スクリプトのあるディレクトリで実行する(どこから呼んでも同じ結果にする)
cd "$(dirname "$0")"

# --- パッケージに含めるプラグインの構成要素 -------------------------------
# 機能に必要なディレクトリ/ファイルのみを明示的に列挙する。
# (.idea, docs, README, 過去の zip など配布に不要なものは含めない)
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
)

# --- パッケージから除外するファイルパターン --------------------------------
EXCLUDE=(
  '*.DS_Store'
  '*/.git/*'
  '*.gitignore'
  '__MACOSX/*'
  '*/__MACOSX/*'
)

# --- 出力ファイル名(composer.json の version を付与) ----------------------
VERSION="$(jq -r '.version // empty' composer.json 2>/dev/null || true)"
ZIP="elepay-eccube4-plugin${VERSION:+-v${VERSION}}.zip"

# --- 必須ファイル/ディレクトリの存在チェック -------------------------------
for item in "${INCLUDE[@]}"; do
  if [ ! -e "$item" ]; then
    echo "エラー: '$item' が見つかりません。パッケージを作成できません。" >&2
    exit 1
  fi
done

# --- 既存の zip を削除してから作成 -----------------------------------------
rm -f "$ZIP"

# COPYFILE_DISABLE=1 : macOS の AppleDouble(._*)を tar/zip に含めない
# zip -X            : 余分なファイル属性(拡張属性など)を保存しない
COPYFILE_DISABLE=1 zip -r -X "$ZIP" "${INCLUDE[@]}" -x "${EXCLUDE[@]}"

echo ""
echo "作成しました: $ZIP"
echo "  サイズ : $(du -h "$ZIP" | cut -f1)"
echo "  件数   : $(unzip -l "$ZIP" | tail -1 | awk '{print $2}') ファイル"

# 念のため、除外すべきファイルが混入していないか検査する
if unzip -l "$ZIP" | grep -qiE 'DS_Store|\.gitignore|__MACOSX|/\._'; then
  echo "警告: 除外対象のファイルがパッケージに含まれています。" >&2
  unzip -l "$ZIP" | grep -iE 'DS_Store|\.gitignore|__MACOSX|/\._' >&2
  exit 1
fi