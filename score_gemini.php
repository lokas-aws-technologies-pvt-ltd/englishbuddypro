<?php
/**
 * Phrase Coach — score_gemini.php   (A/B alternative to score.php)
 * -------------------------------------------------------------------------
 * Same job, same response shape as the Azure endpoint, but scored by
 * Google Gemini (Flash-Lite by default). The page can call either one, so
 * you can compare marks AND cost on the very same recording.
 *
 * Response (identical contract to score.php, plus cost fields):
 *   { pronunciation, completeness, fluency (0..100),
 *     words:[{word, errorType, accuracy}],
 *     _engine:"gemini", _model:"...", _costUsd: <number>, _usage:{...} }
 *
 * SETUP
 *   1. Get a Google AI Studio API key (https://aistudio.google.com/apikey).
 *   2. Put it in config.php next to this file:  $GEMINI_KEY = '...';
 *   3. Upload this file beside index.html and score.php.
 *   4. In Settings choose engine = Gemini.
 * -------------------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');

$GEMINI_KEY   = getenv('GEMINI_API_KEY') ?: '';
$GEMINI_MODEL = 'gemini-3.5-flash-lite';   // cheapest audio-capable; 'gemini-3.5-flash' is pricier
if (is_file(__DIR__ . '/config.php')) require __DIR__ . '/config.php';

// gemini-3.5-flash-lite per-1M-token USD rates (verify if you change the model).
$R_IN_TEXT  = 0.30;   // input tokens (text)
$R_IN_AUDIO = 0.30;   // input tokens (audio)
$R_OUT      = 2.50;   // output tokens

function fail($c,$m){ http_response_code($c); echo json_encode(['error'=>$m]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405,'Use POST.');
if ($GEMINI_KEY === '') fail(500,'Server is missing GEMINI_API_KEY (set $GEMINI_KEY in config.php).');

$reference = isset($_POST['reference']) ? trim($_POST['reference']) : '';
$language  = isset($_POST['language'])  ? trim($_POST['language'])  : 'en-US';
if ($reference === '') fail(400,'Missing reference text.');
if (!preg_match('/^[a-z]{2}-[A-Z]{2}$/',$language)) $language = 'en-US';

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) fail(400,'No audio uploaded.');
$tmp  = $_FILES['audio']['tmp_name'];
$name = strtolower($_FILES['audio']['name'] ?? 'take.wav');
$size = filesize($tmp);
if ($size < 100)         fail(400,'Audio file is empty.');
if ($size > 8*1024*1024) fail(413,'Audio too large (max 8 MB).');

$mime = (substr($name,-4)==='.wav') ? 'audio/wav'
      : ((substr($name,-4)==='.ogg') ? 'audio/ogg'
      : ((substr($name,-4)==='.mp3') ? 'audio/mpeg'
      : ((substr($name,-4)==='.m4a'||substr($name,-4)==='.aac') ? 'audio/aac' : 'audio/wav')));

$audioB64 = base64_encode(file_get_contents($tmp));

$prompt =
  "You are a strict, consistent English pronunciation examiner.\n".
  "The learner was asked to read this exact sentence aloud in {$language}:\n".
  "\"{$reference}\"\n\n".
  "Listen to the audio and judge how well the WORDS were PRONOUNCED (not the pace or pauses — those are measured separately).\n".
  "Return ONLY JSON with these fields:\n".
  "- pronunciation: 0-100, overall clarity/accuracy of the spoken words\n".
  "- completeness: 0-100, share of the reference words that were actually spoken\n".
  "- fluency: 0-100, smoothness and naturalness of the delivery\n".
  "- words: one entry per reference word, in order, each { word, errorType, accuracy }\n".
  "  errorType is exactly one of: None, Mispronunciation, Omission, Insertion; accuracy is 0-100.\n".
  "Add any extra or filler words the learner actually said (for example um, uh, er) as additional entries with errorType Insertion.\n".
  "Score the same audio the same way every time. Do not add commentary outside the JSON.";

$body = [
  'contents' => [[ 'parts' => [
      ['text' => $prompt],
      ['inline_data' => ['mime_type'=>$mime, 'data'=>$audioB64]]
  ]]],
  'generationConfig' => [
    'temperature' => 0,
    'responseMimeType' => 'application/json',
    'responseSchema' => [
      'type' => 'OBJECT',
      'properties' => [
        'pronunciation' => ['type'=>'NUMBER'],
        'completeness'  => ['type'=>'NUMBER'],
        'fluency'       => ['type'=>'NUMBER'],
        'words' => ['type'=>'ARRAY','items'=>[
          'type'=>'OBJECT',
          'properties'=>[
            'word'      => ['type'=>'STRING'],
            'errorType' => ['type'=>'STRING','enum'=>['None','Mispronunciation','Omission','Insertion']],
            'accuracy'  => ['type'=>'NUMBER']
          ],
          'propertyOrdering'=>['word','errorType','accuracy'],
          'required'=>['word','errorType','accuracy']
        ]]
      ],
      'propertyOrdering'=>['pronunciation','completeness','fluency','words'],
      'required'=>['pronunciation','completeness','fluency','words']
    ]
  ]
];

$url = "https://generativelanguage.googleapis.com/v1beta/models/{$GEMINI_MODEL}:generateContent?key=".urlencode($GEMINI_KEY);
$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => json_encode($body),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 40,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Expect:']
]);
$resp = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
curl_close($ch);

if ($resp === false) fail(502,'Could not reach Gemini: '.$cerr);
$data = json_decode($resp, true);
if (!is_array($data)) fail(502,'Gemini sent a non-JSON response.');
if ($status !== 200) {
  $msg = $data['error']['message'] ?? substr($resp,0,300);
  fail(502, "Gemini returned HTTP {$status}: {$msg}");
}

$cand = $data['candidates'][0] ?? null;
if (!$cand) {
  $fb = $data['promptFeedback']['blockReason'] ?? 'no candidates';
  fail(422, "Gemini returned no result ({$fb}). Ask the learner to record again.");
}
// concatenate any text parts, then parse the JSON the model produced
$text = '';
foreach (($cand['content']['parts'] ?? []) as $p) { if (isset($p['text'])) $text .= $p['text']; }
$out = json_decode($text, true);
if (!is_array($out) || !isset($out['words']) || !is_array($out['words'])) {
  fail(502, "Gemini's answer was not the expected JSON. Raw: ".substr($text,0,300));
}

// --- cost from real token usage ---
$um = $data['usageMetadata'] ?? [];
$outTok = $um['candidatesTokenCount'] ?? 0;
$audioTok = 0; $textTok = 0;
foreach (($um['promptTokensDetails'] ?? []) as $d) {
  if (($d['modality'] ?? '') === 'AUDIO') $audioTok += ($d['tokenCount'] ?? 0);
  else $textTok += ($d['tokenCount'] ?? 0);
}
if ($audioTok === 0 && $textTok === 0) $textTok = $um['promptTokenCount'] ?? 0; // fallback if no modality split
$costUsd = ($audioTok*$R_IN_AUDIO + $textTok*$R_IN_TEXT + $outTok*$R_OUT) / 1000000.0;

// --- map to the page's contract (words carry no timestamps; the page measures pauses itself) ---
$words = [];
foreach ($out['words'] as $w) {
  $words[] = [
    'word'       => (string)($w['word'] ?? ''),
    'errorType'  => (string)($w['errorType'] ?? 'None'),
    'accuracy'   => (float)($w['accuracy'] ?? 0),
    'offsetMs'   => 0,
    'durationMs' => 0
  ];
}

echo json_encode([
  'pronunciation' => (float)($out['pronunciation'] ?? 0),
  'completeness'  => (float)($out['completeness'] ?? 0),
  'fluency'       => (float)($out['fluency'] ?? 0),
  'words'         => $words,
  '_engine' => 'gemini',
  '_model'  => $GEMINI_MODEL,
  '_costUsd'=> $costUsd,
  '_usage'  => ['audioTokens'=>$audioTok, 'textTokens'=>$textTok, 'outputTokens'=>$outTok]
], JSON_UNESCAPED_UNICODE);
