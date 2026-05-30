# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A private NEET (India medical entrance) study app for two users — a tutor/admin (`appa`) and a student (`son`). Plain **PHP 8 + PDO/MySQL**, no framework, no build step, no package manager, no test suite. Designed to run on **Hostinger shared hosting**: the repo root maps directly to `public_html`.

## Running & setup

- **Local dev:** `php -S localhost:8000` from the repo root, then open `/`. Requires PHP 8+ with `pdo_mysql`, `curl`, `mbstring`.
- **Config:** `includes/config.php` is git-ignored. Copy `includes/config.sample.php` → `includes/config.php` and fill DB creds + (optional) `ANTHROPIC_API_KEY`. `db.php` shows a friendly error if it's missing.
- **First run / schema:** visit `/install.php` once. It is idempotent (`CREATE TABLE IF NOT EXISTS`) and seeds users/subjects/demo chapters only if `users` is empty. **The schema lives entirely in `install.php`** — there are no migration files; add new tables there. For tables added after a site is already installed, also add a runtime `CREATE TABLE IF NOT EXISTS` guard (see `ensure_sr()` in `lib.php`) since installers get deleted post-setup.
- **Diagnostics:** `/selftest.php` (admin) checks PHP/extensions/DB/tables/uploads/API key and can do a live API ping. Use it to validate a deploy — there's no automated test harness.
- **Syntax check a file:** `php -l somefile.php`.

## Default logins (change after install): `appa`/`appa@123`, `son`/`son@123`.

## Architecture & conventions

**Page lifecycle (follow this exactly).** Each root-level `*.php` is a server-rendered page that:
1. `require_once __DIR__.'/includes/lib.php'` (pulls in `auth.php` → `db.php` → `config.php`); also require `ai.php` if it calls AI.
2. sets `$ACTIVE` (nav highlight key) and `$PAGE` (title), then `require_login()` or `require_admin()`.
3. **handles all POST and any `redirect()` BEFORE `require includes/header.php`.** `header.php` emits HTML immediately, so any `header()`/`redirect()` after it triggers "headers already sent". This is the single most common way to break a page here.
4. renders, then `require includes/footer.php`.

**Includes layer:**
- `db.php` — `db()` returns a singleton PDO (exceptions on, assoc fetch, real prepares).
- `auth.php` — sessions, `current_user()`, `is_admin()`, `require_login()`/`require_admin()`, CSRF (`csrf_field()`, `csrf_ok()`, `require_csrf()`), `json_out()`, and `e()` (HTML escape).
- `lib.php` — query helpers `q1/qa/qcount`, `flash()`/`flash_render()`, `redirect()`, study-content load/save, `resolve_subject_id/resolve_chapter_id`, `question_image_html()`, `ensure_sr()`, `NEET_CORRECT/WRONG/SKIP` constants, `fmt_hms()`.
- `ai.php` — Anthropic client. `ai_enabled()`, `ai_call($messages,$system,$maxTokens)`, `ai_image_block($path)`, `ai_json($text)` (tolerant JSON parse). Uses prompt caching on the system prompt. **Prompts must instruct the model to return only JSON and to write math as LaTeX in `$…$`.**
- `chart.php` — dependency-free inline-SVG charts (`chart_bars/chart_line/chart_hbars`). No JS chart libs.
- `header.php`/`footer.php` — shared shell; KaTeX is loaded here (math renders via `window.__renderMath`). `header.php` emits `data-screen/data-subject/data-chapter` on `<body>` for time tracking.

**API endpoints (`api/`)** return JSON via `json_out()`, guard with `csrf_ok()`, and never emit HTML. Long AI jobs are deliberately split into **per-item AJAX loops** driven by client JS (one chapter → `study_ai.php`; one page → `paper_ai.php`) to stay under shared-hosting execution limits — don't refactor these into one big synchronous request.

**CSRF:** every POST form includes `csrf_field()` and every handler calls `require_csrf()` (or `csrf_ok()` in APIs).

**Auth/roles:** two roles, `superadmin` and `student`. Admin-only pages use `require_admin()`.

### Domain model notes (non-obvious)

- **Subjects are duplicated** across every class × syllabus combination. When filing extracted/manual questions, map to a *canonical* subject id via `resolve_subject_id()` (prefers Class 12 · NCERT). `resolve_chapter_id()` creates chapters on demand.
- **Study Material navigation drops the syllabus step**: `study.php` goes Class → Subject → Chapter. Subjects are still duplicated per syllabus in the DB, so `study_subjects_for_class()` returns one canonical row per subject name (the NCERT one); chapters hang off that canonical subject id.
- **Study content is row-wise `study_items`** (`chapter_id, topic, subtopic, question, explanation, image, qhash, sort`), bulk-uploaded from `.xlsx`/`.csv` via `study_upload.php` (parser in `includes/xlsx.php`) or hand-edited in `study_edit.php`. **Dedup is on the question text only** — `question_hash()` (strip tags, collapse whitespace, lowercase, sha1) backed by `UNIQUE(chapter_id, qhash)`; bulk insert uses `INSERT IGNORE` and counts `rowCount()`. `study_chapter.php` renders items grouped Topic→Sub-topic. The spaced-repetition review (`study_review.php`) treats each item with a non-empty explanation as a flashcard (question→front, explanation→back; `flashcard_reviews.card_index` = `study_items.id`). The old AI study generator and `study_content` table are no longer used (AI remains only for Question Bank paper extraction). Chapters with a `hub_file` still open the standalone hub in `assets/hub/`.
- **Study-item images**: attached files are stored under `uploads/study/{chapterId}/` and the `image` column holds the filename (or a pasted `http(s)` URL). Served via the login-gated `api/file.php?study={chapterId}&f=...`; render with `study_image_url()`.
- **Test option shuffling / grading:** on attempt creation, `attempt_answers.shuffled_options` stores the *original* option indices in display order. The radio `value` and `given_index` are the *displayed* index. Grading maps displayed → original via `shuffled_options[given_index]` and compares to `questions.correct_index`. Keep this mapping consistent across `test_attempt.php` (create/submit) and `test_result.php` (render).
- **Test kinds:** `tests.config` is JSON; `kind: "practice"` (student "practice my mistakes" sets) is filtered out of the Exam Zone list; `kind: "mock"` is a full NEET mock. Normal admin tests have no/empty kind.
- **Uploaded paper images** live in `uploads/papers/{paperId}/pN.ext`, blocked from direct web access by `uploads/.htaccess`, and served only through the login-gated `api/file.php`. Questions reference them via `image_ref`; `question_image_html()` renders the figure inline.
- **Time tracking:** `assets/js/app.js` counts engaged seconds (idle lock at 5 min, idle time never sent), POSTing deltas to `api/track.php` which upserts `activity_log` per user/day/screen/chapter. Reports read from there.

### Gotchas
- `e()` escapes output, but `$…$` survives escaping so KaTeX still renders escaped text. Question/option text is escaped; generated study `concepts`/`flashcards` are echoed as raw HTML (admin-authored) — keep that trust boundary in mind.
- In strings sent to the AI, write LaTeX with **single-quoted PHP strings** (or escape `$`) so `$v`, `$cell` etc. aren't interpreted as PHP variables.
- AI features degrade gracefully when `ANTHROPIC_API_KEY` is blank (manual entry paths); don't assume the key exists.
