# English Buddy Pro

Read-aloud English practice web app (Phrase Coach). Static page + PHP scoring
endpoints, deployed to GoDaddy via cPanel Git Version Control.

## Files
- `index.html` — the student app (records, scores pace/pauses/confidence on-device;
  optional AI pronunciation via Azure or Gemini).
- `score.php` — Azure Pronunciation Assessment endpoint.
- `score_gemini.php` — Gemini Flash-Lite scoring endpoint (A/B alternative).
- `.htaccess` — protects `config.php` from being served.
- `config.sample.php` — copy to `config.php` on the server and add your keys.
- `.cpanel.yml` — cPanel deploy tasks (set DEPLOYPATH before first deploy).

## Secrets
`config.php` holds the Azure and Gemini API keys and is **git-ignored**. It lives
only on the server and is never overwritten by a pull/deploy.

## Deploy
See `DEPLOY.md`.
