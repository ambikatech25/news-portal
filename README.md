# News Portal (name TBD)

News site + community blogs on WordPress. See `CLAUDE.md` for the rules and `TASKS.md` for the build plan.

## Folder layout

```
news-portal/
├── CLAUDE.md                     project rules
├── TASKS.md                      build checklist
├── docker-compose.yml            local WordPress stack
├── .env.example                  copy to .env
├── docs/                         owner + developer notes
├── wp-content/
│   ├── themes/portal-child/      our child theme (Task 2)
│   └── plugins/portal-core/      our custom plugin
└── app/                          future mobile app (later)
```

## Run it locally

1. Install **Docker Desktop** and start it.
2. In this folder: copy `.env.example` to `.env` and change the passwords.
3. `docker compose up -d`
4. Open http://localhost:8080 (WordPress), http://localhost:8081 (phpMyAdmin) and http://localhost:8025 (Mailpit: every e-mail the local site sends).

WP-CLI: `docker compose run --rm wpcli <command>`, e.g. `docker compose run --rm wpcli core version`.

## Set up a fresh local site

Run these once, in order (all are safe to run again):

```
docker compose run --rm --entrypoint sh wpcli /scripts/setup-base.sh
docker compose run --rm --entrypoint sh wpcli /scripts/setup-plugins.sh
docker compose run --rm --entrypoint sh wpcli /scripts/setup-structure.sh
docker compose run --rm -T wpcli eval-file /scripts/setup-design.php
docker compose run --rm -T wpcli eval-file /scripts/setup-accounts.php
docker compose run --rm -T wpcli eval-file /scripts/setup-writing.php
docker compose run --rm -T wpcli eval-file /scripts/setup-engagement.php
docker compose run --rm -T wpcli eval-file /scripts/setup-review.php
```

Optional sample stories for design work (local only): see `docs/home-page.md`.
What each part of the home page and article page shows, and how editors control it: `docs/home-page.md`, `docs/article-page.md`, `docs/author-profiles.md`. Sign-up, Google sign-in setup and My Account: `docs/accounts.md`. Write a story and Become a writer: `docs/writing.md`. Comments, likes, saves and follows: `docs/engagement.md`. Review workflow: `docs/review.md`.

## Tests

```
powershell -ExecutionPolicy Bypass -File tests\test-restricted-categories.ps1
powershell -ExecutionPolicy Bypass -File tests\test-custom-fields.ps1
powershell -ExecutionPolicy Bypass -File tests\test-home-page.ps1
powershell -ExecutionPolicy Bypass -File tests\test-article-page.ps1
powershell -ExecutionPolicy Bypass -File tests\test-author-profile.ps1
powershell -ExecutionPolicy Bypass -File tests\test-accounts.ps1
powershell -ExecutionPolicy Bypass -File tests\test-write-post.ps1
powershell -ExecutionPolicy Bypass -File tests\test-story-import.ps1
powershell -ExecutionPolicy Bypass -File tests\test-engagement.ps1
powershell -ExecutionPolicy Bypass -File tests\test-review.ps1
```
