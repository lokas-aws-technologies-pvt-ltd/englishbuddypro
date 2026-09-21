<?php
/**
 * Copy this file to config.php (same folder as score.php / score_gemini.php)
 * and fill in your keys. Do NOT commit config.php to a public repo.
 */

// --- Azure (score.php) ---
// Azure portal -> your Speech resource -> "Keys and Endpoint".
$AZURE_KEY    = 'PASTE_YOUR_AZURE_SPEECH_KEY_HERE';
$AZURE_REGION = 'centralindia';   // e.g. centralindia, eastus, southeastasia

// --- Gemini (score_gemini.php) ---
// Google AI Studio -> https://aistudio.google.com/apikey
$GEMINI_KEY   = 'PASTE_YOUR_GEMINI_API_KEY_HERE';
// $GEMINI_MODEL = 'gemini-3.5-flash-lite';  // uncomment to override the default

// Audio generator (generate_audio.php) — set to a long random string.
$GEN_SECRET = 'change-me-to-a-long-random-string';

// --- MySQL (log.php / db.php) — progress tracking for teachers (Phase 1) ---
// In cPanel > MySQL Databases: create a database + user, add the user to the
// database with ALL PRIVILEGES, then paste the names below. The tables are
// created automatically on the first logged attempt. Leave $DB_NAME empty to
// disable logging entirely (the app then just skips it).
$DB_HOST = 'localhost';
$DB_NAME = '';   // e.g. vna10l90oed1_englishbuddy
$DB_USER = '';   // e.g. vna10l90oed1_ebuser
$DB_PASS = '';   // the database user's password
