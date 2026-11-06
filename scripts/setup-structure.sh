#!/bin/sh
# Task 4: categories, legal pages and menus.
# Safe to run more than once: existing categories/pages are reused, menus are rebuilt.
#
# Run from the project folder:
#   docker compose run --rm --entrypoint sh wpcli /scripts/setup-structure.sh

set -e

HOME_URL=$(wp option get home)

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

# ensure_category <slug> <name> <description>  -> prints term ID
ensure_category() {
  id=$(wp term list category --slug="$1" --field=term_id)
  if [ -z "$id" ]; then
    id=$(wp term create category "$2" --slug="$1" --description="$3" --porcelain)
  else
    wp term update category "$id" --name="$2" --description="$3" --quiet
  fi
  echo "$id"
}

PLACEHOLDER='<!-- wp:paragraph -->
<p><strong>Placeholder.</strong> The final text of this page will be written by the owner or a lawyer before launch.</p>
<!-- /wp:paragraph -->'

# ensure_page <slug> <title>  -> prints page ID (creates a published placeholder page if missing)
ensure_page() {
  id=$(wp post list --post_type=page --post_status=any --name="$1" --field=ID)
  if [ -z "$id" ]; then
    id=$(wp post create --post_type=page --post_status=publish \
      --post_title="$2" --post_name="$1" --post_content="$PLACEHOLDER" --porcelain)
  fi
  echo "$id"
}

# ---------------------------------------------------------------------------
echo "== 1. Categories"
# Turn WordPress's undeletable default category into Community Blogs, so posts
# with no category picked land in the one category writers are allowed to use.
DEFAULT_CAT=$(wp option get default_category)
wp term update category "$DEFAULT_CAT" --name="Community Blogs" --slug="community-blogs" \
  --description="Posts written by our community of readers and writers." --quiet

ensure_category news       "News"       "Verified news from our editorial desk." >/dev/null
ensure_category logistics  "Logistics"  "Transport, freight, rail and road logistics in central India." >/dev/null
ensure_category industry   "Industry"   "Coal, steel, power and industrial news from Chhattisgarh and beyond." >/dev/null
ensure_category crime      "Crime"      "Crime reports from our editorial desk, with FIR details where available." >/dev/null
ensure_category region     "Region"     "Local news from districts across Chhattisgarh and central India." >/dev/null
ensure_category case-files "Case Files" "In-depth investigations for Premium members." >/dev/null
wp term list category --fields=term_id,name,slug

# ---------------------------------------------------------------------------
echo "== 2. Legal and info pages (placeholder text)"
# Reuse WordPress's own Privacy Policy draft so its privacy settings stay linked.
PRIVACY_ID=$(wp option get wp_page_for_privacy_policy)
wp post update "$PRIVACY_ID" --post_status=publish --post_title="Privacy Policy" \
  --post_name="privacy-policy" --post_content="$PLACEHOLDER" --quiet

TERMS_ID=$(ensure_page terms-of-use "Terms of Use")
GUIDELINES_ID=$(ensure_page community-guidelines "Community Guidelines")
EDITORIAL_ID=$(ensure_page editorial-policy "Editorial & Corrections Policy")
GRIEVANCE_ID=$(ensure_page grievance-officer "Grievance Officer")
ABOUT_ID=$(ensure_page about "About Us")
wp post list --post_type=page --fields=ID,post_title,post_name,post_status

# ---------------------------------------------------------------------------
echo "== 3. Menus (rebuilt from scratch each run)"
for menu in main-menu footer-menu; do
  wp menu delete "$menu" --quiet 2>/dev/null || true
done

wp menu create "Main Menu" --porcelain >/dev/null
wp menu item add-custom main-menu "Home" "$HOME_URL/" --quiet

# Category slugs shown in the top menu, left to right (Home is added above).
# Local news first, then the portal's specialities, Crime after them so the site
# doesn't read as a crime tabloid, Community Blogs last as the "join in" link.
# Case Files (Premium) is promoted separately in Task 21, not in the main menu.
MAIN_MENU="news region industry logistics crime community-blogs"

if [ -z "$MAIN_MENU" ]; then
  echo "ERROR: MAIN_MENU is empty - add category slugs above." >&2
  exit 1
fi
for slug in $MAIN_MENU; do
  wp menu item add-term main-menu category "$(wp term list category --slug="$slug" --field=term_id)" --quiet
done

wp menu create "Footer Menu" --porcelain >/dev/null
for id in "$ABOUT_ID" "$EDITORIAL_ID" "$GUIDELINES_ID" "$TERMS_ID" "$PRIVACY_ID" "$GRIEVANCE_ID"; do
  wp menu item add-post footer-menu "$id" --quiet
done

# Kadence locations: primary = desktop header, mobile = phone menu, footer = footer.
wp menu location assign main-menu primary
wp menu location assign main-menu mobile
wp menu location assign footer-menu footer

echo "== 4. Kadence footer layout: menu in the middle row, copyright in the bottom row"
# Same setting the Customizer's Footer Builder saves (Appearance > Customize > Footer).
wp eval '
set_theme_mod( "footer_items", array(
  "top"    => array( "top_1" => array(), "top_2" => array(), "top_3" => array(), "top_4" => array(), "top_5" => array() ),
  "middle" => array( "middle_1" => array( "footer-navigation" ), "middle_2" => array(), "middle_3" => array(), "middle_4" => array(), "middle_5" => array() ),
  "bottom" => array( "bottom_1" => array( "footer-html" ), "bottom_2" => array(), "bottom_3" => array(), "bottom_4" => array(), "bottom_5" => array() ),
) );
set_theme_mod( "footer_middle_columns", "1" );
echo "footer layout saved\n";
'

echo "== Done"
wp menu list --fields=term_id,name,count,locations
