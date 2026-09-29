#!/bin/bash
#
# プラグインに同梱する Resource/vendor(elepay-php-sdk とその依存)を再生成するスクリプト。
#
# - 依存は PHP 7.4 を前提に解決する。EC-CUBE 4.2 が PHP 7.4 で動くため、ローカルの PHP に
#   合わせて解決すると 4.2 で動かない版が入りうる。
# - オートローダーはホスト(EC-CUBE 本体)の後ろに登録する。同名クラスは本体の版を優先させ、
#   本体側の PSR 実装などと型が食い違うのを避けるため。
# - elepay-php-sdk 1.2.3 は PHP 8.4 以降で暗黙の nullable 引数の非推奨警告を出すため、生成後に
#   PHP 7.4 でも有効な明示的 nullable(?型)へ置き換える。SDK 側で修正された版が出たら SDK_VERSION を上げ、
#   patch_sdk を削除すること。
#   (Model/SourceInfo.php と Model/TerminalToken*.php は ArrayAccess の戻り値型の警告も出すが、
#   ModelInterface を実装しきれておらず読み込むと致命的エラーになる、プラグインでは未使用の
#   クラスのため対象外)
#
# composer がローカルに無い場合は Docker の composer イメージを使う。
#
set -euo pipefail

cd "$(dirname "$0")"

SDK_VERSION="1.2.3"

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

cat > "$WORK_DIR/composer.json" <<EOF
{
  "require": {
    "elestyle/elepay-php-sdk": "${SDK_VERSION}"
  },
  "config": {
    "platform": { "php": "7.4.0" },
    "prepend-autoloader": false
  }
}
EOF

COMPOSER_ARGS=(update --no-dev --optimize-autoloader --no-plugins --no-interaction)
if command -v composer >/dev/null 2>&1; then
  (cd "$WORK_DIR" && composer "${COMPOSER_ARGS[@]}")
else
  # 生成物をホストのユーザーで書き換え・削除できるよう、コンテナもホストのユーザーで動かす
  docker run --rm --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
    -v "$WORK_DIR":/app -w /app composer:2 "${COMPOSER_ARGS[@]}"
fi

patch_sdk() {
  local lib="$1/elestyle/elepay-php-sdk/lib"

  # 型付きでデフォルト null の引数(例: "HeaderSelector $selector = null,")に ? を付ける
  find "$lib" -name '*.php' -exec perl -pi -e 's/(^\s*|[(,]\s*)([A-Za-z_\\][\w\\]*) (\$\w+ = null)(?=\s*[,)]|\s*$)/$1?$2 $3/g' {} +

  # SDK の版を上げて書き方が変わると置換が黙って空振りするため、置換後に残りが無いことを確かめる
  local remaining
  remaining="$(grep -rEn '(^\s*|[(,]\s*)[A-Za-z_\\][A-Za-z0-9_\\]* \$[A-Za-z0-9_]+ = null' "$lib" || true)"
  if [ -n "$remaining" ]; then
    echo "エラー: 暗黙の nullable 引数が残っています:" >&2
    echo "$remaining" >&2
    exit 1
  fi
  if ! grep -rq '?array $data = null' "$lib"; then
    echo "エラー: SDK のパッチが一箇所も適用されていません。SDK の変更を確認してください。" >&2
    exit 1
  fi
}

patch_sdk "$WORK_DIR/vendor"

rm -rf Resource/vendor
mv "$WORK_DIR/vendor" Resource/vendor

echo "Resource/vendor を再生成しました。"
