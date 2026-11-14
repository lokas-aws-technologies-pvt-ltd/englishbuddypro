# Deploying via cPanel Git Version Control

## One-time setup
1. cPanel > Git Version Control > **Create**.
2. Toggle **Clone a Repository** ON.
3. Clone URL: `https://github.com/lokas-aws-technologies-pvt-ltd/englishbuddypro.git`
4. Repository Path: e.g. `/home/vna10l90oed1/repositories/englishbuddypro`
   (a fresh folder, NOT your web folder).
5. Create. cPanel clones the repo there.

## Point it at your web folder
Two options:

**A. Auto-deploy with .cpanel.yml (recommended)**
- Edit `.cpanel.yml` in the repo: set `DEPLOYPATH` to the real document root for
  `training.igiver.org/englishbuddypro` (check cPanel > Domains for the subdomain root).
- In Git Version Control, open the repo and click **Update from Remote**, then **Deploy HEAD Commit**.
  cPanel runs the tasks and copies the files into your web folder.

**B. No .cpanel.yml**
- Set the Repository Path in step 4 directly to the web folder (must be empty first).
  Then a pull updates the live files directly.

## First run
On the server, copy `config.sample.php` to `config.php` and fill in your
Azure + Gemini keys. This file is git-ignored, so pulls never touch it.

## Each update
Claude pushes a new version to GitHub. In cPanel: open the repo >
**Update from Remote** (pull) > **Deploy HEAD Commit** (if using .cpanel.yml). Done.

## Progress tracking / teacher analytics (one-time, optional)
Each finished attempt is saved to a MySQL database so students see their streak
and teachers can see who is practising. It is **optional** — if you skip this,
the app simply doesn't log (practice and scoring still work).

1. cPanel > **MySQL Databases**:
   - Create a database, e.g. `englishbuddy`.
   - Create a user with a strong password.
   - Add the user to the database with **ALL PRIVILEGES**.
   - Note the cPanel-prefixed names (e.g. `vna10l90oed1_englishbuddy`,
     `vna10l90oed1_ebuser`).
2. In `config.php` fill in `$DB_HOST` (`localhost`), `$DB_NAME`, `$DB_USER`,
   `$DB_PASS` (see `config.sample.php`).
3. Deploy. The `students` and `attempts` tables are created automatically on the
   first logged attempt — nothing else to run.
4. Students sign in once (class code + roll number) in the app; a "Change"
   button in Settings lets them switch. No passwords are stored.

## Backend (admin.php): logins, overview, students
Open `https://training.igiver.org/englishbuddypro/admin.php`.

1. **First time:** create the admin account. It asks for the setup passcode,
   which is `$TEACHER_KEY` in `config.php`, so only someone with server access
   can become admin. Choose your own username and password.
2. **Students:** add students one at a time, or paste / upload a whole class
   (one per line: roll number, then name). Edit (history moves with the
   student), reset progress, or delete.
3. **Logins:** create a login per teacher and give it class codes. A teacher
   sees and manages only those classes. The password is shown once; they can
   change it under My account. Admins can reset passwords.
4. **Overview:** class progress (the old teacher dashboard), limited to the
   classes the signed-in person may see, with a class filter.

**Roster-only sign-in (ON by default):** a student can sign in only if they
are on the Students list. Students who already practised are on it
automatically; new students must be added first. Admins can switch it off on
the Students page. Students who were removed are asked to sign in again.

The old shared teacher passcode no longer opens the dashboard; `teacher.php`
now needs a login.

## Sentences (backend: Sentences tab, admin only)
The app loads its sentences from the server (`library.php`), in the order set
here. The first time, the original 25 are loaded with their existing audio.

- **Add / edit:** sentence, level (Easy, Medium, Hard), up to two hard words,
  each with a short meaning. Hard words must appear in the sentence (the app
  underlines them by matching the word).
- **Voice:** on save, an Indian-English clip (Azure "Neerja") is made for the
  sentence and its hard words, using `$AZURE_KEY` / `$AZURE_REGION` from
  `config.php` (audio only; scoring uses Google). Editing the text makes a new
  clip. Without a key, those sentences use the phone's own voice.
  "Make missing voice clips" fills any gaps, e.g. after adding the key.
  Clips are saved in `uploads/audio/` (server only, never overwritten by deploys).
