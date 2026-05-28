<?php
/* ============================================================
   Admin: download a SQL backup of all app data. Restore by running
   install.php on a fresh database (to create the tables), then
   importing this file via phpMyAdmin. AI-generated study material and
   extracted questions are expensive to recreate — back them up.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
require_admin();

$tables = ['users','settings','classes','syllabi','subjects','chapters','topics','study_content',
           'papers','questions','tests','test_questions','attempts','attempt_answers',
           'activity_log','study_sessions','flashcard_reviews'];

$pdo = db();
$fname = 'studyzone-backup-' . date('Ymd-Hi') . '.sql';
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');

echo "-- NEET Study Zone backup · " . date('c') . "\n";
echo "-- Restore: run install.php on an empty DB to create tables, then import this file.\n";
echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $t) {
    if (!preg_match('/^[a-z_]+$/', $t)) continue;          // safety: table names are hard-coded, but be sure
    try { $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll(); }
    catch (Throwable $ex) { echo "-- $t: skipped ({$ex->getMessage()})\n\n"; continue; }
    if (!$rows) { echo "-- $t: empty\n\n"; continue; }
    $cols = array_keys($rows[0]);
    $colList = '`' . implode('`,`', $cols) . '`';
    echo "-- $t (" . count($rows) . " rows)\n";
    foreach ($rows as $r) {
        $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($r));
        echo "INSERT INTO `$t` ($colList) VALUES (" . implode(',', $vals) . ");\n";
    }
    echo "\n";
}
echo "SET FOREIGN_KEY_CHECKS=1;\n";
exit;
