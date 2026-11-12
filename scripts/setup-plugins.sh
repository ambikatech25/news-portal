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
    # Up to 3 tries: a download can fail once for no lasting reason (seen in the Task 18 rehearsal).
    tries=0
    until wp plugin install "$slug" --quiet; do
      tries=$((tries + 1))
      if [ "$tries" -ge 3 ]; then
        echo "ERROR: could not install $slug after 3 tries" >&2
        wp plugin install "$slug"   # once more without --quiet, to show the reason
        exit 1
      fi
      echo "   retrying $slug ($tries)..."
      sleep 5
    done
  fi
done

echo "== Activating (our own plugin portal-core in the same command)"
# All in ONE command. Found in the Task 18 rehearsal: on a fresh site Nextend Social Login
# redirects to its welcome page the first time it loads, which ends the NEXT wp command early
# while still reporting success - so a separate "activate portal-core" silently never ran.
# shellcheck disable=SC2086
wp plugin activate $SLUGS portal-core

echo "== Checking every plugin really is active"
for slug in $SLUGS portal-core; do
  if ! wp plugin is-active "$slug"; then
    echo "   $slug is not active yet - activating again"
    wp plugin activate "$slug"
    wp plugin is-active "$slug" || { echo "ERROR: $slug could not be activated" >&2; exit 1; }
  fi
done

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
