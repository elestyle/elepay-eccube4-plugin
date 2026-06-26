#!/bin/bash
#
# elepay 決済プラグインのリリース用パッケージ(tar.gz)を作成するスクリプト。
#
# EC-CUBE プラグイン仕様に準拠:
#   https://doc4.ec-cube.net/plugin_spec
#   - 配布形式は tar.gz(仕様の推奨形式)
#   - プラグインのファイルはアーカイブのルートに直接配置する(フォルダごと圧縮しない)
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
# 仕様で除外が必須の .git / .DS_Store に加え、.gitignore も除外する。
EXCLUDE=(
  --exclude='.git'
  --exclude='*/.git'
  --exclude='.DS_Store'
  --exclude='.gitignore'
)

# --- 出力ファイル名(composer.json の version を付与) ----------------------
VERSION="$(jq -r '.version // empty' composer.json 2>/dev/null || true)"
ARCHIVE="elepay-eccube4-plugin${VERSION:+-v${VERSION}}.tar.gz"

# --- 必須ファイル/ディレクトリの存在チェック -------------------------------
for item in "${INCLUDE[@]}"; do
  if [ ! -e "$item" ]; then
    echo "エラー: '$item' が見つかりません。パッケージを作成できません。" >&2
    exit 1
  fi
done

# --- 既存のアーカイブを削除してから作成 ------------------------------------
rm -f "$ARCHIVE"

# 仕様推奨の tar.gz 形式で作成する。
#   COPYFILE_DISABLE=1 : macOS の AppleDouble(._*)をアーカイブに含めない
#   -c 作成 / -z gzip / -v 一覧表示 / -f 出力ファイル
COPYFILE_DISABLE=1 tar "${EXCLUDE[@]}" -czvf "$ARCHIVE" "${INCLUDE[@]}"

echo ""
echo "作成しました: $ARCHIVE"
echo "  サイズ : $(du -h "$ARCHIVE" | cut -f1)"
echo "  件数   : $(tar -tzf "$ARCHIVE" | grep -vc '/$') ファイル"

# 念のため、除外すべきファイルが混入していないか検査する
if tar -tzf "$ARCHIVE" | grep -qiE 'DS_Store|\.gitignore|__MACOSX|/\._|(^|/)\.git(/|$)'; then
  echo "警告: 除外対象のファイルがパッケージに含まれています。" >&2
  tar -tzf "$ARCHIVE" | grep -iE 'DS_Store|\.gitignore|__MACOSX|/\._|(^|/)\.git(/|$)' >&2
  exit 1
fi