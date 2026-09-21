<?php
/**
 * Phrase Coach - one-time audio generator (Indian English, Azure Neerja voice).
 * -------------------------------------------------------------------------
 * Run ONCE from a browser:
 *     https://your-site/englishbuddypro/generate_audio.php?key=YOUR_SECRET
 *
 * It reads the Azure key already in config.php, synthesises an MP3 for every
 * sentence and every hard word using en-IN-NeerjaNeural, and writes them to:
 *     audio/phrases/p01.mp3 ... p25.mp3     (full sentences)
 *     audio/words/<word>.mp3                (hard words)
 * The app plays these; if a file is missing it falls back to the browser voice.
 *
 * SETUP: in config.php add a line   $GEN_SECRET = 'some-long-random-string';
 * After it has run successfully, delete this file or keep it behind the secret.
 *
 * NOTE: the sentence/word list below must match index.html's library order,
 * because the app looks up sentences by position (p01..p25).
 * -------------------------------------------------------------------------
 */

header('Content-Type: text/plain; charset=utf-8');

$AZURE_KEY = getenv('AZURE_SPEECH_KEY') ?: '';
$AZURE_REGION = getenv('AZURE_SPEECH_REGION') ?: '';
$GEN_SECRET = '';
if (is_file(__DIR__ . '/config.php')) require __DIR__ . '/config.php';

$VOICE = 'en-IN-NeerjaNeural';
$LANG  = 'en-IN';

if ($GEN_SECRET === '')                       { http_response_code(500); exit("Set \$GEN_SECRET in config.php first."); }
if (($_GET['key'] ?? '') !== $GEN_SECRET)     { http_response_code(403); exit("Bad or missing ?key."); }
if ($AZURE_KEY === '' || $AZURE_REGION === ''){ http_response_code(500); exit("Missing AZURE_SPEECH_KEY / AZURE_SPEECH_REGION in config.php."); }

// 25 sentences in library order, each with its two hard words.
$LIB = [
  ["Please confirm your availability for Thursday.", ["confirm","availability"]],
  ["I will forward the document immediately.", ["forward","immediately"]],
  ["Kindly acknowledge receipt of this email.", ["acknowledge","receipt"]],
  ["Let's schedule a brief meeting tomorrow.", ["schedule","brief"]],
  ["Please review the attachment before the call.", ["review","attachment"]],
  ["We appreciate your prompt response.", ["appreciate","prompt"]],
  ["The invoice is due by the end of the month.", ["invoice","due"]],
  ["Could you clarify the requirements for me?", ["clarify","requirements"]],
  ["I will coordinate with the vendor today.", ["coordinate","vendor"]],
  ["Please ensure the report is accurate.", ["ensure","accurate"]],
  ["My colleague will handle the presentation.", ["colleague","presentation"]],
  ["We need to prioritise the urgent tasks.", ["prioritise","urgent"]],
  ["The deadline for the project is Wednesday.", ["deadline","project"]],
  ["Please escalate the issue to the manager.", ["escalate","issue"]],
  ["Let's collaborate on the quarterly report.", ["collaborate","quarterly"]],
  ["The client requested a detailed proposal.", ["detailed","proposal"]],
  ["We should allocate the budget carefully.", ["allocate","budget"]],
  ["Please summarise the key points briefly.", ["summarise","briefly"]],
  ["The manager approved the revised schedule.", ["approved","revised"]],
  ["Our team exceeded the monthly target.", ["exceeded","target"]],
  ["Our clientele expects punctual, professional service.", ["clientele","punctual"]],
  ["The entrepreneur negotiated a favourable contract.", ["entrepreneur","negotiated"]],
  ["I appreciate your thorough and prompt feedback.", ["thorough","feedback"]],
  ["My colleague queried the miscellaneous expenses.", ["queried","miscellaneous"]],
  ["The committee will reconvene next Wednesday.", ["committee","reconvene"]],
];

@mkdir(__DIR__.'/audio', 0755, true);
@mkdir(__DIR__.'/audio/phrases', 0755, true);
@mkdir(__DIR__.'/audio/words', 0755, true);

$endpoint = "https://{$AZURE_REGION}.tts.speech.microsoft.com/cognitiveservices/v1";

function synth($text, $outfile, $endpoint, $key, $voice, $lang) {
  if (is_file($outfile)) return "skip (exists)";
  $safe = htmlspecialchars($text, ENT_XML1, 'UTF-8');
  $ssml = "<speak version='1.0' xml:lang='{$lang}'><voice xml:lang='{$lang}' name='{$voice}'>{$safe}</voice></speak>";
  $ch = curl_init($endpoint);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $ssml,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
      'Ocp-Apim-Subscription-Key: ' . $key,
      'Content-Type: application/ssml+xml',
      'X-Microsoft-OutputFormat: audio-24khz-48kbitrate-mono-mp3',
      'User-Agent: phrasecoach',
    ],
  ]);
  $audio = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($audio === false) return "ERROR curl: $err";
  if ($status !== 200)  return "ERROR http $status: " . substr($audio, 0, 120);
  if (file_put_contents($outfile, $audio) === false) return "ERROR write (check folder permissions)";
  return "ok (" . strlen($audio) . " bytes)";
}

function wslug($w){ return preg_replace('/[^a-z0-9]+/', '', strtolower($w)); }

echo "Voice: {$VOICE}  Region: {$AZURE_REGION}\n\n";
$made = 0; $skipped = 0; $errors = 0;
$seenWords = [];

foreach ($LIB as $i => $row) {
  [$sentence, $words] = $row;
  $n = str_pad($i + 1, 2, '0', STR_PAD_LEFT);
  $r = synth($sentence, __DIR__."/audio/phrases/p{$n}.mp3", $endpoint, $AZURE_KEY, $VOICE, $LANG);
  echo "p{$n}  \"{$sentence}\"  -> {$r}\n";
  if (strpos($r,'ok')===0) $made++; elseif (strpos($r,'skip')===0) $skipped++; else $errors++;
  foreach ($words as $w) {
    $s = wslug($w);
    if (isset($seenWords[$s])) continue;
    $seenWords[$s] = true;
    $r = synth($w, __DIR__."/audio/words/{$s}.mp3", $endpoint, $AZURE_KEY, $VOICE, $LANG);
    echo "   word {$w}  -> {$r}\n";
    if (strpos($r,'ok')===0) $made++; elseif (strpos($r,'skip')===0) $skipped++; else $errors++;
  }
}

echo "\nDone. made={$made}  skipped={$skipped}  errors={$errors}\n";
echo "If errors are 0, your audio is ready. Delete this file or keep it behind the secret.\n";
