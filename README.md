# Smart Waste Collection Optimizer

A municipal waste-collection platform for a smart city. Citizens report waste
and track pickups, the control room prioritises and dispatches crews, workers
collect with GPS proof-of-service, and officers verify the results against
before/after photos and act on predicted hotspots.

Built as a plain PHP + MySQL application (no framework, no build step) so it
runs on any XAMPP/WAMP/LAMP install by copying the folder.

---

## 1. Quick start

1. Copy the project into your web root:

   ```
   C:\xampp\htdocs\waste-php          ->  http://localhost/waste-php/
   ```

2. Start **Apache** and **MySQL** from the XAMPP control panel.

3. Create the database and load the demo data:

   ```
   C:\xampp\php\php.exe tests\reset-db.php
   ```

   This drops and recreates all 14 tables from `database/schema.sql`, loads
   `database/seed.sql`, then runs `database/finalize.php` to compute priority
   scores, predict hotspots and refresh counters. It is safe to re-run.

4. Open <http://localhost/waste-php/>.

### Demo accounts

| Role    | Email                     | Password     |
| ------- | ------------------------- | ------------ |
| Admin   | `admin@smartwaste.gov`    | `admin@123`  |
| Worker  | `worker1@smartwaste.gov`  | `worker@123` |
| Citizen | `citizen1@smartwaste.gov` | `citizen@123`|

All accounts follow the pattern `worker2..8@smartwaste.gov` and
`citizen2..10@smartwaste.gov` with the same role password.

### Requirements

| Component | Version |
| --------- | ------- |
| PHP       | 8.0+ (developed on 8.2) |
| MySQL / MariaDB | 5.7+ / 10.4+ |
| PDO MySQL driver | required |
| `mbstring`, `json`, `fileinfo` | required for uploads |

`gd` is **not** required — image validation uses `getimagesize()`.

### Configuration

Everything is in `config/config.php`:

```php
DB_HOST, DB_PORT, DB_NAME = 'smart_waste_optimizer'
DB_USER = 'root', DB_PASS = ''
APP_DEBUG = true            // set false in production
```

Uploads are validated by real image type (not the extension), must be at least
100x100 px, and are renamed to a random token so nothing user-supplied ever
reaches the filesystem path.

---

## 2. How the system works

```
citizen                control room (admin)              crew (worker)
  |                            |                              |
  report waste  ---------->   pending                        |
  |                            |  calculate_priority()         |
  |                            v                              |
  |                       score + level                      |
  |                            |  recommend_workers()         |
  |                            v                              |
  |                       assignment  ---------------------->  arrive
  |                            |                              start
  |                            |                    <--------- before photo
  |                            |                              after photo
  |                            |  collect  ------------------>  collected
  |                            |                              |
  |  live tracking  <---------- verify (officer)              |
  |                            v                              |
  |                       verified + notification             |
```

### Request lifecycle

`pending -> prioritized -> assigned -> in_progress -> collected -> verified`
(with `cancelled` and `rejected` as terminal alternatives).

`assigned` and `in_progress` both mean a crew is on it; `scheduled` marks a stop
that is on an optimised route but has not started.

### Priority engine (`includes/priority.php`)

Every request is scored 0-100 from five weighted factors:

| Factor                | Weight | Notes                                        |
| --------------------- | -----: | -------------------------------------------- |
| `severity`            |     30 | derived from waste type and quantity          |
| `quantity`            |     20 | small / medium / large / XL                  |
| `location_risk`       |     20 | proximity to a predicted hotspot              |
| `age`                 |     15 | saturates at 14 days                         |
| `nearby_reports`      |     15 | other open reports within 1 km               |

Levels: `low` 0-30, `medium` 31-60, `high` 61-80, `critical` 81-100.
`view.php` returns the per-factor breakdown so the UI can explain the score.

### Worker recommendation engine (`includes/recommend.php`)

Ranks eligible workers for a request:

| Factor         | Weight |
| -------------- | -----: |
| `distance`     |     40 |
| `availability` |     25 |
| `workload`     |     20 |
| `compatibility`|     15 |

Compatibility matches the crew's `skills` / team waste types against the
request's waste type, so a plastic-only team is not sent for organic waste.

### Route optimiser (`includes/planner.php`)

Nearest-neighbour ordering over the selected stops with 2-opt improvement pass.
Produces `collection_plans` + ordered `collection_plan_stops`, per-stop ETA,
leg distance and an `in_plan` flag so the worker app can show the route on a map.

### Hotspot predictor (`includes/hotspots.php`)

Groups historical reports into named areas and scores each by report volume,
report frequency and a time-decay term. Surfaces as a live list plus a
predictive heat layer on the map. Seeded data yields 9 hotspots, the highest
being *Market Yard Vegetable Section* (11 reports, roughly one every 2.4 days).

### Proof-of-service and verification

