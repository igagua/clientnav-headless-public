#!/usr/bin/env bash
# Builds the plugin and packs it as dist/clientnav-headless-<version>.zip, the file WordPress installs.
set -euo pipefail
cd "$(dirname "$0")/.."
node plugin/build.mjs >/dev/null
v=$(sed -n "s/^const VERSION = '\(.*\)';/\1/p" plugin/clientnav-headless/clientnav-headless.php)
mkdir -p dist
rm -f "dist/clientnav-headless-$v.zip"
(cd plugin && python3 -m zipfile -c "../dist/clientnav-headless-$v.zip" clientnav-headless)
echo "dist/clientnav-headless-$v.zip  $(sha256sum "dist/clientnav-headless-$v.zip" | cut -c1-64)"
