#!/usr/bin/env bash
# Runs every test on a test machine over SSH: PHP syntax, unit tests, and the content door inside a throwaway WordPress,
# once without and once with PR Register (its source is taken from PRR, default ../pr-register),
# and with Client Navigator Translate when its source is there (TRANSLATE, default ../clientnav-translate),
# and Client Navigator Podcast (PODCAST, default ../clientnav-podcast).
#
#   bin/test-remote.sh user@host          # builds ~/cnh-test there, runs, removes it again
#   KEEP=1 bin/test-remote.sh user@host   # leaves ~/cnh-test in place to look around
#   ADDON=<add-on folder> bin/test-remote.sh user@host   # also tries a site add-on beside this plugin
#
# Nothing is installed on the machine outside ~/cnh-test, and no root is needed. See tests/remote.sh.
set -euo pipefail
host=${1:?usage: bin/test-remote.sh user@host}
PRR=${PRR:-$HOME/pr-register}
cd "$(dirname "$0")/.."
ssh -o BatchMode=yes "$host" 'rm -rf ~/cnh-test && mkdir -p ~/cnh-test'
rsync -a --delete -e "ssh -o BatchMode=yes" --exclude node_modules --exclude dist --exclude .git --exclude .next ./ "$host:cnh-test/clientnav-headless/"
if [ -f "$PRR/pr-register.php" ]; then
	rsync -a --delete -e "ssh -o BatchMode=yes" --exclude dist --exclude .git --exclude node_modules "$PRR/" "$host:cnh-test/pr-register/"
fi
PODCAST=${PODCAST:-$HOME/clientnav-podcast}
if [ -f "$PODCAST/clientnav-podcast.php" ]; then
	rsync -a --delete -e "ssh -o BatchMode=yes" --exclude .git --exclude node_modules --exclude dist --exclude tests "$PODCAST/" "$host:cnh-test/clientnav-podcast/"
fi
TRANSLATE=${TRANSLATE:-$HOME/clientnav-translate}
if [ -f "$TRANSLATE/clientnav-translate.php" ]; then
	rsync -a --delete -e "ssh -o BatchMode=yes" --exclude .git --exclude node_modules "$TRANSLATE/" "$host:cnh-test/clientnav-translate/"
fi
# A site add-on (a plugin folder with tests/wordpress*.php) is tried with this plugin when ADDON names its folder.
if [ -n "${ADDON:-}" ] && [ -f "$ADDON/$(basename "$ADDON").php" ]; then
	ssh -o BatchMode=yes "$host" "mkdir -p ~/cnh-test/addon-src"
	rsync -a --delete -e "ssh -o BatchMode=yes" --exclude .git --exclude node_modules "$ADDON/" "$host:cnh-test/addon-src/$(basename "$ADDON")/"
fi
ssh -o BatchMode=yes "$host" "KEEP=${KEEP:-0} sh ~/cnh-test/clientnav-headless/tests/remote.sh"
