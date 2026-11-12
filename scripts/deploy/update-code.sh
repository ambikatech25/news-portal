#!/bin/sh
# Put the latest CityCharcha code (our child theme and our plugin) on the live server.
# Run over SSH on Hostinger:
#
#   sh ~/citycharcha/scripts/deploy/update-code.sh ~/domains/citycharcha.com/public_html
#
# It updates the git copy (~/citycharcha), then copies portal-child and portal-core into
# the site's wp-content. Nothing else on the site is touched (uploads, other plugins, settings).

set -e
SITE="${1:?Give the WordPress folder, e.g. ~/domains/citycharcha.com/public_html}"
REPO=$(cd "$(dirname "$0")/../.." && pwd)

[ -f "$SITE/wp-config.php" ] || { echo "No wp-config.php in $SITE - wrong folder?"; exit 1; }

if [ -d "$REPO/.git" ]; then
  echo "== Getting the latest code from GitHub"
  git -C "$REPO" pull --ff-only
else
  echo "== No git copy here: using the files uploaded to $REPO"
fi

copy() {
  # $1 = path inside wp-content. Copy into a new folder, then swap: visitors never see a half-copied folder.
  src="$REPO/wp-content/$1"
  dst="$SITE/wp-content/$1"
  rm -rf "$dst.new"
  cp -R "$src" "$dst.new"
  rm -rf "$dst.old"
  [ -d "$dst" ] && mv "$dst" "$dst.old"
  mv "$dst.new" "$dst"
  rm -rf "$dst.old"
  echo "   updated wp-content/$1"
}

echo "== Copying our theme and plugin"
copy themes/portal-child
copy plugins/portal-core

echo "== Done."
[ -d "$REPO/.git" ] && git -C "$REPO" log --oneline -1 || true
