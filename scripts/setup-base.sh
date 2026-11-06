#!/bin/sh
# Task 2: base WordPress install.
# Safe to run more than once (it skips steps that are already done).
#
# Run from the project folder:
#   docker compose run --rm --entrypoint sh wpcli /scripts/setup-base.sh

set -e

echo "== 1. Install WordPress core (only if not installed yet)"
if wp core is-installed 2>/dev/null; then
  echo "Already installed, skipping."
  FRESH_INSTALL=0
else
  wp core install \
    --url="$WP_SITE_URL" \
    --title="$WP_SITE_TITLE" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL" \
    --skip-email
  FRESH_INSTALL=1
fi

echo "== 2. Site settings"
wp option update blogname "$WP_SITE_TITLE"
wp option update blogdescription "News and community voices from Chhattisgarh and central India"
wp option update timezone_string "Asia/Kolkata"
wp option update date_format "j F Y"
wp option update time_format "g:i A"
wp option update start_of_week 1
# Nobody can register yet; sign-up is switched on in Task 10.
wp option update users_can_register 0
wp option update default_role subscriber

echo "== 3. Permalinks: /category/post-name/"
wp rewrite structure '/%category%/%postname%/'
wp rewrite flush --hard

echo "== 4. Remove sample content (only right after a fresh install)"
if [ "$FRESH_INSTALL" = "1" ]; then
  wp comment delete 1 --force # comment first: deleting the post also deletes its comments
  wp post delete 1 --force #deletes a post or page by ID; --force skips the trash
  wp plugin delete akismet hello
  wp theme delete twentytwentythree
  wp theme list
fi

echo "== 5. Parent theme (Kadence) and our child theme"
wp theme is-installed kadence || wp theme install kadence
wp theme activate portal-child

echo "== Done"
wp option get timezone_string
wp option get permalink_structure
wp theme list --status=active --fields=name,status,version
