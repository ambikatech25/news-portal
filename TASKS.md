# Build Tasks: CityCharcha

Work top to bottom. Each task lists what "done" means. Tick `[x]` when it is done and tested.

## Phase 1: Local setup
- [x] **1. Project skeleton.** Git repo, `.gitignore`, `.env.example`, `docker-compose.yml` (WordPress, MariaDB, WP-CLI, phpMyAdmin). *Done:* site opens at http://localhost:8080, and WP-CLI works.
- [x] **2. Base install.** Site title, timezone Asia/Kolkata, permalinks `/%category%/%postname%/`, delete sample content, install the parent theme and create `portal-child`. *Done:* the child theme is active.
- [x] **3. Free plugins.** Install and activate the approved free plugins (see CLAUDE.md) with WP-CLI; write a short note on each plugin's settings in `docs/plugins.md`. *Done:* all active, no errors.

## Phase 2: Structure
- [x] **4. Categories and menus.** Create the categories, main menu, footer menu and legal pages (placeholder text). *Done:* menus visible.
- [x] **5. Roles.** Confirm the role mapping, allow Contributors to upload images, and block Editor-only categories for Contributors and Authors in `portal-core`. *Done:* a test Contributor cannot pick News, Crime or Case Files, even through the REST API.
- [x] **6. Custom fields.** Register the `portal_*` post meta with REST support and add an editor sidebar panel for Verified, FIR number, police station, Sensitive and Premium. *Done:* the fields save and appear in `/wp-json/wp/v2/posts`.

## Phase 3: Look and feel
- [x] **7. News home page.** Top story, breaking strip, section blocks, latest, trending, Community Blogs block. Mobile first. *Done:* looks like a news site on phone and desktop.
- [x] **8. Article page.** Headline, Verified badge (FIR and police station), author card, read time, share buttons, related posts, Report button. *Done:* the badge shows only when verified.
- [x] **9. Author profile pages.** Photo, bio, posts, follower count. *Done:* public profile URL works.

## Phase 4: Community
- [x] **10. Sign-up and login.** Email plus Google sign-in, profile editing, consent checkbox for Terms and Privacy. *Done:* a new user can register, and the default role is Subscriber. _Google sign-in verified locally on 2026-11-07 (Member role, consent recorded). Before launch: add the live domain and publish the Google app (`docs/accounts.md`, step E)._
- [x] **11. Write a post.** Front-end submission page (title, body, images, category limited to allowed ones, tags) → Pending review. "Become a writer" request moves Subscriber to Contributor after admin approval. *Done:* a Contributor's submission lands in Pending.
- [x] **12. Engagement.** Comments with spam filter, likes, follow author or tag, bookmarks. *Done:* all work for logged-in users.

## Phase 5: Editorial control
- [x] **13. Review workflow.** PublishPress statuses and notifications; reject with a reason emailed to the writer. *Done:* an editor can approve, request changes or reject.
- [x] **14. Sensitive two-step approval.** Block publishing when `portal_sensitive` is set and there is no different second approver. *Done:* publishing is blocked with a clear message.
- [x] **15. Image safety.** EXIF/GPS stripping on upload, plus a short guide in `docs/blurring.md` on how editors blur faces and number plates. *Done:* an uploaded test photo has no GPS data.
- [x] **16. Reports, grievances and audit log.** Report button stores reports; admin screen lists them; grievance form; log of admin actions. *Done:* a report and a takedown both appear in the log.

## Phase 6: Go live
- [x] **17. SEO, speed, security.** Rank Math sitemaps, LiteSpeed Cache, Cloudflare guide, Wordfence, UpdraftPlus backups to Google Drive. *Done:* PageSpeed mobile score ≥ 80 on the home page. _Lighthouse mobile (local): 100. Confirm with PageSpeed on the live site in Task 18 (`docs/speed.md`)._

