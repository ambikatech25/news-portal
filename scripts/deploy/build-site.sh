#!/bin/sh
# Build (or refresh) every CityCharcha setting on a WordPress site by running all task setup
# scripts in order (Tasks 2-17). Safe to run again: each script skips or updates what exists.
#
# Run it from the WordPress folder (where wp-config.php is), after our theme and plugin files
# are in wp-content (see update-code.sh):
#
#   Live (Hostinger, over SSH):  cd ~/domains/citycharcha.com/public_html && sh ~/citycharcha/scripts/deploy/build-site.sh
#   Rehearsal (Docker):          docker compose -p ccrehearsal run --rm -T --entrypoint sh wpcli /scripts/deploy/build-site.sh
#
# On a site where WordPress is not installed yet, setup-base.sh installs it and needs:
#   WP_SITE_URL, WP_SITE_TITLE, WP_ADMIN_USER, WP_ADMIN_PASSWORD, WP_ADMIN_EMAIL

set -e
DIR=$(cd "$(dirname "$0")/.." && pwd)

step() { echo; echo "######## $1"; }

step "Task 2: base install, settings, permalinks, theme"
sh "$DIR/setup-base.sh"

step "Task 3: approved free plugins + portal-core"
sh "$DIR/setup-plugins.sh"

step "Task 4: categories, legal pages, menus"
sh "$DIR/setup-structure.sh"

for s in design accounts writing engagement community review moderation seo speed security; do
  step "setup-$s.php"
  wp eval-file "$DIR/setup-$s.php"
done

step "Final: URL rules and caches"
wp rewrite flush
wp cache flush || true

echo
echo "CityCharcha settings are in place."
wp option get home
wp plugin list --status=active --field=name | tr '\n' ' '; echo
