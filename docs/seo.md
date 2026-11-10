# SEO (search engines)

Settings: `scripts/setup-seo.php` (Rank Math). Code: `portal-core/includes/seo.php`.
Test: `tests\test-seo-security.ps1`.

## What is set up

| Item | Setting |
|---|---|
| Home page title / description | "CityCharcha \| News and community voices…" and a one-line description |
| Story titles | `Headline \| CityCharcha` |
| Organisation | CityCharcha, a company (not a person); add the logo in Rank Math → Titles & Meta → Local SEO once you have it |
| Structured data | News-desk stories: **NewsArticle**; Community Blogs: **BlogPosting** (honest labels; NewsArticle is what Google News / Top Stories look for) |
| Sitemaps | `/sitemap_index.xml`: stories, pages, sections, writers. Not included: pending, rejected, taken-down stories, My Account, Write, Edit story |
| In Google | Stories, sections, writer profiles (who wrote it builds trust). **Not**: tag pages, date pages, image pages, My Account / Write / Edit story, search results |
| robots.txt | Points to the sitemap |
| Rank Math modules | Only Sitemap, Schema, Link counter, SEO analysis. Off: shop/forum, AI writer, instant indexing (send content out or need accounts) |

## Owner steps after going live (Task 18/19)

1. **Google Search Console** through Site Kit: wp-admin → **Site Kit → Start setup** → sign in with
   the company Google account → allow → Site Kit verifies the site and connects Search Console.
2. Search Console → **Sitemaps** → enter `sitemap_index.xml` → **Submit**.
3. Each story page: in the editor, the Rank Math panel (top right icon) shows a score; aim for a clear
   headline and the first paragraph answering who/what/where/when. Editors do not need to chase 100.
4. **Google News** (optional): https://publishercenter.google.com → add publication → CityCharcha.
   A dedicated *News sitemap* is a Rank Math **Pro** (paid) feature; not needed to start, ask before buying.

## Found while setting up

- Rank Math still had the old name "News Portal" and called the site a person: fixed.
- Rank Math's "exclude roles" option for the writer sitemap builds a broken database query when one
  role is chosen, and silently drops the whole writer sitemap. Left empty (members have no stories anyway).
- Rank Math builds a story's default structured data through a separate hook; portal-core listens to
  both, so the NewsArticle / BlogPosting rule always applies.
