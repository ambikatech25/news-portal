# Hindi + English (Task 18A)

CityCharcha is **Hindi first**: `citycharcha.com/` is Hindi, `citycharcha.com/en/` is English.
An **English | हिन्दी** link in the menu opens the same page or story in the other language.
Built with Polylang (free) plus small additions in portal-core (`includes/languages.php`).

| What | Rule |
|---|---|
| News, Region, Industry, Logistics, Crime | Published in **both** languages |
| Community Blogs | In the **writer's language** (a translation is optional) |
| Hindi versions | Always **checked by a Hindi writer/editor** before publishing (no unchecked machine translation) |
| Old English addresses | Still work: `/write/` sends readers to `/en/write/` |

## Sections and pages

| English | Hindi |
|---|---|
| News `/category/news/` (English site: `/en/category/news/`) | समाचार `/category/samachar/` |
| Region | क्षेत्र `/category/kshetra/` |
| Industry | उद्योग `/category/udyog/` |
| Logistics | लॉजिस्टिक्स `/category/logistics-hindi/` |
| Crime | अपराध `/category/apradh/` |
| Community Blogs | सामुदायिक ब्लॉग `/category/samudayik-blog/` |
| Case Files | केस फ़ाइल्स `/category/case-files-hindi/` |
| Community, Send news, Write, My Account | समुदाय `/samuday/`, खबर भेजें `/khabar-bhejen/`, लिखें `/likhen/`, मेरा खाता `/mera-khata/` |
| Legal pages | Hindi pages exist with a short "Hindi text coming" note and a link to the English page, until the lawyer's Hindi text arrives |

The editor-only rule covers **both** languages: writers cannot post in समाचार, अपराध or केस फ़ाइल्स either.

## For editors: the other-language version of a story

1. Open the published (or draft) story in wp-admin.
2. Right sidebar → **Languages** panel → next to the other language click **+**.
3. A new, linked story opens in that language. Write (or paste and correct) the translation.
   Section, picture and the editorial fields (Verified, FIR number, police station, Breaking,
   Sensitive, Premium) are shared between the two versions automatically.
4. A Hindi writer/editor checks the Hindi version → **Publish**.

In **Posts**, the list shows a column per language with a ✓ (exists) or **+** (add it).

**Web addresses of Hindi stories** are made from the Hindi title in English letters
(`रायपुर के पुराने बाज़ार` → `raipur-ke-purane-bazar`), short and readable when shared.

## For writers

The **Write** page asks **"Language of your story"** (Hindi by default on the Hindi site). Write in
the language you are most comfortable with; editors may add the other version.

## The interface words (buttons, labels, messages)

- Hindi texts live in **`scripts/hindi-translations.php`** (English → Hindi, easy to read and correct).
  Editor/admin screens stay English for now.
- After changing it (or after code changes add new texts), the developer rebuilds the files WordPress
  loads (commands at the top of `scripts/build-hindi-translations.php`) and deploys the code.
- WordPress itself, LiteSpeed Cache and Polylang have official Hindi packs (installed by
  `build-site.sh`). Kadence and WP User Frontend have none: their few reader-visible words are
  translated in portal-core.

## For the app (REST API)

Add `?lang=hi` or `?lang=en` to a list (`/wp/v2/posts`, `/wp/v2/categories`, `/wp/v2/tags`,
`/portal/v1/community`, …); without it every language is listed. Each story has
`"lang": "hi"` and `"translations": {"hi": 12, "en": 15}` (published versions only). A new story
sent without `lang` takes the language of its sections.

## Other rules

- **Story switch:** under the headline, **हिन्दी | English** appears only when the story is
  published in both languages (a draft translation does not count).
- **Series** take the language of their stories: an English series opens at `/en/series/…`, a Hindi
  one at `/series/…`; the other address redirects there.
- **Writer profiles, Following feed, series parts** show a writer's stories in every language.
- **Following a tag** includes its other-language version (Raipur and रायपुर).
- **Bio:** writers fill in one bio; it shows on both language sites unless an English bio is added
  separately in wp-admin → Users.

## Switching the live site (with the owner's OK)

1. Deploy the code, then run `setup-languages.php` **without** `hindi-default`: languages, Hindi
   sections, pages and menus are created; the site stays English-first meanwhile.
2. Create the Hindi versions of the published stories as drafts; Hindi writers check and publish them.
3. Run `setup-languages.php hindi-default`: Hindi becomes the main language; English moves to `/en/`.
4. Check the Hindi and English home pages, a story in both languages and the switch. Clear the cache.
