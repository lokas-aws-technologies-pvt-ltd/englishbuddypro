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
