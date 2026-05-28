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
