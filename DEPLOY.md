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
