# Pack Leader K9 — EspoCRM customizations

The code that makes the [Pack Leader K9](https://example.com) office book (EspoCRM) work for the
business: public booking and shot-record pages, the returning-client page, the kennel board, lesson packages,
report cards, and the installers that put it all into EspoCRM. Published to satisfy the AGPL (EspoCRM is
AGPLv3 and these pages modify it), so anyone who uses the modified book over the network can get its source.

## What runs on

- **EspoCRM 10.0.9** (`espocrm/espocrm:10.0.9`), the upstream of everything here: <https://github.com/espocrm/espocrm>
- The **ext-mcp** module is also installed but **unmodified**: we link upstream
  (`espocrm/ext-mcp` 0.1.0) rather than include it here. Its files under `custom/Espo/Modules/Mcp/` are
  not in this repo.

## How the files map into the book

The directories here mirror the live paths under the EspoCRM document root (`/var/www/html`):

| Here | Installed at |
|---|---|
| `custom/Espo/Custom/…` | `custom/Espo/Custom/…` (entry points, API, hooks, the `PackLeader` helper, custom-entity classes and metadata) |
| `client/custom/…` | `client/custom/…` (theme CSS, logo, JS handlers) |
| `public/calendar.php` | `public/calendar.php` (the private iCal feed; bind-mounted in the compose stack) |

### Entry points and API routes (client-facing)

- `?entryPoint=booking` — the public booking form (`Booking.php` → `BookingSubmit.php`).
- `?entryPoint=shotRecords` — a client uploads a dog's shot records (`ShotRecords.php` → `ShotRecordsUpload.php`).
- `?entryPoint=client` — the returning-client page (`Client.php` → `BookAgainSubmit.php`, `ClientLinkRequest.php`,
  `PackLeader/Client.php`).
- `?entryPoint=kennelBoard` — the staff kennel board (login required).
- `?entryPoint=lessonSchedule` — a client's printable lesson schedule.
- `?entryPoint=rates` — the public rates page (`Rates.php`).
- `?entryPoint=boardingTerms` — the boarding agreement terms page (`BoardingTerms.php`).
- `public/calendar.php` — private iCalendar feed (token from `CALENDAR_TOKEN`, DB from `ESPOCRM_DATABASE_*` env).

### Entities and hooks

- `lesson-package/` — the `LessonPackage` entity (3/5/10-packs) and its count hooks.
- `request-dog/` — `RequestDog` (one dog on a booking request).
- `stay-care/` — copying a dog's care details onto a hand-made booking.
- `stay-log/`, `training-goal/` — report cards and board & train progress.

## Installers (`installers/`)

These are the source of everything that is **not** a whole file above: they merge the office book's core
entities (Lead, Meeting, Stay, Dog, Contact) with the business's fields and layouts, so those overlays are
produced by running the installer against EspoCRM's own base (linked above), not stored here as files.

- `packleader-office-flow-install.py` — the main one-time setup (fields, panels, entry points, routes, roles,
  API users, outgoing email). Dry run by default; `--apply` to do it.
- `packleader-install-files.sh` — reinstall just the PHP entry points (no admin login, so it works with 2FA on).
- `packleader-rates-page.py` + `packleader_pricing.py` — the rates page, and the shared price list (the same
  numbers the invoices use).
- `packleader-add-*.sh` — idempotent field/role additions (no-show, payRef, phase 5a notes/care, custom invoices,
  report cards, the staff role, the vet-record fields, the read-only watcher).

Run them on the machine that hosts the EspoCRM container (they use `docker exec`), with `PK9_*` env overrides
to target a dev book. Every script backs up `custom/` before it writes.

## Publishing hygiene

Nothing here is a secret. The installers read tokens/passwords from environment variables or files at run
time (e.g. `PK9_ESPO_URL`, `CALENDAR_TOKEN`, `ESPOCRM_DATABASE_*`, `~/packleader-smtp.token`) — none of those
values are in this repo. The only hard-coded real-world values are the business's own public phone number and
its public domains (`example.com`, `office.example.com`, `go.example.com`); everything else is a
`555`-style placeholder or a config lookup.

## License

AGPL-3.0-or-later. See [`LICENSE`](LICENSE).
