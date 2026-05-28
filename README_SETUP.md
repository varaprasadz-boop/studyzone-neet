# NEET Study Zone — Setup Guide (Hostinger shared hosting)

**All phases are built.** Logins + mobile nav, AI study-material generation from a
syllabus image, AI question extraction from paper photos, timed tests with NEET
marking, and reports with charts. AI features need an Anthropic API key (below);
everything else works without one.

---

## What you need
- Your Hostinger account (shared hosting plan).
- 10 minutes.
- (Later, for Phase 2+) an Anthropic API key from console.anthropic.com.

## Step 1 — Create a MySQL database
1. Log in to Hostinger → **hPanel**.
2. Go to **Databases → MySQL Databases**.
3. Create a new database. Note down the **database name**, **username**, and **password**.
   (Host is almost always `localhost`.)

## Step 2 — Add your DB details to the app
1. Open `includes/config.php` (edit it on your computer before upload, or via hPanel
   File Manager after upload).
2. Fill in:
   ```php
   define('DB_NAME', 'your_db_name');
   define('DB_USER', 'your_db_user');
   define('DB_PASS', 'your_db_password');
   ```
3. Paste your `ANTHROPIC_API_KEY` (from console.anthropic.com) to enable AI study-material
   generation and question extraction. You can leave it blank and add it later — the rest of
   the app (browsing, manual question entry, test building, attempts, reports) still works.

## Step 3 — Upload the files
1. hPanel → **Files → File Manager**.
2. Open **public_html** (or a sub-folder like `public_html/study` if you want it at
   `yoursite.com/study`).
3. Upload **all** the files and folders from this package, keeping the structure:
   ```
   index.php  login.php  logout.php  dashboard.php  account.php  install.php
   study.php  study_generate.php  study_chapter.php
   questionbank.php  paper_new.php  paper_review.php  question_edit.php
   examzone.php  test_new.php  test_attempt.php  test_result.php
   reports.php
   includes/   api/   assets/   sql/   uploads/   .htaccess
   ```
   (Tip: upload the `.zip`, then use File Manager's **Extract** option.)

## Step 4 — Run the installer once
1. Visit `https://yoursite.com/install.php` (or `.../study/install.php`).
2. You should see **“Installation complete”** with the default logins.
3. **Delete `install.php`** from the server (File Manager → right-click → Delete).

## Step 5 — Log in & secure it
Default logins (change immediately):
- **Super Admin** → `appa` / `appa@123`
- **Student** → `son` / `son@123`

Log in as each, open **Account**, and set new passwords you'll remember.

---

## What the app does

**Foundation**
- Two separate logins (admin vs student) with the right menus for each.
- Mobile-friendly navigation (hamburger drawer on phones, sidebar on desktop).
- A 5-minute idle lock on every screen; idle time is never counted in reports.

**Study Material** (admin authors, student reads)
- Browse Class 11/12 → NCERT/State → Subject → Chapter.
- The six seeded Class-12 NCERT chapters open the full interactive hub.
- Admin → "Create study material from syllabus image": upload the syllabus photo, the
  AI lists the chapters, and generates concepts, formulas, flashcards and a self-test for
  each — rendered in-app. (Or type chapter names manually / create empty shells.)

**Question Bank**
- Admin → "Add new paper": upload up to 25 page photos; the AI reads every question and
  files it by subject & chapter as drafts. Review, fix, then publish. Questions can also
  be added by hand.
- Student browses published questions by paper or subject/chapter, with answer + explanation.

**Exam Zone**
- Admin → "Generate new test": pick subject + chapters + difficulty + count; questions are
  drawn from the published bank.
- Student attempts with an optional timer and NEET marking (+4 / −1 / 0). Questions and
  options reshuffle on every attempt; results show a full answer key, and reattempts are
  unlimited.

**Reports**
- Scores over time, accuracy by subject and chapter, and engaged (idle-free) time by area
  and per day — as inline charts. Admin can pick which student to view.

## Using AI features
The Study-Material generator and Question extractor call the Anthropic API. Add your key in
`includes/config.php` (`ANTHROPIC_API_KEY`). The default model is `claude-sonnet-4-6`. Without
a key those two buttons fall back to manual entry; the rest of the app is unaffected.

## Troubleshooting
- *“Database connection failed”* → re-check the four `DB_*` values in `includes/config.php`.
- *Blank page* → ensure your Hostinger plan runs **PHP 8+** (hPanel → Advanced → PHP
  Configuration).
- *Styles missing* → confirm the `assets/` folder uploaded with its sub-folders.
- *AI buttons say “No API key”* → paste `ANTHROPIC_API_KEY` in `includes/config.php`.
- *Uploads fail* → make sure the `uploads/` folder exists and is writable (755/775).
