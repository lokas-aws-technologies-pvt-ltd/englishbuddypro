<?php
/**
 * English Buddy - Indian-English voice clips (Azure "Neerja") for new or edited
 * sentences and hard words. Uses $AZURE_KEY / $AZURE_REGION from config.php,
 * for audio only (scoring uses Google). Without a key, nothing is generated and
 * the app uses the phone's own voice for those sentences.
 */
const PC_TTS_VOICE = 'en-IN-NeerjaNeural';
const PC_TTS_DIR   = __DIR__ . '/uploads/audio';

function pc_tts_config() {
  $AZURE_KEY = getenv('AZURE_SPEECH_KEY') ?: ''; $AZURE_REGION = getenv('AZURE_SPEECH_REGION') ?: '';
  if (is_file(__DIR__ . '/config.php')) { $cfg = (function () use ($AZURE_KEY, $AZURE_REGION) { require __DIR__ . '/config.php'; return [$AZURE_KEY, $AZURE_REGION]; })(); [$AZURE_KEY, $AZURE_REGION] = $cfg; }
  return [(string)$AZURE_KEY, (string)$AZURE_REGION];
}
function pc_tts_ready() { [$k, $r] = pc_tts_config(); return $k !== '' && $r !== ''; }

function pc_tts_dir($sub = '') {
  $dir = PC_TTS_DIR . ($sub !== '' ? '/' . $sub : '');
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new Exception('Could not create the uploads/audio folder on the server.');
  if (!is_file(PC_TTS_DIR . '/.htaccess')) {   // audio only: never run scripts from this folder
    @file_put_contents(PC_TTS_DIR . '/.htaccess', "Options -Indexes -ExecCGI\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n");
  }
  return $dir;
}
/* Speak $text into $file (MP3). Throws with a short reason on failure. */
function pc_tts_to_file($text, $file) {
  [$key, $region] = pc_tts_config();
  if ($key === '' || $region === '') throw new Exception('no Azure key in config.php');
  $safe = htmlspecialchars($text, ENT_XML1, 'UTF-8');
  $ssml = "<speak version='1.0' xml:lang='en-IN'><voice xml:lang='en-IN' name='" . PC_TTS_VOICE . "'>{$safe}</voice></speak>";
  $ch = curl_init("https://{$region}.tts.speech.microsoft.com/cognitiveservices/v1");
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $ssml, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => ['Ocp-Apim-Subscription-Key: ' . $key, 'Content-Type: application/ssml+xml',
                           'X-Microsoft-OutputFormat: audio-24khz-48kbitrate-mono-mp3', 'User-Agent: englishbuddy']]);
  $audio = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  if ($audio === false) throw new Exception('could not reach Azure (' . $err . ')');
  if ($status !== 200 || strlen($audio) < 200) throw new Exception('Azure returned HTTP ' . $status);
  if (file_put_contents($file, $audio) === false) throw new Exception('could not save the audio file (folder permissions)');
}
/* Clip for a sentence; a new file whenever the text changes. Returns the web path. */
function pc_tts_sentence($id, $text) {
  $dir = pc_tts_dir();
  $name = 's' . (int)$id . '-' . substr(md5($text), 0, 8) . '.mp3';
  if (!is_file($dir . '/' . $name)) pc_tts_to_file($text, $dir . '/' . $name);
  return 'uploads/audio/' . $name;
}
/* Clip for a hard word, unless one already exists. */
function pc_tts_word($w) {
  $slug = pc_word_slug($w);
  if ($slug === '' || pc_word_audio($w)) return;
  pc_tts_to_file($w, pc_tts_dir('words') . '/' . $slug . '.mp3');
}
