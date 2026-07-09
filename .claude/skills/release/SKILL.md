---
name: release
description: Full workflow and gotcha checklist for publishing a new version of the elepay EC-CUBE 4.2/4.3 plugin. Use when the user wants to release / cut a new version / publish a GitHub Release / tag / bump the version / re-publish this plugin. Covers the single version source, release.sh packaging, the tag-vs-Release-title naming difference, gh publishing, and verification.
---

# elepay EC-CUBE Plugin Release Guide

Standard workflow to publish a new version of this plugin (repo `elestyle/elepay-eccube4-plugin`). Follow the steps in order; read the gotchas first.

## ⚠️ Gotchas (read first)

1. **The git tag and the Release title use different prefixes** (easiest to trip on):
   - git **tag** = bare version, **no** `v`, e.g. `1.3.1`
   - Release **title** = **with** `v`, e.g. `v1.3.1`
   - So `gh release view v1.3.1` fails with `release not found`; you must query by tag: `gh release view 1.3.1`.
2. **The version lives in exactly one place**: the `version` field in `composer.json`. After editing, run `grep -rn <new-version>` to confirm there is nothing else to update — don't go hunting through other files.
3. **The `.tar.gz` is not committed**: it is `.gitignore`d and shipped only as a Release asset. Local old tarballs are build artifacts — delete them freely.
4. **Pin the repo in gh commands**: the remote uses the SSH alias `github.com-elestyle`, so pass `-R elestyle/elepay-eccube4-plugin` explicitly to avoid default-repo ambiguity.
5. **`jq` is required**: `release.sh` reads the version from `composer.json` via `jq` to build the archive name.

## Release steps

Example: releasing `X.Y.Z` (shown as `1.3.1`).

### 1. Bump the version and commit

Edit the `"version"` field in `composer.json` to the new version, then confirm it is the only reference:

```bash
grep -rn "X.Y.Z" --include="*.php" --include="*.json" --include="*.yaml" --include="*.twig" .
# Expect a single hit in composer.json
```

Commit (Conventional Commits; no AI attribution/traces of any kind):

```bash
git add composer.json
git commit -m "build(core): Update package version to X.Y.Z"
```

### 2. Build the release package

```bash
./release.sh
```

- Output: `elepay-eccube4-plugin-vX.Y.Z.tar.gz` (the `v` in the filename is added by the script).
- The script packages per the EC-CUBE plugin spec: files sit at the **archive root**, and `.git` / `.DS_Store` / `.gitignore` / `docs` / `.idea` etc. are excluded. It also self-checks that no excluded item slipped in.

### 3. Push the commit

```bash
git push origin master
```

### 4. Create the GitHub Release

Note the tag has no `v` and the title has `v`:

```bash
gh release create X.Y.Z \
  --target master \
  --title "vX.Y.Z" \
  --notes "elepay-eccube4-plugin for eccube 4.2/4.3" \
  -R elestyle/elepay-eccube4-plugin \
  elepay-eccube4-plugin-vX.Y.Z.tar.gz
```

- This **creates the tag `X.Y.Z`** on the latest master commit and uploads the tarball asset.
- `--notes` reuses the fixed text used by past versions: `elepay-eccube4-plugin for eccube 4.2/4.3`.
- The newest non-prerelease automatically becomes Latest.

### 5. Verify

```bash
# Check asset, title, tag, and that it is not a draft/prerelease
gh release view X.Y.Z -R elestyle/elepay-eccube4-plugin \
  --json name,tagName,isDraft,isPrerelease,body,assets \
  --jq '{name,tagName,isDraft,isPrerelease,body,assets:[.assets[]|{name,size}]}'

# Confirm it is now Latest
gh release list -R elestyle/elepay-eccube4-plugin \
  --json tagName,isLatest --jq '.[]|select(.isLatest)|.tagName'

# Confirm the tag points at the pushed commit
git ls-remote --tags origin X.Y.Z
```

Expected: `name = vX.Y.Z`, `tagName = X.Y.Z`, `isDraft=false`, `isPrerelease=false`, asset `elepay-eccube4-plugin-vX.Y.Z.tar.gz`, and Latest = `X.Y.Z`.

## Naming conventions cheat sheet

| Item | Value | Example |
|------|-------|---------|
| composer.json version | `X.Y.Z` | `1.3.1` |
| git tag | `X.Y.Z` (no v) | `1.3.1` |
| Release title | `vX.Y.Z` (with v) | `v1.3.1` |
| Package filename | `elepay-eccube4-plugin-vX.Y.Z.tar.gz` | `...-v1.3.1.tar.gz` |
| Release notes | fixed text | `elepay-eccube4-plugin for eccube 4.2/4.3` |
| Commit message | `build(core): Update package version to X.Y.Z` | — |
