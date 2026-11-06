# Build Tasks: [PORTAL_NAME]

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
- [ ] **9. Author profile pages.** Photo, bio, posts, follower count. *Done:* public profile URL works.

## Phase 4: Community
- [ ] **10. Sign-up and login.** Email plus Google sign-in, profile editing, consent checkbox for Terms and Privacy. *Done:* a new user can register, and the default role is Subscriber.
- [ ] **11. Write a post.** Front-end submission page (title, body, images, category limited to allowed ones, tags) → Pending review. "Become a writer" request moves Subscriber to Contributor after admin approval. *Done:* a Contributor's submission lands in Pending.
- [ ] **12. Engagement.** Comments with spam filter, likes, follow author or tag, bookmarks. *Done:* all work for logged-in users.

## Phase 5: Editorial control
- [ ] **13. Review workflow.** PublishPress statuses and notifications; reject with a reason emailed to the writer. *Done:* an editor can approve, request changes or reject.
- [ ] **14. Sensitive two-step approval.** Block publishing when `portal_sensitive` is set and there is no different second approver. *Done:* publishing is blocked with a clear message.
- [ ] **15. Image safety.** EXIF/GPS stripping on upload, plus a short guide in `docs/blurring.md` on how editors blur faces and number plates. *Done:* an uploaded test photo has no GPS data.
- [ ] **16. Reports, grievances and audit log.** Report button stores reports; admin screen lists them; grievance form; log of admin actions. *Done:* a report and a takedown both appear in the log.

## Phase 6: Go live
- [ ] **17. SEO, speed, security.** Rank Math sitemaps, LiteSpeed Cache, Cloudflare guide, Wordfence, UpdraftPlus backups to Google Drive. *Done:* PageSpeed mobile score ≥ 80 on the home page.
- [ ] **18. Deploy to Hostinger.** Step-by-step migration (owner buys hosting and domain first), SSL, email, cron. *Done:* the live site works over https.
- [ ] **19. Launch checklist.** Test every role, legal pages filled in, Grievance Officer named, AdSense applied for, Ministry of I&B details reminder. *Done:* checklist signed off by the owner.

## Phase 7: Premium (around months 3–4)
- [ ] **20. Membership plugin + Razorpay.** One plan, Premium ₹99/month (optional 7-day trial), Razorpay recurring billing, member account page. *Done:* a test payment in Razorpay test mode activates Premium.
- [ ] **21. Paywall.** Premium posts show a preview plus a subscribe box; ad-free for Premium; Premium badge on profiles; daily briefing list for Premium. *Done:* a non-member sees the preview and a member sees the full post.
- [ ] **22. App-ready API.** `GET /wp-json/portal/v1/me` (role and Premium status), JWT auth plugin, and API notes in `docs/api.md`. *Done:* the endpoint returns the correct data for a test user.

## Later (not now)
- Mobile app (Flutter or React Native) on the same API, with Premium sold through Google Play and Apple billing.
- Automatic AI face and plate blurring.
- Move to the full custom stack when traffic grows.
