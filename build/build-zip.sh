#!/usr/bin/env bash
# Package an installable plugin zip for GitHub Releases.
# The zip's top-level folder must be the plugin slug so WP unpacks it correctly.
set -euo pipefail

cd "$(dirname "$0")/.."
SLUG="chatty-helpdesk"
VERSION="$(grep -m1 "Version:" chatty-helpdesk.php | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
OUT="dist/woocommerce-plugin-${VERSION}.zip"

rm -rf dist build-tmp
mkdir -p dist "build-tmp/${SLUG}"

# Ship only runtime files — never .git, dev, or build scaffolding.
rsync -a --prune-empty-dirs \
  --include='chatty-helpdesk.php' \
  --include='readme.txt' \
  --include='includes/***' \
  --include='assets/***' \
  --exclude='*' \
  ./ "build-tmp/${SLUG}/"

( cd build-tmp && zip -rq "../${OUT}" "${SLUG}" )
rm -rf build-tmp
echo "Built ${OUT}"
