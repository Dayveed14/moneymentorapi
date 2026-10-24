# MoneyMentor API (PHP 8.3 + MySQL)

The backend for the MoneyMentor frontend. Same endpoint URLs as before
(`/api/login.php` etc.), now secured, Dockerised for Render, and tested.

## Layout
```
public/            web root (only this folder is reachable from the internet)
  api/*.php        one file per endpoint
  uploads/         images uploaded with the old version (served as plain files)
src/               shared code: config, DB, auth tokens, rate limiting, helpers
database/schema.sql
bin/migrate.php    creates/upgrades tables + first admin (runs on every Docker start-up)
src/migrations.php the upgrade steps; also run automatically on the first request after an update
src/modules.php    learning module + lesson ids (keep in sync with the frontend's src/data/modules.js and modulesMore.js)
src/mail.php       sending email through Brevo or Resend's HTTP API (or a local log file)
storage/           mail.log in test mode (created automatically, not served, not in git)
bin/move_images_to_cloudinary.php   one-off: moves existing images to Cloudinary
docker/            Apache, PHP and start-up config
Dockerfile, docker-compose.yml, render.yaml
```

## Endpoints
| Method | Path | Who | What |
|---|---|---|---|
| POST | `/api/signup.php` | anyone | Create account, returns `token` + `user` |
| POST | `/api/login.php` | anyone | Returns `token` + `user`. 5 failed tries / 15 min per email+IP |
| GET | `/api/me.php` | logged in | Current user (checks the token) |
| GET | `/api/get_articles.php?page&limit&category&search` | anyone | Published articles + `reading_minutes` |
| GET | `/api/get_article.php?id=` | anyone (drafts: admin) | One article with body; counts a view |
| GET | `/api/image.php?id=` | anyone | Image stored in the database (only when Cloudinary isn't set up) |
| POST | `/api/add_article.php` | admin | Multipart: title, summary, body, category, status, image |
| GET/POST | `/api/admin_articles.php` | admin | List all articles / `{action: publish\|unpublish\|delete, id}` |
| GET | `/api/admin_users.php?page&search` | admin | Users list |
| GET | `/api/admin_stats.php` | admin | Dashboard numbers + recent activity |
| GET/POST | `/api/progress.php` | logged in | Lessons completed + quiz results / `{module, lesson, completed}` |
| POST | `/api/quiz_attempt.php` | logged in | `{module, score, total}` — pass mark 80% |
| GET/POST | `/api/budget.php` | logged in | The user's saved budget plan |
| GET/POST | `/api/saved_articles.php` | logged in | Bookmarks / `{article_id, saved}` |
| POST | `/api/profile.php` | logged in | `update_name` or `change_password` |
| POST | `/api/contact.php` | anyone | Saves a contact message |
| POST | `/api/subscribe.php` | anyone | Newsletter sign-up |
| GET | `/api/health.php` | anyone | Health check for Render |
| GET/POST | `/api/assessment.php` | logged in | Latest knowledge-check result / save a new one (level, topic scores, goals, learning path) |
| GET | `/api/get_scam_alerts.php?limit` | anyone | Published scam alerts, most severe first |
| GET/POST | `/api/admin_scam_alerts.php` | admin | All alerts / `{action: create\|update\|delete}` |
| POST | `/api/admin_users.php` | admin | `{action: create\|set_role\|reset_password\|delete}` (can't remove yourself or the last admin) |
| GET | `/api/admin_user.php?id=` | admin | One user: module progress + latest knowledge check |
| POST | `/api/update_article.php` | admin | Multipart edit; image optional (old one is removed when replaced) |
| GET/POST | `/api/admin_messages.php?page&filter=unread` | admin | Contact inbox / `{action: mark_read\|mark_unread\|delete}` |
| GET/POST | `/api/admin_subscribers.php?page` | admin | Subscribers / `{action: delete}` |
| GET | `/api/admin_learning.php` | admin | Lesson completions, quiz pass rates, knowledge-check levels and goals |
| GET | `/api/admin_export.php?type=users\|articles\|messages\|subscribers\|reports\|questions\|research` | admin | CSV download. `research` = anonymised first vs latest knowledge-check scores per learner |
| POST | `/api/forgot_password.php` | anyone | `{email}` → emails a reset link (valid 1 hour). Same answer whether or not the email exists |
| POST | `/api/reset_password.php` | anyone | `{token, password}`. Signs the user out on every other device |
| POST | `/api/unsubscribe.php` | anyone | `{email, token}` from the link in every newsletter/alert email |
| POST | `/api/report_scam.php` | anyone | Report a scam: `{channel, scammer_contact, description, lost_money, amount_lost, name, email}` |
| GET/POST | `/api/admin_reports.php?status&page` | admin | Scam reports + totals / `{action: set_status\|delete}` |
| GET | `/api/get_help_contacts.php` | anyone | Published bank emergency codes and agencies |
| GET/POST | `/api/admin_help_contacts.php` | admin | All help contacts / `{action: create\|update\|delete}` |
| GET | `/api/share.php?article=\|alert=\|page=` | anyone | Link-preview page for WhatsApp/Facebook/X, then redirects to the frontend |
| GET/POST | `/api/expenses.php?month=YYYY-MM` | logged in | Spending for a month with totals, trend and budget plan / `{action: add\|delete}` |
| GET/POST | `/api/questions.php` | logged in | My "Ask an expert" questions / ask one (5 a day) |
| GET | `/api/get_faq.php?topic&search` | anyone | Answered questions an admin made public (no names) |
| GET/POST | `/api/admin_questions.php?status&page` | admin | Questions / `{action: answer\|set_public\|delete}`. Answers are emailed |
| GET | `/api/get_lesson_videos.php?module=` | anyone | `{lesson_id: youtube_id}` |
| POST | `/api/admin_lesson_videos.php` | admin | `{module, lesson, url}`; any YouTube link, empty removes it |
| POST | `/api/admin_newsletter.php` | admin | `{action: test\|send, subject, body}` |

Also changed: `admin_scam_alerts.php` takes `summary_pcm` (Pidgin summary), `report_id`
(links the report an alert came from) and `{action: "email", id}`; `admin_messages.php` takes
`{action: "reply", id, reply}`; `assessment.php` GET also returns `history`; `profile.php`
`change_password` returns a fresh `token` and signs out other devices.

On start-up `bin/migrate.php` creates any missing tables and columns, seeds five starter
scam alerts, and seeds the help contacts the first time:
- **Agencies** (NPF-NCCC, EFCC, FCCPC, SEC, CBN) are published, with links to their official websites.
- **Bank emergency codes start as drafts.** Codes change and different sources list different ones,
  so confirm each code with the bank and publish it from **Admin → Help contacts**. Nothing unconfirmed is shown publicly.

## Database upgrades (XAMPP included)
The API upgrades the database by itself. On the first request after you update the code, it
compares the database with `SCHEMA_VERSION` in `src/migrations.php`, adds any missing tables and
columns, then remembers the version in `storage/schema-version`, so later requests skip the check.
On Render the same steps also run at start-up. To run them by hand and see each step:
`php bin/migrate.php` (XAMPP: `C:\xampp\php\php.exe bin\migrate.php`). Set `AUTO_MIGRATE=false`
to turn the automatic upgrade off.

## Email
Render's free plan blocks SMTP, so email goes through an HTTP API. Used for password resets,
contact replies, answers to questions, scam alerts, newsletters and the subscribe welcome email.

| Variable | Value |
|---|---|
| `MAIL_DRIVER` | `log` (default: nothing is sent, emails are written to `storage/mail.log`), `brevo` or `resend` |
| `MAIL_FROM` / `MAIL_FROM_NAME` | Sender address and name |
| `BREVO_API_KEY` | Brevo: free tier, about 300 emails a day. Verify your sender address in Brevo first |
| `RESEND_API_KEY` | Resend: free tier, needs a domain you can verify |
| `MAIL_BATCH_LIMIT` | Most emails sent per newsletter/alert (default 300, Brevo's free daily limit) |
| `FRONTEND_URL` | Your Vercel URL, used for links in emails (defaults to the first `ALLOWED_ORIGINS` entry) |

Brevo setup: sign up → **Senders, domains & IPs → Senders** → add and verify your address →
**SMTP & API → API keys** → create a key. Then set `MAIL_DRIVER=brevo`, `BREVO_API_KEY`, `MAIL_FROM`.
The admin dashboard shows a "test mode" note while `MAIL_DRIVER` is `log`.

Logged-in requests send `Authorization: Bearer <token>`. Tokens are signed with
`APP_SECRET` and last 7 days.

## Run it locally

### Test with XAMPP + Cloudinary (Windows)
Needs XAMPP with **PHP 8.1 or newer** (check: `C:\xampp\php\php.exe -v`).

1. **Copy** the `moneymentor_api` folder into `C:\xampp\htdocs\`.
   The included `.htaccess` blocks everything except `public/`, so `.env` and `src/`
   can't be opened from the browser.
2. **PHP extensions:** in `C:\xampp\php\php.ini` make sure these lines have no `;` in front:
   `extension=curl`, `extension=fileinfo`, `extension=mbstring`, `extension=pdo_mysql`.
   Restart Apache after changing it.
3. **Certificates (needed for Cloudinary):** download https://curl.se/ca/cacert.pem and save it as
   `C:\xampp\php\extras\ssl\cacert.pem`. The API finds it there automatically.
   If you put it somewhere else, set `CA_BUNDLE=` to its path in `.env`.
4. **Database:** start MySQL in the XAMPP panel. In phpMyAdmin create a database
   `moneymentor` (collation `utf8mb4_unicode_ci`). Optionally import your InfinityFree export into it.
5. **Settings:** copy `.env.example` to `.env` and set:
   ```
   APP_SECRET=<64 random characters>
   ALLOWED_ORIGINS=http://localhost:5173
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=moneymentor
   DB_USER=root
   DB_PASS=
   DB_SSL=false
   CLOUDINARY_URL=cloudinary://<api_key>:<api_secret>@<cloud_name>
   ADMIN_EMAIL=you@example.com
   ADMIN_PASSWORD=<12+ characters>
   ```
   To make an APP_SECRET: `C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32));"`
6. **Create the tables and admin** (run this again after pulling updates):
   ```
   cd C:\xampp\htdocs\moneymentor_api
   C:\xampp\php\php.exe bin\migrate.php
   ```
7. **Check it:** http://localhost/moneymentor_api/public/api/health.php should show `{"success":true,"status":"ok"}`.
8. **Frontend:** in the frontend's `.env.local` put
   `VITE_API_URL=http://localhost/moneymentor_api/public`, then `npm run dev`.
9. Log in with your `ADMIN_EMAIL`, add an article with an image, and it should appear in
   Cloudinary's **Media Library** under `moneymentor/articles`.

**Prefer not to use htdocs?** With XAMPP's MySQL running, from the project folder:
`C:\xampp\php\php.exe -S localhost:8080 -t public`, then use `VITE_API_URL=http://localhost:8080`.

**Testing against TiDB instead of local MySQL:** use the TiDB host/port/user/password in `.env`
with `DB_SSL=true` (the same cacert.pem is used).

**If something goes wrong**
| You see | Fix |
|---|---|
| "SSL certificate problem" in the PHP error log | Step 3, the cacert.pem file |
| "Server is not configured correctly" | `APP_SECRET` is missing or shorter than 32 characters |
| "The service is temporarily unavailable" (503) | MySQL isn't running, or the `DB_*` settings are wrong |
| CORS error in the browser console | `ALLOWED_ORIGINS` must match the frontend address exactly (`localhost` and `127.0.0.1` count as different) |
| "The image couldn't be uploaded right now" | Check `CLOUDINARY_URL`; the exact reason is in `C:\xampp\apache\logs\error.log` |

**With Docker Desktop instead:**
```bash
cp .env.example .env        # set APP_SECRET, ADMIN_PASSWORD, CLOUDINARY_URL
docker compose up --build   # API on http://localhost:8080
```

## Deploy

### 1. Database: TiDB Cloud
InfinityFree's free MySQL only accepts connections from InfinityFree's own
servers, so Render can't reach it. Move the data to TiDB Cloud (MySQL-compatible).
1. In TiDB Cloud, create a **Starter** cluster, then **Connect**. Note the host
   (`gateway01.<region>.prod.aws.tidbcloud.com`), port **4000**, user (`xxxxxx.root`)
   and password. Create a database: `CREATE DATABASE moneymentor;`
2. In InfinityFree's phpMyAdmin, **Export** the `moneymentor` database (SQL format).
3. Import it, either with TiDB Cloud's **Import** page (upload the .sql file) or from your machine:
   ```bash
   mysql -h gateway01.<region>.prod.aws.tidbcloud.com -P 4000 -u xxxxxx.root -p \
     --ssl-mode=VERIFY_IDENTITY --ssl-ca=/etc/ssl/certs/ca-certificates.crt moneymentor < export.sql
   ```
   (On Windows/macOS use the CA path TiDB Cloud's Connect dialog shows you.)
   You can also skip the import and start with an empty database: the API creates all tables itself.

On start-up the API adds missing columns and converts old 3-byte `utf8` tables to
`utf8mb4`. Tables that are already `utf8mb4` are left as they are, because TiDB can't
change collation on existing tables. Search is case-insensitive either way.

**TiDB notes**
- TLS is required: keep `DB_SSL=true`. The Docker image ships the system CA bundle, so nothing else is needed.
- Rows are capped at 6 MiB by default. Images go to Cloudinary, so that only matters for the local database fallback (images are capped at 5 MB, which fits).
- IDs aren't strictly sequential (TiDB hands out ID ranges per server). Nothing in the app depends on that.

### 2. Images: Cloudinary
1. In the Cloudinary console, open **Settings → API Keys** and copy the
   **API environment variable**. It looks like `cloudinary://<api_key>:<api_secret>@<cloud_name>`.
2. Use it as `CLOUDINARY_URL` on Render (next step). Images are uploaded to the
   `moneymentor/articles` folder (change with `CLOUDINARY_FOLDER`).
3. **Existing images:** after the data is in TiDB, run once from your machine with
   `.env` pointing at TiDB and Cloudinary:
   ```bash
   php bin/move_images_to_cloudinary.php --dry-run   # see what will move
   php bin/move_images_to_cloudinary.php
   ```
   It uploads old files from `public/uploads/` and any images stored in the
   database, updates the articles, and can safely be run again.

How it works:
- The API checks each upload by its actual content (JPEG/PNG/WebP, max 5 MB),
  then sends it to Cloudinary with a signed request. The API secret never reaches the browser.
- Each article stores the Cloudinary URL (`image_path`) and `image_public_id`.
  Deleting an article deletes its image on Cloudinary too.
- If the article can't be saved after the upload, the uploaded image is removed again.
- The frontend asks Cloudinary for the best format (WebP/AVIF) and quality, and only as wide as the layout needs.
- If `CLOUDINARY_URL` isn't set (e.g. local dev), images are stored in the database instead, so everything still works offline.

### 3. API on Render
1. Push this folder to its own GitHub repo.
2. Render → **New → Blueprint**, pick the repo (it reads `render.yaml`), or **New → Web Service → Docker**.
3. Fill in the environment variables:
   - `DB_HOST`, `DB_USER`, `DB_PASS` from TiDB Cloud's Connect dialog, `DB_NAME=moneymentor`, `DB_PORT=4000`, `DB_SSL=true` (port and SSL are pre-filled by the Blueprint)
   - `ALLOWED_ORIGINS`: your Vercel URL(s), comma-separated, **no trailing slash**
   - `CLOUDINARY_URL` from step 2
   - `ADMIN_EMAIL` / `ADMIN_PASSWORD` (12+ chars): creates your admin on first start
   - `APP_SECRET` is generated by the Blueprint. If you set it by hand, use 64 random characters and never change it, or everyone is logged out.
4. Check `https://<your-service>.onrender.com/api/health.php` returns `{"success":true,"status":"ok"}`.

### 4. Point the frontend at it
In Vercel → Project → Settings → Environment Variables: `VITE_API_URL=https://<your-service>.onrender.com`, then redeploy.

## Good to know
- **Images live on Cloudinary**, never on Render's disk, which is wiped on each deploy and restart.
- **Free Render services sleep when idle.** The first request after a quiet spell can take a while; later ones are fast.
- Files already in `public/uploads/` are baked into the image, so old article images keep working until you run the move script.
- Promote an existing account to admin by setting `ADMIN_EMAIL` to its email and restarting.
- **Changing or resetting a password signs out every other device** (tokens issued before the change stop working).
- **Link previews:** shares point at `/api/share.php?...` so WhatsApp shows a title, summary and image. The default image is the frontend's `public/og-image.png`.
- **For your research:** Admin → Learning & quizzes → *Research data (CSV)* exports one anonymous row per learner (no names or emails): first and latest knowledge-check scores overall and per topic, days between, lessons completed and quizzes passed.

## What changed from the first version
- **Login tokens were never checked.** They were random strings nobody stored. They're now signed tokens verified on every request.
- **Anyone could publish articles.** `add_article.php` had no login check and trusted `author_id` from the form. It now requires an admin, and the author is the logged-in admin.
- **Image upload could run code on the server.** It trusted the browser's file type and kept the original extension, so a `.php` file labelled `image/png` was saved in a public folder. Images are now checked by content and stored on Cloudinary; `uploads/` can't execute PHP.
- **CORS never matched Vercel**, because the allowed origin had a trailing `/`. Origins now come from `ALLOWED_ORIGINS` and the `Authorization` header is allowed.
- **No server-side signup rules.** The server now requires a valid email and passwords of 8–72 characters, lowercases emails, and rate-limits signups.
- **No brute-force protection.** Login, signup, contact and subscribe are now rate-limited.
- **Unbounded queries.** `limit=999999` or `page=-1` caused huge queries or errors. Both are now clamped.
- **DB credentials were hard-coded.** They now come from environment variables, the 3-byte `utf8` charset is now `utf8mb4`, and PHP errors no longer leak into responses.
- **Added** `get_article.php`, category/search filters, reading time, and the admin, contact, subscribe and health endpoints.
