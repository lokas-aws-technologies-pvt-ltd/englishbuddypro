<?php
/**
 * Phrase Coach — /api/score
 * -------------------------------------------------------------------------
 * Runs on GoDaddy Linux / cPanel hosting (PHP 7.4+). Same domain as the
 * HTML page, so there is no CORS to configure.
 *
 * It receives a WAV recording + the reference sentence, forwards the audio to
 * Azure Speech "Pronunciation Assessment", and returns the compact JSON that
 * phrase-coach.html expects:
 *
 *   { pronunciation, completeness, fluency (0..100),
 *     words: [ { word, errorType, accuracy, offsetMs, durationMs } ] }
 *
 * SETUP
 *   1. Create an Azure "Speech" resource, copy its KEY and REGION
 *      (region is a short code like "centralindia", "eastus").
 *   2. Put those in config.php next to this file (see config.sample.php).
 *      Keeping the key in a separate file — not in this script — makes it
 *      easy to keep out of git and to lock down.
 *   3. Upload score.php, config.php and .htaccess to your web root.
 *   4. In the app's Settings, turn on "Use my live backend" and set the
 *      API base to your site, e.g. https://yourdomain.com
 * -------------------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');

// --- load credentials -----------------------------------------------------
$AZURE_KEY    = getenv('AZURE_SPEECH_KEY')    ?: '';
$AZURE_REGION = getenv('AZURE_SPEECH_REGION') ?: '';
if (is_file(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';   // may set $AZURE_KEY / $AZURE_REGION
}

function fail($httpCode, $message) {
    http_response_code($httpCode);
    echo json_encode(['error' => $message]);
    exit;
}

// --- basic guards ----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST')  fail(405, 'Use POST.');
if ($AZURE_KEY === '' || $AZURE_REGION === '') fail(500, 'Server is missing AZURE_SPEECH_KEY / AZURE_SPEECH_REGION.');

$reference = isset($_POST['reference']) ? trim($_POST['reference']) : '';
$language  = isset($_POST['language'])  ? trim($_POST['language'])  : 'en-US';
if ($reference === '') fail(400, 'Missing reference text.');
if (!preg_match('/^[a-z]{2}-[A-Z]{2}$/', $language)) $language = 'en-US';

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    fail(400, 'No audio uploaded.');
}
$tmp  = $_FILES['audio']['tmp_name'];
$name = strtolower($_FILES['audio']['name'] ?? 'take.wav');
$size = filesize($tmp);
if ($size < 100)        fail(400, 'Audio file is empty.');
if ($size > 6*1024*1024) fail(413, 'Audio too large (max 6 MB).');

// --- pick the Content-Type Azure needs for the uploaded bytes --------------
// Azure's short-audio REST endpoint accepts WAV (PCM) and OGG/OPUS.
// The page sends 16 kHz mono WAV, which matches the first branch.
if (substr($name, -4) === '.wav') {
    $audioContentType = 'audio/wav; codecs=audio/pcm; samplerate=16000';
} elseif (substr($name, -4) === '.ogg') {
    $audioContentType = 'audio/ogg; codecs=opus';
} else {
    fail(415, 'Send 16 kHz mono WAV (or OGG/Opus). Other formats need server-side transcoding.');
}

$audioBytes = file_get_contents($tmp);
if ($audioBytes === false) fail(500, 'Could not read the uploaded audio.');

// --- build the Pronunciation-Assessment header -----------------------------
// EnableMiscue=true makes skipped words (Omission), extra words and fillers
// (Insertion) show up in the per-word results.
$paParams = [
    'ReferenceText' => $reference,
    'GradingSystem' => 'HundredMark',
    'Granularity'   => 'Word',
    'Dimension'     => 'Comprehensive',
    'EnableMiscue'  => true,
];
$paHeader = base64_encode(json_encode($paParams, JSON_UNESCAPED_UNICODE));

$endpoint = "https://{$AZURE_REGION}.stt.speech.microsoft.com/speech/recognition/"
          . "conversation/cognitiveservices/v1?language={$language}&format=detailed";

// --- call Azure ------------------------------------------------------------
$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $audioBytes,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'Ocp-Apim-Subscription-Key: ' . $AZURE_KEY,
        'Content-Type: ' . $audioContentType,
        'Accept: application/json',
        'Pronunciation-Assessment: ' . $paHeader,
        'Expect:',
    ],
]);
$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr   = curl_error($ch);
curl_close($ch);

if ($body === false)  fail(502, 'Could not reach Azure: ' . $cerr);
$snippet = substr((string)$body, 0, 400);
if ($status === 401 || $status === 403) fail(502, "Azure rejected the key or region (HTTP {$status}). Region used: {$AZURE_REGION}. Azure said: {$snippet}");
if ($status !== 200)  fail(502, "Azure returned HTTP {$status}. Region used: {$AZURE_REGION}, language: {$language}, audio: {$audioContentType}. Azure said: {$snippet}");

$data = json_decode($body, true);
if (!is_array($data)) fail(502, 'Azure sent a response that was not JSON.');

$recStatus = $data['RecognitionStatus'] ?? '';
if ($recStatus !== 'Success') {
    // NoMatch = nothing intelligible; usually silence, wrong mic, or bad format.
    fail(422, 'No speech recognised (' . $recStatus . '). Ask the student to record again.');
}

// Find the first NBest entry that carries assessment scores. Azure returns them
// either flat on the entry (AccuracyScore) or nested (PronunciationAssessment).
$nbest = null;
foreach (($data['NBest'] ?? []) as $cand) {
    if (isset($cand['AccuracyScore']) || isset($cand['PronunciationAssessment'])) { $nbest = $cand; break; }
}
if (!$nbest) {
    // Dump what Azure really sent so we can see why the assessment is missing.
    $raw = substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 700);
    fail(502,
        "Azure response had no pronunciation scores. "
        . "RecognitionStatus=" . ($data['RecognitionStatus'] ?? '?') . ". "
        . "DisplayText=\"" . ($data['DisplayText'] ?? '') . "\". "
        . "NBestCount=" . count($data['NBest'] ?? []) . ". "
        . "Raw: " . $raw
    );
}

// --- map Azure -> the page's contract --------------------------------------
// Scores may be flat on the entry, or nested under PronunciationAssessment.
$pa = $nbest['PronunciationAssessment'] ?? $nbest;
$words = [];
foreach (($nbest['Words'] ?? []) as $w) {
    $wpa = $w['PronunciationAssessment'] ?? $w;   // per-word: flat or nested
    $words[] = [
        'word'       => (string)($w['Word'] ?? ''),
        'errorType'  => (string)($wpa['ErrorType'] ?? 'None'),   // None|Mispronunciation|Omission|Insertion
        'accuracy'   => (float)($wpa['AccuracyScore'] ?? 0),
        // Azure offsets/durations are in 100-nanosecond ticks -> milliseconds
        'offsetMs'   => (int)round(($w['Offset']   ?? 0) / 10000),
        'durationMs' => (int)round(($w['Duration'] ?? 0) / 10000),
    ];
}

// --- cost of this attempt (Azure bills by audio seconds) ---
// 16 kHz mono 16-bit WAV -> seconds = (bytes - 44 header) / (16000 * 2)
$AZURE_RATE_PER_HOUR = 1.30;   // STT + pronunciation add-on
$billedSeconds = max(0, (strlen($audioBytes) - 44) / 32000.0);
$costUsd = $billedSeconds / 3600.0 * $AZURE_RATE_PER_HOUR;

echo json_encode([
    'pronunciation' => (float)($pa['AccuracyScore']     ?? 0),
    'completeness'  => (float)($pa['CompletenessScore'] ?? 0),
    'fluency'       => (float)($pa['FluencyScore']      ?? 0),
    'words'         => $words,
    '_engine'  => 'azure',
    '_costUsd' => $costUsd,
    '_usage'   => ['billedSeconds' => round($billedSeconds, 2)],
], JSON_UNESCAPED_UNICODE);
