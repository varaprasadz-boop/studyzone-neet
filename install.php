<?php
/* ============================================================
   ONE-TIME INSTALLER
   1. Edit includes/config.php with your DB details first.
   2. Visit this file once in the browser (e.g. https://yoursite/install.php).
   3. After success, DELETE this file.
   ============================================================ */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';   // for e()

$messages = [];
$pdo = db();

/* ---------- 1. Tables ---------- */
$tables = [
"users" => "CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role ENUM('superadmin','student') NOT NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(120) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"settings" => "CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(60) PRIMARY KEY,
  v TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"classes" => "CREATE TABLE IF NOT EXISTS classes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL,
  sort INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"syllabi" => "CREATE TABLE IF NOT EXISTS syllabi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL,
  sort INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"subjects" => "CREATE TABLE IF NOT EXISTS subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  class_id INT NOT NULL,
  syllabus_id INT NOT NULL,
  name VARCHAR(80) NOT NULL,
  color VARCHAR(16) DEFAULT '#3b5bdb',
  icon VARCHAR(16) DEFAULT '',
  sort INT DEFAULT 0,
  INDEX(class_id), INDEX(syllabus_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"chapters" => "CREATE TABLE IF NOT EXISTS chapters (
  id INT AUTO_INCREMENT PRIMARY KEY,
  subject_id INT NOT NULL,
  name VARCHAR(160) NOT NULL,
  hub_file VARCHAR(160) DEFAULT NULL,
  sort INT DEFAULT 0,
  INDEX(subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"topics" => "CREATE TABLE IF NOT EXISTS topics (
  id INT AUTO_INCREMENT PRIMARY KEY,
  chapter_id INT NOT NULL,
  name VARCHAR(160) NOT NULL,
  sort INT DEFAULT 0,
  INDEX(chapter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"study_content" => "CREATE TABLE IF NOT EXISTS study_content (
  id INT AUTO_INCREMENT PRIMARY KEY,
  chapter_id INT NOT NULL,
  kind ENUM('concepts','flashcards','widgets','quiz','formulas') NOT NULL,
  data LONGTEXT,
  created_by INT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(chapter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"papers" => "CREATE TABLE IF NOT EXISTS papers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_name VARCHAR(160) NOT NULL,
  exam_date DATE NOT NULL,
  note VARCHAR(255) DEFAULT NULL,
  uploaded_by INT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_paper (exam_name, exam_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"questions" => "CREATE TABLE IF NOT EXISTS questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  paper_id INT DEFAULT NULL,
  subject_id INT DEFAULT NULL,
  chapter_id INT DEFAULT NULL,
  topic_id INT DEFAULT NULL,
  qtype ENUM('mcq','numeric') DEFAULT 'mcq',
  stem TEXT NOT NULL,
  options LONGTEXT,
  correct_index INT DEFAULT NULL,
  correct_value VARCHAR(120) DEFAULT NULL,
  explanation TEXT,
  difficulty ENUM('easy','medium','hard') DEFAULT 'medium',
  template LONGTEXT DEFAULT NULL,
  source ENUM('uploaded','generated','manual') DEFAULT 'uploaded',
  image_ref VARCHAR(200) DEFAULT NULL,
  status ENUM('draft','published') DEFAULT 'draft',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(paper_id), INDEX(subject_id), INDEX(chapter_id), INDEX(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"tests" => "CREATE TABLE IF NOT EXISTS tests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  config LONGTEXT,
  summary LONGTEXT,
  duration_min INT DEFAULT 0,
  created_by INT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"test_questions" => "CREATE TABLE IF NOT EXISTS test_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  test_id INT NOT NULL,
  question_id INT NOT NULL,
  sort INT DEFAULT 0,
  INDEX(test_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"attempts" => "CREATE TABLE IF NOT EXISTS attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  test_id INT NOT NULL,
  student_id INT NOT NULL,
  started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME DEFAULT NULL,
  total INT DEFAULT 0,
  correct_count INT DEFAULT 0,
  wrong_count INT DEFAULT 0,
  skipped_count INT DEFAULT 0,
  score DECIMAL(7,2) DEFAULT 0,
  status ENUM('in_progress','completed') DEFAULT 'in_progress',
  INDEX(test_id), INDEX(student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"attempt_answers" => "CREATE TABLE IF NOT EXISTS attempt_answers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attempt_id INT NOT NULL,
  question_id INT NOT NULL,
  shuffled_options LONGTEXT,
  given_index INT DEFAULT NULL,
  given_value VARCHAR(120) DEFAULT NULL,
  is_correct TINYINT DEFAULT 0,
  time_spent_sec INT DEFAULT 0,
  INDEX(attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"activity_log" => "CREATE TABLE IF NOT EXISTS activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  screen VARCHAR(60) NOT NULL,
  subject_id INT DEFAULT NULL,
  chapter_id INT DEFAULT NULL,
  active_seconds INT DEFAULT 0,
  day DATE NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(user_id), INDEX(day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"study_sessions" => "CREATE TABLE IF NOT EXISTS study_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  chapter_id INT NOT NULL,
  active_seconds INT DEFAULT 0,
  started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(user_id), INDEX(chapter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"study_items" => "CREATE TABLE IF NOT EXISTS study_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  chapter_id INT NOT NULL,
  topic VARCHAR(180) DEFAULT '',
  subtopic VARCHAR(180) DEFAULT '',
  question TEXT NOT NULL,
  explanation MEDIUMTEXT,
  image VARCHAR(220) DEFAULT NULL,
  qhash CHAR(40) NOT NULL,
  sort INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_chap_q (chapter_id, qhash),
  INDEX(chapter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"flashcard_reviews" => "CREATE TABLE IF NOT EXISTS flashcard_reviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  chapter_id INT NOT NULL,
  card_index INT NOT NULL,
  reps INT DEFAULT 0,
  ease DECIMAL(4,2) DEFAULT 2.50,
  interval_days INT DEFAULT 0,
  due_date DATE NOT NULL,
  UNIQUE KEY uniq_card (user_id, chapter_id, card_index),
  INDEX(user_id), INDEX(due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* ---- Phase 1 RBAC + audit + consent foundations ---- */
"roles" => "CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"permissions" => "CREATE TABLE IF NOT EXISTS permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,
  description VARCHAR(160) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"role_permissions" => "CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT NOT NULL,
  permission_id INT NOT NULL,
  PRIMARY KEY (role_id, permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"user_roles" => "CREATE TABLE IF NOT EXISTS user_roles (
  user_id INT NOT NULL,
  role_id INT NOT NULL,
  PRIMARY KEY (user_id, role_id),
  INDEX(role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"user_scopes" => "CREATE TABLE IF NOT EXISTS user_scopes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  scope_type ENUM('class','subject','chapter','student','tenant') NOT NULL,
  scope_id INT DEFAULT NULL,
  INDEX(user_id), INDEX(scope_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"audit_log" => "CREATE TABLE IF NOT EXISTS audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  actor_user_id INT DEFAULT NULL,
  action VARCHAR(80) NOT NULL,
  entity VARCHAR(60) DEFAULT NULL,
  entity_id INT DEFAULT NULL,
  meta_json TEXT,
  ip VARCHAR(45) DEFAULT NULL,
  ua VARCHAR(255) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(actor_user_id), INDEX(action), INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"user_consents" => "CREATE TABLE IF NOT EXISTS user_consents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  kind VARCHAR(40) NOT NULL,
  granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  ip VARCHAR(45) DEFAULT NULL,
  evidence VARCHAR(255) DEFAULT NULL,
  INDEX(user_id), INDEX(kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($tables as $name => $sql) {
    $pdo->exec($sql);
    $messages[] = "Table ready: $name";
}

/* ---------- 2. Seed (only if empty) ---------- */
$already = (int)$pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'];

if ($already === 0) {
    // users
    $admin = password_hash('appa@123', PASSWORD_DEFAULT);
    $stud  = password_hash('son@123',  PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (role, username, password_hash, name) VALUES (?,?,?,?)")
        ->execute(['superadmin', 'appa', $admin, 'Super Admin']);
    $pdo->prepare("INSERT INTO users (role, username, password_hash, name) VALUES (?,?,?,?)")
        ->execute(['student', 'son', $stud, 'Student']);
    $messages[] = "Created logins: appa (admin) & son (student)";

    // classes & syllabi
    $pdo->exec("INSERT INTO classes (name, sort) VALUES ('Class 11',1),('Class 12',2)");
    $pdo->exec("INSERT INTO syllabi (name, sort) VALUES ('NCERT',1),('State Board',2)");

    $classRows = $pdo->query("SELECT * FROM classes")->fetchAll();
    $sylRows   = $pdo->query("SELECT * FROM syllabi")->fetchAll();

    $subjectDefs = [
        ['Physics',   '#3b5bdb', '⚡'],
        ['Chemistry', '#0f8a7e', '⚗'],
        ['Botany',    '#4a8c3f', '🌿'],
        ['Zoology',   '#c2683a', '🧬'],
    ];
    $insSubj = $pdo->prepare("INSERT INTO subjects (class_id, syllabus_id, name, color, icon, sort) VALUES (?,?,?,?,?,?)");
    foreach ($classRows as $c) {
        foreach ($sylRows as $s) {
            foreach ($subjectDefs as $i => $d) {
                $insSubj->execute([$c['id'], $s['id'], $d[0], $d[1], $d[2], $i]);
            }
        }
    }
    $messages[] = "Seeded subjects for both classes and syllabi";

    // Demo chapters under Class 12 + NCERT, bridged to the interactive hub already built
    $c12 = null; foreach ($classRows as $c) if ($c['name'] === 'Class 12') $c12 = $c['id'];
    $ncert = null; foreach ($sylRows as $s) if ($s['name'] === 'NCERT') $ncert = $s['id'];

    $hub = 'NEET_PACE7_StudyHub.html';
    $demoChapters = [
        'Physics'   => ['Current Electricity', 'Ray Optics'],
        'Chemistry' => ['Electrochemistry', 'Haloalkanes, Alcohols, Phenols & Ethers'],
        'Botany'    => ['Principles of Inheritance & Linkage'],
        'Zoology'   => ['Evolution'],
    ];
    $findSubj = $pdo->prepare("SELECT id FROM subjects WHERE class_id=? AND syllabus_id=? AND name=?");
    $insChap  = $pdo->prepare("INSERT INTO chapters (subject_id, name, hub_file, sort) VALUES (?,?,?,?)");
    foreach ($demoChapters as $subjName => $chaps) {
        $findSubj->execute([$c12, $ncert, $subjName]);
        $sid = $findSubj->fetch()['id'] ?? null;
        if ($sid) {
            foreach ($chaps as $i => $cn) $insChap->execute([$sid, $cn, $hub, $i]);
        }
    }
    $messages[] = "Seeded demo Class-12 NCERT chapters linked to the interactive hub";

    $pdo->prepare("INSERT INTO settings (k,v) VALUES ('installed', ?)")->execute([date('c')]);
    $seeded = true;
} else {
    $seeded = false;
    $messages[] = "Users already exist — skipped seeding (tables verified).";
}

/* ============================================================
   3. MIGRATIONS (always run — idempotent).
   Adds new columns to existing tables, seeds permissions/roles
   and backfills the legacy users.role ENUM into user_roles.
   Safe to re-run any time.
   ============================================================ */

function col_exists($pdo, $table, $col) {
    $r = $pdo->prepare("SELECT COUNT(*) c FROM information_schema.columns
                        WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $r->execute([$table, $col]);
    return (int)$r->fetch()['c'] > 0;
}
function add_col($pdo, $table, $col, $def, &$msgs) {
    if (!col_exists($pdo, $table, $col)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        $msgs[] = "Added $table.$col";
    }
}

/* 3a. users — new SaaS columns */
add_col($pdo, 'users', 'email',                "VARCHAR(190) DEFAULT NULL", $messages);
add_col($pdo, 'users', 'status',               "ENUM('active','disabled','pending') NOT NULL DEFAULT 'active'", $messages);
add_col($pdo, 'users', 'must_change_password', "TINYINT(1) NOT NULL DEFAULT 0", $messages);
add_col($pdo, 'users', 'created_by',           "INT DEFAULT NULL", $messages);
add_col($pdo, 'users', 'dob',                  "DATE DEFAULT NULL", $messages);

// unique index on email (multiple NULLs allowed in MySQL UNIQUE)
$r = $pdo->prepare("SELECT COUNT(*) c FROM information_schema.statistics
                    WHERE table_schema=DATABASE() AND table_name='users' AND index_name='uniq_users_email'");
$r->execute();
if ((int)$r->fetch()['c'] === 0) {
    $pdo->exec("ALTER TABLE users ADD UNIQUE KEY uniq_users_email (email)");
    $messages[] = "Added unique index users.email";
}

/* 3b. tenant_id on every content/user/attempt table (single tenant for now;
   future B2B / school sign-ups become an UPDATE of this column only). */
foreach (['users','classes','syllabi','subjects','chapters','topics','study_items','study_content',
          'papers','questions','tests','test_questions','attempts','attempt_answers',
          'activity_log','study_sessions','flashcard_reviews'] as $t) {
    add_col($pdo, $t, 'tenant_id', "INT NOT NULL DEFAULT 1", $messages);
}

/* 3c. Permissions catalogue (idempotent INSERT IGNORE by code). */
$perms = [
    ['study.view',        'Browse study material'],
    ['study.edit',        'Add / edit / upload study items'],
    ['paper.upload',      'Upload past-paper images'],
    ['paper.extract',     'Run AI extraction on uploaded pages'],
    ['question.publish',  'Edit & publish question bank items'],
    ['test.create',       'Create tests from the question bank'],
    ['test.attempt',      'Attempt tests'],
    ['test.delete',       'Delete tests and their attempts'],
    ['users.manage',      'Create / edit / disable users; assign roles & scopes'],
    ['reports.view_self', 'View own performance reports'],
    ['reports.view_all',  'View all students\' reports'],
    ['billing.manage',    'Manage plans / subscriptions / coupons'],
    ['system.diagnose',   'Run self-test, exports and diagnostic tools'],
];
$pi = $pdo->prepare("INSERT IGNORE INTO permissions (code, description) VALUES (?,?)");
foreach ($perms as $p) $pi->execute($p);

/* 3d. Roles catalogue (idempotent). */
$roles = [
    ['superadmin', 'Super Admin'],
    ['org_admin',  'Organisation Admin'],
    ['tutor',      'Tutor'],
    ['parent',     'Parent / Guardian'],
    ['student',    'Student'],
];
$ri = $pdo->prepare("INSERT IGNORE INTO roles (code, name) VALUES (?,?)");
foreach ($roles as $r) $ri->execute($r);

/* 3e. Role → permission mapping (additive: INSERT IGNORE never strips
   custom rows an admin might add later via SQL). */
$roleMap = [
    'superadmin' => array_column($perms, 0),  // everything
    'org_admin'  => ['study.view','study.edit','paper.upload','paper.extract','question.publish',
                     'test.create','test.delete','users.manage','reports.view_all','system.diagnose'],
    'tutor'      => ['study.view','study.edit','paper.upload','paper.extract','question.publish',
                     'test.create','reports.view_all'],
    'parent'     => ['study.view','reports.view_self'],
    'student'    => ['study.view','test.attempt','reports.view_self'],
];
$ins = $pdo->prepare(
    "INSERT IGNORE INTO role_permissions (role_id, permission_id)
     SELECT r.id, p.id FROM roles r, permissions p WHERE r.code=? AND p.code=?"
);
foreach ($roleMap as $rcode => $codes) {
    foreach ($codes as $pcode) $ins->execute([$rcode, $pcode]);
}

/* 3f. Backfill: every legacy user with users.role gets a user_roles row. */
if (col_exists($pdo, 'users', 'role')) {
    $pdo->exec(
        "INSERT IGNORE INTO user_roles (user_id, role_id)
         SELECT u.id, r.id FROM users u JOIN roles r ON r.code = u.role"
    );
    $messages[] = "Backfilled user_roles from legacy users.role";
}

$messages[] = "Migrations + RBAC seed complete (idempotent).";

?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install · <?php echo APP_NAME; ?></title>
<style>
body{font-family:system-ui,sans-serif;background:#f3eee3;color:#2d2a24;margin:0;padding:30px 16px;line-height:1.6}
.box{max-width:640px;margin:0 auto;background:#fffdf8;border:1px solid #e5ddcb;border-radius:16px;padding:26px 24px}
h1{font-size:1.5rem;margin:0 0 4px}.ok{color:#2f9e6e}.warn{background:#fff4e5;border:1px solid #ffd9a8;border-radius:10px;padding:12px 14px;margin:16px 0;color:#8a5a12}
code{background:#f3eee3;padding:2px 7px;border-radius:5px;font-size:.92em}
ul{padding-left:18px}li{margin:3px 0;font-size:.92rem}
.cred{background:#eef6ff;border:1px solid #c9e0fb;border-radius:10px;padding:14px;margin:14px 0}
a.btn{display:inline-block;margin-top:8px;background:#3b5bdb;color:#fff;text-decoration:none;padding:11px 18px;border-radius:10px;font-weight:600}
</style></head><body>
<div class="box">
  <h1 class="ok">✓ Installation complete</h1>
  <p>Database tables created/verified and starter data is in place.</p>
  <?php if ($seeded): ?>
  <div class="cred">
    <b>Default logins (change after first sign-in):</b><br>
    Super Admin → user <code>appa</code> · pass <code>appa@123</code><br>
    Student → user <code>son</code> · pass <code>son@123</code>
  </div>
  <?php endif; ?>
  <div class="warn"><b>Important:</b> delete <code>install.php</code> from your server now, then log in.</div>
  <details><summary>Setup log</summary><ul>
    <?php foreach ($messages as $m) echo '<li>' . e($m) . '</li>'; ?>
  </ul></details>
  <a class="btn" href="login.php">Go to login →</a>
</div></body></html>
