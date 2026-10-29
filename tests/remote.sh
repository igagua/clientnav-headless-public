#!/bin/sh
# Runs on the test machine, started by bin/test-remote.sh. Checks the plugin's PHP, then builds a throwaway
# WordPress (SQLite, no root needed) in ~/cnh-test and tries the content door in it, with and without PR Register.
# Deletes the folder again unless KEEP=1. Needs: Ubuntu with apt-get, dpkg-deb, curl, python3.
T=$HOME/cnh-test
cleanup() {
	[ "${KEEP:-0}" = 1 ] && { echo "kept: $T"; return; }
	rm -rf "$T"; echo "cleaned up: $T removed"
}
trap cleanup EXIT
set -e
mkdir -p "$T/debs" "$T/dl" "$T/php" "$T/bin"; cd "$T"
SRC=$T/clientnav-headless

# PHP 8.5 from Ubuntu's packages, unpacked here.
(cd debs && apt-get download php8.5-cli php8.5-common php8.5-mbstring php8.5-sqlite3 php8.5-xml php8.5-curl libargon2-1 >/dev/null)
for d in debs/*.deb; do dpkg-deb -x "$d" php; done
ED=$(ls -d "$T"/php/usr/lib/php/2*/ | head -1)
{ echo "extension_dir=$ED"; for e in mbstring tokenizer ctype pdo pdo_sqlite sqlite3 xml dom simplexml xmlwriter curl phar fileinfo iconv posix; do echo "extension=$e.so"; done; echo "memory_limit=512M"; echo "date.timezone=UTC"; echo "error_reporting=24575"; } > php.ini
cat > bin/php <<EOS
#!/bin/sh
export PHPRC="$T/php.ini" PHP_INI_SCAN_DIR= LD_LIBRARY_PATH="$T/php/usr/lib/x86_64-linux-gnu"
exec "$T/php/usr/bin/php8.5" "\$@"
EOS
cat > bin/wp <<EOS
#!/bin/sh
exec "$T/bin/php" "$T/dl/wp-cli.phar" --path="$T/site" "\$@"
EOS
chmod +x bin/php bin/wp; PATH=$T/bin:$PATH
php -v | head -1
failed=0