- **Reorder, retire, bring back.** Every sentence keeps its number (#) for good,
  so students' scores, mastery and the Overview stay correct; sentences are
  retired, never deleted. Changing a sentence a lot? Retire it and add a new one.
- If the server can't be reached, the app uses its built-in 25 sentences.
- `generate_audio.php` (the original one-time audio generator) is no longer
  needed.

## Gifts and points (backend: Gifts & points tab, admin only)
Students earn points for practising and more for improving; a full bar unlocks a
scratch card. Everything is set in the backend, no code edits needed:

- **Points rules:** gift steps (default 100, 250, 450, 700, 1000), then every +N
  (default 350); daily practice points (default 60); points per +1.0 personal
  best (default 10); mastery bonus (default 20). "Use defaults" restores them.
  Only the best 2 tries of a sentence count each day; very short takes earn
  nothing; personal bests and mastery have no daily limit.
- **Gifts:** card 1 = gift 1, card 2 = gift 2 ... the last gift repeats. Each
  gift has a name, a short line and a picture (JPG/PNG/GIF/WebP under 3 MB,
  shrunk to 512 px). Reorder, edit, replace or remove pictures, remove gifts.
  A won gift is stored on the student's card, so later edits never change it.
- Pictures are saved in `uploads/gifts/` on the server (not in GitHub; deploys
  never touch it). That folder must be writable by PHP; it is created
  automatically and blocks scripts from running.
- **Unopened gifts:** who has a gift waiting to be scratched.
- Old colour-theme gifts (from before) still show in students' collections but
  no longer change the app's colours.
- Settings > "Preview a scratch card" in the app shows the effect without saving.

## AI usage and spending limit (backend: AI usage tab, admin only)
Every paid AI pronunciation check is recorded (when, class, roll number, audio
length, tokens, estimated cost, success or error). Before each check the server
applies the limits set on this page:

- **AI pronunciation checks On / Off.**
- **Monthly limit (USD)**, default $5; 0 = no limit. Once this month's
  estimated spend reaches it, checks pause until the 1st of next month.
- **Checks per student per day**, default 0 (no limit).

When a limit applies, students still practise and get their free phone score,
with a short "pronunciation check is resting for now" note and no error.
The page shows this month's spend against the limit with a month-end forecast,
checks per day for 30 days, usage by class, the students using it most, and
recent failed checks (e.g. a missing Gemini key). Costs are estimates from
Google's usage counts ($0.30 text / $0.50 audio input, $2.50 output per million
tokens, including "thinking" tokens); the Google bill is the final figure.

## Student progress view
The "My progress" link under the gift opens the student's day streak, best
streak, sentences tried and mastered, a 7-day chart of tries per day (tap a day
for its tries and average), this week's average vs last week, and the gifts
they have won. It is closed by default so the gift stays the only goal on
screen. It comes from the same `rewards.php` call - nothing to set up. Without
the server the app falls back to the on-phone "Your session" card.

## Student settings (fixed) and AI scoring
Students have no switches. For everyone:

- Every reading is scored on the phone first (free). It also goes to **Google
  Gemini** (`score_gemini.php`) for a pronunciation check, but only when the
  phone score is 7 or more, and not on a sentence whose pronunciation the
  student has already mastered (8+ on AI twice). If the AI check fails, the
  student still gets the phone score.
- Language is English (India); sound with results is on; light mode only.
- **`$GEMINI_KEY` must be set in `config.php`** on the server, or the AI check
  fails and students only get phone scores. Azure (`score.php`) is no longer
  used by the app; it stays in the repo for comparison.
- Developer info (backend contract, cost per attempt) is hidden. Add `?dev=1`
  to the app's address to see it.
- To tune: AI_GATE in index.html; PC_PRON_MASTER_SCORE / PC_PRON_MASTER_TIMES
  in rewards.php. These checks run in the app, so they save money in normal
  use but are not a hard spending cap.

## Indian voice audio (one-time)
The app plays pre-generated Indian-English MP3s (Azure "Neerja") for the hard
words and the Listen button, falling back to the browser voice if a clip is missing.

To create the clips:
1. In `config.php` add: `$GEN_SECRET = 'a-long-random-string';`
2. Deploy, then open once in a browser:
   `https://training.igiver.org/englishbuddypro/generate_audio.php?key=a-long-random-string`
3. It writes `audio/phrases/*.mp3` and `audio/words/*.mp3` using your Azure key
   (a few thousand characters — free tier). When it reports errors=0, you're done.
4. Delete `generate_audio.php` or leave it behind the secret.
