# Deploying to Hostinger

Every push to `main` deploys to production. The pipeline lives in
[`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) and runs in three
stages:

1. **Build and test** — installs Composer and npm dependencies, runs `npm run build`
   and the full test suite. A red suite stops the deploy.
2. **Pull** — calls Hostinger's auto-deployment webhook, which makes the server
   `git pull` the new commit.
3. **Finish on the server** — uploads the compiled Vite assets over SSH and runs
   [`scripts/deploy.sh`](../scripts/deploy.sh): `composer install --no-dev`,
   migrations, cache rebuild, queue restart, with the site in maintenance mode
   while it happens.

Stage 3 matters. `vendor/` and `public/build/` are gitignored, so a bare `git pull`
on its own leaves the site without dependencies or CSS/JS. Shared hosting has no
Node, so the assets are built in CI and shipped up as a tarball.

## One-time setup

### 1. Hostinger side

In hPanel → **Websites** → your site → **Advanced** → **GIT**:

- Confirm the repository is connected and tracking the `main` branch, with the
  deploy path pointing at the app root (the folder holding `artisan`).
- Click **Auto deployment** and copy the webhook URL it shows.

Then over SSH, in that same app root, one time only:

- Create `.env` (copy `.env.example`, set `APP_ENV=production`, `APP_DEBUG=false`,
  the real `APP_URL`, the MySQL credentials and mail settings, then run
  `php artisan key:generate`). `.env` is gitignored — deploys never touch it.
- Point the domain's document root at `public/`.
- Add a cron job so recurring invoices generate:

  ```
  * * * * * cd /home/USER/path/to/chakra-portal && php artisan schedule:run >> /dev/null 2>&1
  ```

- Generate a deploy key so GitHub Actions can SSH in:

  ```bash
  ssh-keygen -t ed25519 -f ~/.ssh/github_deploy -N ""
  cat ~/.ssh/github_deploy.pub >> ~/.ssh/authorized_keys
  chmod 600 ~/.ssh/authorized_keys
  cat ~/.ssh/github_deploy        # this private key goes into GitHub secrets
  ```

### 2. GitHub side

Add these under **Settings → Secrets and variables → Actions → New repository secret**:

| Secret | Required | Value |
|---|---|---|
| `HOSTINGER_DEPLOY_WEBHOOK` | yes | The auto-deployment URL copied from hPanel |
| `HOSTINGER_SSH_HOST` | yes | SSH host/IP from hPanel → **SSH Access** |
| `HOSTINGER_SSH_USER` | yes | SSH username (e.g. `u123456789`) |
| `HOSTINGER_SSH_KEY` | yes | Contents of `~/.ssh/github_deploy`, including the BEGIN/END lines |
| `HOSTINGER_APP_PATH` | yes | Absolute path to the app root, e.g. `/home/u123456789/domains/example.com/chakra-portal` |
| `HOSTINGER_SSH_PORT` | no | Defaults to `65002`, Hostinger's usual SSH port |
| `HOSTINGER_SSH_KNOWN_HOSTS` | no | Output of `ssh-keyscan -p 65002 <host>`. Without it the workflow trusts the key it sees on first connect |

The webhook and SSH halves are independent: set only the webhook and the server
still pulls, but nothing runs afterwards (the workflow warns about this). Set only
the SSH secrets and add `DEPLOY_GIT_PULL=1` to `scripts/deploy.sh`'s environment to
have the script pull the code itself.

## Deploying

- **Automatic:** push or merge to `main`.
- **Manual:** Actions → **Deploy to Hostinger** → **Run workflow**.
- **From the server:** `cd <app root> && bash scripts/deploy.sh`.

Deploys are serialised — a second push waits for the first to finish rather than
cancelling it, so a migration is never interrupted halfway.

## When something breaks

- **Site stuck in maintenance mode** — a step failed partway. Fix the cause, then
  `php artisan up`.
- **Assets 404 after a deploy** — the asset upload or unpack failed. Check the
  *Upload compiled assets to server* step, or re-run the workflow.
- **`Refusing to run tests against [mysql/...]`** — a cached config leaked into the
  test run. `php artisan config:clear`, run the suite, then `php artisan config:cache`.
- **Migration failed** — the deploy stops before the caches are rebuilt and the site
  stays in maintenance mode. Roll the schema back or fix it manually over SSH, then
  re-run the workflow.
- **Wrong PHP version on the server** — set `PHP_BIN` in the script's environment, or
  change the PHP version in hPanel. The app needs PHP 8.2+.