### Community reading experience (before launch, agreed 2026-11-12)
- [x] **17A. Series.** Writers group posts into a series (Part 1, 2, 3…) with a cover and description; series page with contents list; "Part X of Y" and "Next part →" on each post; series in the API. *Done:* a writer creates a 3-part series and a reader can move through it in order. _`tests/test-series.ps1` 12/12; guide `docs/series.md`._
- [x] **17B. Reading comfort.** Larger, comfortable text with a Hindi-friendly font, reading progress bar, estimated time, clean reading mode (hide distractions), for every story. *Done:* reading mode works on phone and desktop and PageSpeed mobile stays ≥ 80. _`tests/test-reading.ps1` 10/10; checked in a browser at phone and desktop size (no layout shift, no font downloads); PageSpeed to confirm on the live site (Task 18). Also: Follow button by the writer's name, writer card above Like/Save/Comments, comments open in place. Guide `docs/reading.md`. 23 Nov 2026: reading mode replaced by always-visible A− A+ · Light / Sepia / Dark under the headline; the whole story page follows the colours._
- [x] **17C. Community home.** A Community page: trending writers, new series, editors' picks, newest community posts. *Done:* the page fills itself from published content and editors can pick stories. _`tests/test-community.ps1` 11/11; page /community/ in the main menu; guide `docs/community.md`._
- [x] **17D. Reader news tips.** "Send news" for every signed-in member (header and My Account): what happened, where, when, photos/video (GPS stripped), how they know, phone for editors only, stay anonymous or not. Tips go to a private Reader tips queue for editors (never public); an editor verifies and writes it up as a News story credited "Based on a reader report" (or the reader's name, if they agree), or declines with a reason; the reader is e-mailed and sees the result in My Account → My tips. Rate-limited; logged in the audit log. *Done:* a member sends a tip with a photo, an editor turns it into a published News story, and the member sees "Published" in My tips. _`tests/test-tips.ps1` 13/13; guide `docs/tips.md`. Video as links only (video files keep their location); live upload limit must be ≥ 10 MB (Task 18)._

- [x] **18. Deploy to Hostinger.** Hosting: Hostinger **Unlimited** web hosting, 48 months (bought 2026-11-16; LiteSpeed, hPanel, SSH, daily backups); domain registered at Hostinger for 3 years (registration to be completed and e-mail verified). Fresh site built by `scripts/deploy/build-site.sh` (rehearsed 2026-11-10), SSL, mailboxes, SMTP, cron, upload limit ≥ 10 MB, `www` and http redirect to https://citycharcha.com. *Done:* the live site works over https. _Live 16-17 Nov 2026: build with no errors, 14 plugins, https + redirects, security headers, LiteSpeed cache, production settings, hPanel cron every 5 min (proven), SMTP via no-reply@ (test mail in the inbox; SPF, DKIM, DMARC set), grievance e-mail officer@. Notes and lessons in `docs/deploy.md`. Google sign-in on the live site, 2FA, Drive backups, Search Console, PageSpeed: Task 19._
- [ ] **19. Launch checklist.** Test every role, legal pages filled in, Grievance Officer named, AdSense applied for, Ministry of I&B details reminder. *Done:* checklist signed off by the owner.

- [ ] **18A. Hindi + English site (agreed 21 Nov 2026).** With Polylang (free): **Hindi is the main language** (`citycharcha.com/`), **English at `/en/`**; an **English | हिंदी** switch on every page that opens the same story in the other language. News, Region, Industry, Logistics and Crime in both languages; Community Blogs in the writer's own language (translation optional). Steps:
  1. Languages and addresses: Hindi default (no prefix), English `/en/`; existing English stories marked English.
  2. Translate sections (समाचार, क्षेत्र, उद्योग, लॉजिस्टिक्स, अपराध, समुदाय…), menus, and pages (Community, Send news, Write, My Account, legal pages: Hindi placeholder until the lawyer's text).
  3. Hindi interface text for our theme and plugin (buttons, labels, e-mails, messages) and WordPress / plugin language packs.
  4. Our features follow the reader's language: home page, Breaking, trending, related, series, Community page, writer profiles, tips write-up, REST API (`?lang=hi|en`).
  5. Short English-letter addresses for Hindi stories (title transliterated, e.g. `/region/khaira-saur-gaon/`), never the long `%e0%a4…` form.
  6. Writing: writers pick the story's language (Hindi by default); editors get "Add Hindi / English version" on each story.
  7. Translate the 13 published stories into Hindi as **drafts** for the Hindi writers to check and publish.
  8. Google: language tags (hreflang) and sitemaps per language (Rank Math + Polylang).
  9. Built and tested locally first, then put live only with the owner's OK.
  *Done:* `citycharcha.com` opens in Hindi and `/en/` in English, the switch works on every page, the 13 stories exist in both languages, a writer can submit in either language, and all tests pass.

### After launch
- [ ] **19A. Writer page upgrade.** Cover banner, the writer's series, followers up front on profile pages. *Done:* a writer can set a banner and their series show on their profile.
- [ ] **19B. Reading lists.** Members turn Saved stories into named lists (e.g. "Steel prices", "Weekend reads"). *Done:* a member creates a list, adds stories and sees it in My Account.
- [ ] **19C. Ads and sponsors.** Ad slots (home between sections, article after 3rd paragraph and at the end, desktop sidebar) managed from a portal-core screen: Google AdSense, a sponsor banner (image, link, dates) or nothing; "Advertisement" label; lazy-loaded; no ads for Premium members or on Crime/Sensitive stories; "Sponsored" label for paid posts. *Done:* each slot shows the chosen ad, and none appear for a Premium member or on a sensitive story.

## Phase 7: Premium (around months 3–4)
- [ ] **20. Membership plugin + Razorpay.** CityCharcha Premium: ₹99/month or ₹999/year, Razorpay recurring billing, member account page. 7-day free trial that starts only when the reader subscribes with autopay (UPI AutoPay or card), first charge on day 8 unless cancelled, one trial per person. **Ask before buying the plugin.** *Done:* a test subscription in Razorpay test mode starts the trial, activates Premium, and a second trial on the same account is refused.
- [ ] **21. Paywall.** Premium posts show a ~150-word preview plus a subscribe box; Case Files and the new **Industry Intelligence** section (weekly coal/steel/scrap price tracker, freight and rake updates, tenders digest) are Premium; 7 am morning briefing e-mail list for Premium; ad-free; Premium badge; writers can mark a post Premium (editor approves); **Premium series**: first 3 parts free, Part 4 onward Premium (writer asks, editor approves), paid-part reads counted per writer. Public-safety news and corrections are never Premium. Page cache must never show a member's copy to visitors. *Done:* a non-member sees the preview and a member sees the full post.
- [ ] **22. App-ready API.** `GET /wp-json/portal/v1/me` (role and Premium status), JWT auth plugin, and API notes in `docs/api.md`. *Done:* the endpoint returns the correct data for a test user.

## Later (not now)
- **Writer subscriptions (phase 2):** readers subscribe to individual writers, with revenue share (Razorpay Route, writer KYC, TDS/GST). Start about 6 months after launch or at 500 Premium members, whichever comes first. Until then: writer Premium posts and a manual "Writer of the Month" reward.
- Newsletter sponsorship, business directory and job listings, government (Jansampark) advertising empanelment.
- Mobile app (Flutter or React Native) on the same API, with Premium sold through Google Play and Apple billing.
- Automatic AI face and plate blurring.
- Move to the full custom stack when traffic grows.