A worker cannot mark a pickup collected until **both** a BEFORE and an AFTER
photo exist — the API returns HTTP 422 otherwise. Citizens are blocked from
writing those two image types entirely, so a citizen cannot forge evidence about
their own pickup. An officer then compares the two images and approves or
rejects, and only approval sets `verified`.

---

## 3. Project layout

```
waste-php/
├── index.php  login.php  register.php  logout.php     public pages
├── citizen/    dashboard, report, requests, track, profile
├── admin/      dashboard, requests, request, assignments, workers,
│               collection-plans, collection-plan, verify, map,
│               hotspots, analytics, activity, profile
├── worker/     dashboard, mission, requests, profile
├── api/        36 JSON endpoints (see below)
├── includes/   auth, functions, priority, recommend, planner,
│               hotspots, upload, header, footer
├── assets/     css/style.css, js/app.js, js/map.js
├── config/     config.php, database.php
├── database/   schema.sql, seed.sql, finalize.php
├── tests/      verification suite (see below)
└── uploads/    stored citizen and proof photos
```

### API

Every endpoint returns `{ success, message, data }`. Mutating requests need the
session `X-CSRF-Token` header. Errors use meaningful status codes: `401` not
signed in, `403` wrong role or not your record, `404` missing, `422` validation
failed, `405` wrong method.

| Group        | Endpoints |
| ------------ | --------- |
| `auth`       | `login` `logout` `me` `register` |
| `public`     | `overview` |
| `requests`   | `create` `list` `view` `track` `cancel` `feedback` `update-status` `upload` |
| `assignments`| `create` `list` |
| `collection` | `mission` `suggest` `plans` `plan-view` `create-plan` `plan-action` `reorder` |
| `workers`    | `list` `recommend` `status` `update-location` |
| `hotspots`   | `list` `recompute` |
| `analytics`  | `charts` |
| `admin`      | `stats` `map-data` `verify` `set-status` `priority` `reject` `activity` |

### Database (14 tables)

`users` `citizens` `teams` `vehicles` `workers` `waste_requests`
`request_images` `assignments` `collection_plans` `collection_plan_stops`
`worker_locations` `hotspots` `notifications` `activity_logs`

---

## 4. Security

| Concern        | Mitigation |
| -------------- | ---------- |
| SQL injection  | every query is a prepared statement; `PDO::ATTR_EMULATE_PREPARES = false` |
| XSS            | all output escaped through `e()`; JS builds DOM with `escapeHtml()` |
| CSRF           | per-session token required on every state-changing request |
| Session        | httponly + samesite cookies, regenerated on login |
| Privilege      | `require_role()` / `api_require_role()` on every page and endpoint, plus ownership checks per row |
| File upload    | real MIME sniff, min dimensions, random filename, served from `uploads/` |
| Mass assignment| endpoints whitelist the fields they accept |
| Audit trail    | `activity_logs` records every state change with actor and IP |

---

## 5. Tests

```
C:\xampp\php\php.exe tests\run-all.php                      # against localhost/waste-php
C:\xampp\php\php.exe tests\run-all.php http://127.0.0.1:8899 # against any host
```

| Script | What it covers | Needs a server |
| ------ | -------------- | -------------- |
| `tests/lint-all.php` | `php -l` on all 85 files, close-tag-in-comment trap, BOM/whitespace | no |
| `tests/bom-scan.php` | UTF-8 BOMs, stray closing tags, leading whitespace | no |
| `tests/check-includes.php` | every file requires the helper that defines each function it calls | no |
| `tests/contract.php` | every JS helper and CSS class referenced by a page actually exists | no |
| `tests/smoke.php` | 87 API assertions across the whole request lifecycle | yes |
| `tests/worker-flow.php` | 42 assertions: create -> recommend -> assign -> arrive -> start -> proof -> collect -> verify, plus role boundaries | yes |
| `tests/pages.php` | renders 37 pages across 4 roles, fails on any PHP diagnostic | yes |

`tests/reset-db.php` rebuilds the demo database and prints a summary of what it
created.

The three HTTP tests are **order-independent** — `worker-flow.php` creates and
assigns its own pickup rather than relying on leftover demo state.

### Current status

```
php syntax + close-tag/BOM lint    PASS   (85 files)
include dependency check           PASS   (16 files)
js/css/php contract check          PASS   (0 missing)
api smoke test                     PASS   (87/87)
worker field journey               PASS   (42/42)
every page for every role          PASS   (37/37)
```

---

## 6. Notes and limitations

- Geocoding is not wired up; areas are inferred from coordinates by
  `infer_area_name()` using the seeded landmark list.
- Routing uses nearest-neighbour + 2-opt rather than a full solver, so a plan is
  good rather than provably optimal.
- `worker/dashboard.php` pings GPS every two minutes, which browsers only allow
  on a secure origin (`localhost` counts) or over HTTPS.
- Push notifications are stored in `notifications` and rendered on the next page
  load; there is no WebSocket or email delivery.
- The test server used during development was `php -S 127.0.0.1:8899`; the app
  itself only needs Apache.#   S m a r t W a s t e C o l l e c t i o n  
 