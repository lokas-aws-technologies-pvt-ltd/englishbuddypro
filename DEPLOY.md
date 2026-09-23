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

## Teacher dashboard (teacher.php)
A read-only page showing class progress: confidence trend, practice volume, a
student roster with streaks and score sparklines, the most-missed "focus words",
and the toughest phrases. It reads the same MySQL data that `log.php` writes.

1. Set the MySQL credentials above (the dashboard needs them).
2. In `config.php` set `$TEACHER_KEY` to a long, private passcode.
3. Open `https://training.igiver.org/englishbuddypro/teacher.php`, enter the
   passcode once (it's held in a session cookie, never in the URL). Use **Sign out**
   to end the session; **Refresh** reloads the latest numbers.

Only you should know the passcode — anyone with it can see the class data.

## Scratch-card rewards (rewards.php)
Students earn points for practising and bigger points for improving, and unlock
scratch cards with in-app rewards (badges, colour themes, a title). It uses the
same MySQL database - no extra setup; the `rewards` table creates itself.

- Points: each try earns its score (best 2 tries per sentence per day count, max
  30 tries a day). Beating your own best earns 10 per point gained; scoring 8+
  on a sentence on 2 different days masters it (+20).
- Cards unlock at 100, 250, 450, 700, 1000 points, then every +350 - only after
  practising on 2 different days AND improving (3 new personal bests, or a
  mastered sentence, or a higher recent average) since the last card.
- The app always shows the next reward and the next step toward it.
- Tune the numbers at the top of `rewards.php` once you have a few weeks of data.
- Settings > "Preview a scratch card" shows the effect without saving anything.

## Student progress view
Signed-in students see a "Your progress" card: their day streak (🔥 badge in the
top bar too, grey until they practise today), best streak, sentences tried and
mastered, and a 7-day chart of tries per day (tap a day for its tries and
average), plus this week's average vs last week. It comes from the same
`rewards.php` call - nothing to set up. Without the server it falls back to the
on-phone "Your session" card.

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
