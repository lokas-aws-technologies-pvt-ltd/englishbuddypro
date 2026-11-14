<?php
/**
 * English Buddy - the sentence list for the app (public, read-only JSON).
 * GET -> { ok, sentences:[{ id, t, lvl, audio, words:[{w, meaning, audio}] }] }
 * Only active sentences, in the order set in the backend. The app keeps its
 * built-in list as a fallback if this cannot be reached.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
try {
  require __DIR__ . '/db.php';
  $pdo = pc_pdo();
  $out = [];
  foreach (pc_sentences($pdo) as $r) {
    if ((int)$r['active'] !== 1) continue;
    $words = [];
    foreach ([1, 2] as $k) {
      if (trim((string)$r['word' . $k]) === '') continue;
      $words[] = ['w' => $r['word' . $k], 'meaning' => (string)$r['meaning' . $k], 'audio' => pc_word_audio($r['word' . $k])];
    }
    $audio = ($r['audio'] && is_file(__DIR__ . '/' . $r['audio'])) ? $r['audio'] : null;
    $out[] = ['id' => (int)$r['id'], 't' => $r['text'], 'lvl' => $r['level'], 'audio' => $audio, 'words' => $words];
  }
  echo json_encode(['ok' => true, 'sentences' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'server: ' . $e->getMessage()]);
}
