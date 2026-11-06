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
4. Open http://localhost:8080 (WordPress) and http://localhost:8081 (phpMyAdmin).

WP-CLI: `docker compose run --rm wpcli <command>`, e.g. `docker compose run --rm wpcli core version`.