echo "== syntax"
n=0; for f in $(find "$SRC/plugin/clientnav-headless" "$SRC/tests" -name '*.php'); do php -l "$f" >/dev/null 2>&1 || { php -l "$f"; failed=1; }; n=$((n+1)); done; echo "$n files"
if command -v node >/dev/null 2>&1; then
	echo "== editor script ($(node -v))"
	node --test "$SRC"/tests/*.test.mjs 2>&1 | grep -E '^# (tests|pass|fail)' | tr '\n' ' '; echo
	node --test "$SRC"/tests/*.test.mjs >/dev/null 2>&1 || failed=1
else
	echo "== editor script: no node on this machine, run npm test where the plugin is built"
fi
echo "== unit tests"
for m in "" connector connector-empty constant filter; do php "$SRC/tests/front-url.php" $m || failed=1; done
for m in "" full cms other-front empty throws notfound; do php "$SRC/tests/seo.php" $m || failed=1; done

# WordPress, WP-CLI and the SQLite plugin, each checked against its published checksum where there is one.
cd dl
curl -fsSL -o wordpress.tar.gz https://wordpress.org/latest.tar.gz
[ "$(sha1sum wordpress.tar.gz | cut -d' ' -f1)" = "$(curl -fsSL https://wordpress.org/latest.tar.gz.sha1)" ] || { echo "WordPress download does not match its checksum"; exit 1; }
curl -fsSL -o wp-cli.phar https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar
[ "$(sha512sum wp-cli.phar | cut -d' ' -f1)" = "$(curl -fsSL https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar.sha512 | cut -d' ' -f1)" ] || { echo "WP-CLI download does not match its checksum"; exit 1; }
curl -fsSL -o sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
cd "$T" && tar xzf dl/wordpress.tar.gz && mv wordpress site && cd site
(cd wp-content/plugins && python3 -m zipfile -e "$T/dl/sqlite.zip" .)
cp wp-content/plugins/sqlite-database-integration/db.copy wp-content/db.php
sed -i "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$PWD/wp-content/plugins/sqlite-database-integration#; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" wp-content/db.php
wp config create --dbname=cnh --dbuser=x --dbpass=x --skip-check --quiet
wp config set WP_DEBUG true --raw --quiet; wp config set WP_DEBUG_LOG true --raw --quiet; wp config set WP_DEBUG_DISPLAY false --raw --quiet
wp config set CLIENTNAV_FRONT_URL https://www.example.test --quiet
wp core install --url=http://127.0.0.1:8090 --title="Headless Test" --admin_user=tester --admin_email=tester@example.test --skip-email --quiet >/dev/null
wp rewrite structure '/%postname%/' --quiet; wp option update blogdescription "Testing ground" --quiet
ln -sfn "$SRC/plugin/clientnav-headless" wp-content/plugins/clientnav-headless
mkdir -p wp-content/mu-plugins && cp "$SRC/tests/fixtures/test-types.php" wp-content/mu-plugins/
wp rewrite flush --quiet
echo "WordPress $(wp core version)"
set +e
run_tests() {
	out=$("$@" 2>&1)
	echo "$out" | tail -25
	echo "$out" | grep -q 'passed, 0 failed$' || failed=1
}
wp plugin activate clientnav-headless --quiet || failed=1
echo "== content door, PR Register absent"
CNH_PRR=0 run_tests wp eval-file "$SRC/tests/wordpress.php"
echo "== lists, fields, terms, authors"
run_tests wp eval-file "$SRC/tests/wordpress-lists.php"
echo "== kept answers, big lists, refresh, feeds"
CNH_PRR=0 run_tests wp eval-file "$SRC/tests/wordpress-u1.php"
echo "== wp clientnav-headless tidy (the one-time clean-up after an update)"
if wp clientnav-headless tidy 2>&1 | grep -q "^Success: .* expired and .* over the most removed"; then echo "1 passed, 0 failed"; else echo "FAIL  wp clientnav-headless tidy"; echo "0 passed, 1 failed"; failed=1; fi
echo "== archives, search feed, site add-on hooks, abilities"
run_tests wp eval-file "$SRC/tests/wordpress-u2.php"
echo "== forms: described, and judged by Anti-Spam"
run_tests wp eval-file "$SRC/tests/wordpress-forms.php"
echo "== languages (a stand-in for Translate)"
run_tests wp eval-file "$SRC/tests/wordpress-lang.php"
echo "== closed door, closed REST API, pair check"
run_tests wp eval-file "$SRC/tests/wordpress-door.php"
echo "== click-to-edit marks, preview link"
run_tests wp eval-file "$SRC/tests/wordpress-marks.php"
echo "== a menu put in another place refreshes the front end"
run_tests wp eval-file "$SRC/tests/wordpress-menuplace.php"
echo "== pictures and files in the text, as paths when asked"
run_tests wp eval-file "$SRC/tests/wordpress-media.php"
echo "== several types in one list, ordering by a named field"
run_tests wp eval-file "$SRC/tests/wordpress-multitype.php"
echo "== excerpts as WordPress makes them, the posts page by its address"
run_tests wp eval-file "$SRC/tests/wordpress-excerpt.php"
echo "== page make-ups (news home pages)"
run_tests wp eval-file "$SRC/tests/wordpress-layouts.php"
echo "== block set offered"
CNH_BLOCKS=on run_tests wp eval-file "$SRC/tests/wordpress-blocks.php"
echo "== pairing a front end"
run_tests wp eval-file "$SRC/tests/wordpress-pair.php"
echo "== search index kept"
run_tests wp eval-file "$SRC/tests/wordpress-search.php"
wp option update clientnav_headless '{"blocks":"off","search_index":"off"}' --format=json --quiet
echo "== block set switched off"
CNH_BLOCKS=off run_tests wp eval-file "$SRC/tests/wordpress-blocks.php"
echo "== search index switched off"
CNH_SEARCH=off run_tests wp eval-file "$SRC/tests/wordpress-search.php"
wp option delete clientnav_headless --quiet
if [ -f "$T/pr-register/pr-register.php" ]; then
	ln -sfn "$T/pr-register" wp-content/plugins/pr-register
	wp plugin activate pr-register --quiet || failed=1
	echo "== content door, PR Register $(wp plugin get pr-register --field=version 2>/dev/null) active"
	CNH_PRR=1 run_tests wp eval-file "$SRC/tests/wordpress.php"
	echo "== SEO words as plain text, PR Register active"
	run_tests wp eval-file "$SRC/tests/wordpress-seo-plain.php"
else
	echo "== PR Register's source was not sent along: that part is SKIPPED"
fi
echo "== languages with the real Translate"
if [ -f "$T/clientnav-translate/clientnav-translate.php" ]; then
	ln -sfn "$T/clientnav-translate" wp-content/plugins/clientnav-translate
	if wp plugin activate clientnav-translate --quiet; then
		wp option update cntr_settings '{"languages":["es"],"default_language":"en"}' --format=json --quiet
		run_tests wp eval-file "$SRC/tests/wordpress-lang-real.php"
		wp plugin deactivate clientnav-translate --quiet
	else
		echo "Translate did not activate"; failed=1
	fi
else
	echo "Translate's source was not sent along: SKIPPED"
fi
echo "== podcasts through Client Navigator Podcast"
if [ -f "$T/clientnav-podcast/clientnav-podcast.php" ]; then
	ln -sfn "$T/clientnav-podcast" wp-content/plugins/clientnav-podcast
	if wp plugin activate clientnav-podcast --quiet; then
		run_tests wp eval-file "$SRC/tests/wordpress-podcast.php"
		wp plugin deactivate clientnav-podcast --quiet
	else
		echo "Podcast did not activate"; failed=1
	fi
else
	echo "Podcast's source was not sent along: SKIPPED"
fi
echo "== Elementor pages"
if wp plugin install elementor --activate --quiet >/dev/null 2>&1; then
	run_tests wp eval-file "$SRC/tests/wordpress-elementor.php"
	wp plugin deactivate elementor --quiet
else
	echo "Elementor could not be installed from wordpress.org: SKIPPED"; failed=1
fi
for addon in "$T"/addon-src/*/; do
	[ -d "$addon" ] || continue
	slug=$(basename "$addon")
	echo "== site add-on $slug"
	ln -sfn "$addon" "wp-content/plugins/$slug"
	if wp plugin activate "$slug" --quiet; then
		for t in "$addon"tests/wordpress*.php; do
			[ -f "$t" ] && run_tests wp eval-file "$t"
		done
		wp plugin deactivate "$slug" --quiet
	else
		echo "$slug did not activate"; failed=1
	fi
done
logged=$(grep -c 'clientnav-headless' wp-content/debug.log 2>/dev/null); logged=${logged:-0}
echo "== lines in WordPress's debug log that name the plugin: $logged"
[ "$logged" = 0 ] || { grep 'clientnav-headless' wp-content/debug.log | sed 's/^\[[^]]*\] //' | cut -c1-240 | sort | uniq -c | head -8; failed=1; }
[ $failed = 0 ] && echo "ALL TESTS PASSED" || echo "SOME TESTS FAILED"
exit $failed
