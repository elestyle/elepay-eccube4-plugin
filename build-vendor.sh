#!/bin/bash
#
# Regenerates Resource/vendor (elepay-php-sdk and its dependencies) bundled with the plugin.
#
# - Dependencies are resolved for PHP 7.4. EC-CUBE 4.2 runs on PHP 7.4, so resolving them against
#   the local PHP could pull in versions that do not run on 4.2.
# - The autoloader is registered after the host's (EC-CUBE core), so that the core's version wins on a
#   class name clash and types do not diverge from the core's PSR implementations and the like.
# - elepay-php-sdk 1.2.3 triggers implicit nullable parameter deprecations on PHP 8.4+, so after generation
#   they are replaced with explicit nullable types (?Type), which are valid on PHP 7.4 too. Once the SDK ships
#   a fixed version, raise SDK_VERSION and delete patch_sdk.
#   (Model/SourceInfo.php and Model/TerminalToken*.php also trigger ArrayAccess return type warnings, but are
#   left out: they do not fully implement ModelInterface, cause a fatal error when loaded, and are unused
#   by the plugin.)
#
# Falls back to the Docker composer image when composer is not installed locally.
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
  # Run the container as the host user, so that the host user can modify and delete the output
  docker run --rm --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
    -v "$WORK_DIR":/app -w /app composer:2 "${COMPOSER_ARGS[@]}"
fi

patch_sdk() {
  local lib="$1/elestyle/elepay-php-sdk/lib"

  # Add ? to typed parameters that default to null (e.g. "HeaderSelector $selector = null,")
  find "$lib" -name '*.php' -exec perl -pi -e 's/(^\s*|[(,]\s*)([A-Za-z_\\][\w\\]*) (\$\w+ = null)(?=\s*[,)]|\s*$)/$1?$2 $3/g' {} +

  # If a newer SDK changes how the code is written, the replacement silently matches nothing, so check that none remain
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
