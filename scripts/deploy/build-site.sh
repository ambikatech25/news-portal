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

for s in design accounts writing engagement community tips review moderation seo speed security; do
  step "setup-$s.php"
  wp eval-file "$DIR/setup-$s.php"
done

step "Task 18A: Hindi + English"
# WordPress / plugin Hindi language packs (dates, comment form, login). "|| true": a pack that
# does not exist (Kadence, WP User Frontend) is skipped; portal-core translates those words itself.
wp language core install hi_IN || true
wp language plugin install --all hi_IN || true
# Languages, Hindi sections / pages / menus, Hindi as the main language. On a site that already
# has English stories, add their Hindi versions first (docs/languages.md), or the Hindi home is empty.
wp eval-file "$DIR/setup-languages.php" hindi-default

step "Final: URL rules and caches"
wp rewrite flush
wp cache flush || true

echo
echo "CityCharcha settings are in place."
wp option get home
wp plugin list --status=active --field=name | tr '\n' ' '; echo
