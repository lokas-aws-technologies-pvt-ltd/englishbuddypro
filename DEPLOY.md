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
