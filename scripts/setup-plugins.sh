#!/bin/sh
# Task 3: install and activate the approved free plugins (see CLAUDE.md).
# Safe to run more than once: installed plugins are skipped, active ones stay active.
#
# Run from the project folder:
#   docker compose run --rm --entrypoint sh wpcli /scripts/setup-plugins.sh
#
# Settings for each plugin are described in docs/plugins.md.

set -e

# WordPress.org slug            # what it is for
PLUGINS="
publishpress                    # editorial workflow and statuses
nextend-facebook-connect        # Google sign-in (Nextend Social Login)
wp-user-frontend                # front-end post submission
seo-by-rank-math                # SEO, sitemaps
litespeed-cache                 # page cache (only works on LiteSpeed servers like Hostinger)
wordfence                       # firewall, malware scan, login security
updraftplus                     # backups
antispam-bee                    # comment spam filter
polylang                        # Hindi + English
mailpoet                        # newsletter
fluentform                      # grievance and tip forms
google-site-kit                 # Analytics, Search Console, AdSense
ewww-image-optimizer            # image compression + EXIF/GPS removal
"

# Installed but left OFF until the owner has an account and enters its keys.
# (OneSignal without an App ID loads an empty script on every page and logs PHP warnings.)
INSTALL_ONLY="
onesignal-free-web-push-notifications  # browser push - needs OneSignal App ID first
"

# Keep only the first word of each line (the slug), drop comments and blanks.
SLUGS=$(echo "$PLUGINS" | sed 's/#.*//' | awk 'NF {print $1}')
INSTALL_ONLY_SLUGS=$(echo "$INSTALL_ONLY" | sed 's/#.*//' | awk 'NF {print $1}')

for slug in $SLUGS $INSTALL_ONLY_SLUGS; do
  if wp plugin is-installed "$slug"; then
    echo "-- $slug already installed"
  else
    echo "-- installing $slug"
    wp plugin install "$slug" --quiet
  fi
done

echo "== Activating"
# shellcheck disable=SC2086
wp plugin activate $SLUGS

# Our own plugin (lives in the repo, mounted by docker-compose).
wp plugin activate portal-core

echo "== Keeping these off until configured"
for slug in $INSTALL_ONLY_SLUGS; do
  if wp plugin is-active "$slug"; then
    wp plugin deactivate "$slug"
  else
    echo "-- $slug is off"
  fi
done

echo "== Result"
wp plugin list --fields=name,status,version
