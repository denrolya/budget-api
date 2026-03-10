<?php

/**
 * Deployer 7.x — Budget API
 *
 * Config lives in .env.local (gitignored). Add DEPLOY_* and DB_* vars — see .env for the full list.
 *
 * Usage:
 *   vendor/bin/dep deploy production          — full deploy (tests → build → symlink)
 *   vendor/bin/dep deploy:dev production      — deploy with dev deps + Symfony profiler (APP_ENV=dev)
 *   vendor/bin/dep app:logs production        — tail production log (last 100 lines)
 *   vendor/bin/dep app:shell production       — interactive SSH session
 *   vendor/bin/dep app:cache:clear production — clear + warm Symfony cache remotely
 * 
 *   vendor/bin/dep app:bank:sync production — run polling sync on remote
 *   vendor/bin/dep app:bank:webhooks:refresh production — refresh webhooks on remote
 *   vendor/bin/dep app:bank:maintenance production — run webhook refresh + polling sync on remote
 *   vendor/bin/dep app:bank:sync:logs production — tail polling sync log on remote
 *   vendor/bin/dep app:bank:webhooks:logs production — tail webhook refresh log on remote
 *   vendor/bin/dep app:bank:logs production — tail bank sync/webhook logs on remote
 * 
 *   vendor/bin/dep app:php:restart production — reload php-fpm (after php.ini changes)
 *   vendor/bin/dep app:php_ini:upload production — push local php.ini to server
 *   vendor/bin/dep env:pull production        — download production .env → .env.production locally
 *   vendor/bin/dep env:push production        — upload local .env.production → production shared/.env
 *   vendor/bin/dep db:pull production         — copy remote DB → local
 *   vendor/bin/dep db:push production         — copy local DB → remote (backs up first)
 *   vendor/bin/dep db:backup production       — create timestamped remote SQL dump
 *   vendor/bin/dep db:backup:download production — download all remote backups locally
 *   vendor/bin/dep list                       — list every available task
 */

namespace Deployer;

require 'recipe/symfony.php';

// ── Load .env → .env.local (same precedence order as Symfony) ─────────────────
// Collect all files first so later files (e.g. .env.local) properly override
// earlier ones (e.g. .env), then apply in a single pass so real system env
// vars (set before this script runs) still win over everything.
(function (): void {
    $vars = [];
    $allowedPrefixes = ['DEPLOY_', 'DB_'];
    $allowedKeys = ['APP_ENV'];
    foreach ([__DIR__ . '/.env', __DIR__ . '/.env.local'] as $path) {
        if (!file_exists($path)) {
            continue;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $val] = array_map('trim', explode('=', $line, 2));
            $quoted = strlen($val) >= 2
                && (($val[0] === '"' && $val[-1] === '"') || ($val[0] === "'" && $val[-1] === "'"));
            if ($quoted) {
                $val = substr($val, 1, -1);
            } elseif (($pos = strpos($val, ' #')) !== false) {
                $val = rtrim(substr($val, 0, $pos)); // strip inline comment
            }
            $vars[$key] = $val; // later file wins
        }
    }
    foreach ($vars as $key => $val) {
        $isAllowed = in_array($key, $allowedKeys, true);
        if (!$isAllowed) {
            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $isAllowed = true;
                    break;
                }
            }
        }

        if (!$isAllowed) {
            continue;
        }

        if (getenv($key) === false) { // real system env vars win
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
})();

function env(string $key, mixed $default = null): mixed
{
    $v = getenv($key);
    return $v !== false ? $v : ($_ENV[$key] ?? $default);
}

// ── Project ───────────────────────────────────────────────────────────────────
set('application',   env('DEPLOY_APP',            'api'));
set('repository',    env('DEPLOY_REPO'));
set('git_tty',       false);
set('keep_releases', (int) env('DEPLOY_KEEP_RELEASES', 5));
set('http_user',     env('DEPLOY_HTTP_USER',       'www-data'));

// Override symfony recipe defaults to match the current server layout.
// shared_files includes php.ini so it is managed per-server (not in git).
set('shared_dirs',  ['var/log', 'var/sessions', 'config/jwt', 'backups']);
set('shared_files', ['.env', 'php.ini']);
set('writable_dirs', ['var']);
set('writable_mode', 'acl');

// Skip Composer post-install scripts (cache:clear, assets:install, etc.) during
// deploy:vendors — they would run without --env=prod and fail on a no-dev install
// because dev bundles (MakerBundle etc.) are not present.
// Our deploy:cache:clear task handles cache:clear + cache:warmup with --env=prod.
set('composer_options', '--prefer-dist --no-progress --no-interaction --no-dev --optimize-autoloader --no-scripts');

// ── Host ──────────────────────────────────────────────────────────────────────
host('production')
    ->setHostname(env('DEPLOY_HOST'))
    ->setRemoteUser(env('DEPLOY_USER'))
    ->setIdentityFile(env('DEPLOY_IDENTITY_FILE', '~/.ssh/id_rsa'))
    ->setSshMultiplexing(true)
    ->set('branch',      env('DEPLOY_BRANCH', 'master'))
    ->set('deploy_path', env('DEPLOY_PATH',   '/var/www/{{application}}'));

// ── Database credentials (used by db:* tasks) ─────────────────────────────────
set('db_remote', [
    'host'     => env('DEPLOY_HOST'),
    'name'     => env('DB_REMOTE_NAME'),
    'username' => env('DB_REMOTE_USER'),
    'password' => env('DB_REMOTE_PASSWORD'),
]);
set('db_local', [
    'name'     => env('DB_LOCAL_NAME',     'budget'),
    'username' => env('DB_LOCAL_USER',     'root'),
    'password' => env('DB_LOCAL_PASSWORD', ''),
]);

// ── Deploy pipeline ───────────────────────────────────────────────────────────
task('deploy', [
    'deploy:run_tests',   // run PHPUnit locally — abort on failure
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:shared',
    'deploy:writable',
    'deploy:vendors',
    'deploy:cache:clear',
    'deploy:symlink',
    'deploy:unlock',
    'deploy:cleanup',
    'deploy:success',
])->desc('Deploy application to production');

// ── Dev deploy (with profiler) ─────────────────────────────────────────────────
// Installs dev dependencies (WebProfiler, DebugBundle) and sets APP_ENV=dev.
// Usage: composer deploy:dev  — or — vendor/bin/dep deploy:dev production
task('deploy:dev', [
    'deploy:run_tests',
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:shared',
    'deploy:writable',
    'deploy:vendors:dev',
    'deploy:cache:clear:dev',
    'deploy:symlink',
    'deploy:unlock',
    'deploy:cleanup',
    'deploy:success',
])->desc('Deploy with dev dependencies + Symfony profiler enabled (APP_ENV=dev)');

task('deploy:vendors:dev', function () {
    run('cd {{release_or_current_path}} && {{bin/composer}} install --prefer-dist --no-progress --no-interaction --no-scripts');
    writeln('<info>✓ Vendors installed (including dev).</info>');
})->desc('Install all vendors including dev dependencies');

task('deploy:cache:clear:dev', function () {
    run('{{bin/console}} cache:clear --env=dev');
    run('{{bin/console}} cache:warmup --env=dev');
    writeln('<info>✓ Dev cache cleared and warmed up.</info>');
})->desc('Clear & warm up Symfony dev cache');

after('deploy:failed', 'deploy:unlock');

// ── Override recipe: always clear + warmup (recipe skips if composer ran scripts) ─
task('deploy:cache:clear', function () {
    run('{{bin/console}} cache:clear --env=prod --no-debug');
    run('{{bin/console}} cache:warmup --env=prod --no-debug');
    writeln('<info>✓ Cache cleared and warmed up.</info>');
})->desc('Clear & warm up Symfony prod cache');

// ── Core: pre-deploy test gate ────────────────────────────────────────────────
task('deploy:run_tests', function () {
    writeln('<info>Running test suite locally…</info>');
    runLocally('composer test', ['timeout' => 600, 'tty' => true]);
    writeln('<info>✓ All tests passed.</info>');
})->desc('Run PHPUnit locally and abort deploy on failure');

// ── Utility: remote application ───────────────────────────────────────────────
task('app:cache:clear', function () {
    run('{{bin/php}} {{release_or_current_path}}/bin/console cache:clear  --env=prod --no-debug');
    run('{{bin/php}} {{release_or_current_path}}/bin/console cache:warmup --env=prod --no-debug');
    writeln('<info>✓ Cache cleared and warmed up.</info>');
})->desc('Clear & warm up the Symfony cache on the remote host');

task('app:bank:sync', function () {
    run("sh -lc 'mkdir -p {{deploy_path}}/shared/var/log; touch {{deploy_path}}/shared/var/log/bank-sync.log; printf \"[%s] START app:bank:sync\\n\" \"$(date -u +%FT%TZ)\" >> {{deploy_path}}/shared/var/log/bank-sync.log; {{bin/php}} {{release_or_current_path}}/bin/console app:bank:sync --env=prod --no-debug >> {{deploy_path}}/shared/var/log/bank-sync.log 2>&1; status=$?; printf \"[%s] END app:bank:sync status=%s\\n\" \"$(date -u +%FT%TZ)\" \"\$status\" >> {{deploy_path}}/shared/var/log/bank-sync.log; echo \"=== bank-sync.log (latest) ===\" >&2; tail -n 80 {{deploy_path}}/shared/var/log/bank-sync.log >&2; test \"\$status\" -eq 0'");
    writeln('<info>✓ Remote bank polling sync completed.</info>');
})->desc('Run polling bank sync on the remote host');

task('app:bank:webhooks:refresh', function () {
    run("sh -lc 'mkdir -p {{deploy_path}}/shared/var/log; touch {{deploy_path}}/shared/var/log/bank-webhooks-refresh.log; printf \"[%s] START app:bank:webhooks:refresh\\n\" \"$(date -u +%FT%TZ)\" >> {{deploy_path}}/shared/var/log/bank-webhooks-refresh.log; {{bin/php}} {{release_or_current_path}}/bin/console app:bank:webhooks:refresh --env=prod --no-debug >> {{deploy_path}}/shared/var/log/bank-webhooks-refresh.log 2>&1; status=$?; printf \"[%s] END app:bank:webhooks:refresh status=%s\\n\" \"$(date -u +%FT%TZ)\" \"\$status\" >> {{deploy_path}}/shared/var/log/bank-webhooks-refresh.log; echo \"=== bank-webhooks-refresh.log (latest) ===\" >&2; tail -n 80 {{deploy_path}}/shared/var/log/bank-webhooks-refresh.log >&2; test \"\$status\" -eq 0'");
    writeln('<info>✓ Remote webhook refresh completed.</info>');
})->desc('Refresh bank webhooks on the remote host');

task('app:bank:maintenance', [
    'app:bank:webhooks:refresh',
    'app:bank:sync',
])->desc('Run webhook refresh and polling sync on the remote host');

task('app:bank:sync:logs', function () {
    $output = run("sh -lc 'mkdir -p {{deploy_path}}/shared/var/log; touch {{deploy_path}}/shared/var/log/bank-sync.log; echo \"=== bank-sync.log ===\"; if [ -s {{deploy_path}}/shared/var/log/bank-sync.log ]; then tail -n 150 {{deploy_path}}/shared/var/log/bank-sync.log; else echo \"(empty)\"; fi'");
    writeln($output);
})->desc('Tail polling sync log on the remote host');

task('app:bank:webhooks:logs', function () {
    $output = run("sh -lc 'mkdir -p {{deploy_path}}/shared/var/log; touch {{deploy_path}}/shared/var/log/bank-webhooks-refresh.log; echo \"=== bank-webhooks-refresh.log ===\"; if [ -s {{deploy_path}}/shared/var/log/bank-webhooks-refresh.log ]; then tail -n 150 {{deploy_path}}/shared/var/log/bank-webhooks-refresh.log; else echo \"(empty)\"; fi'");
    writeln($output);
})->desc('Tail webhook refresh log on the remote host');

task('app:bank:logs', function () {
    $output = run("sh -lc 'mkdir -p {{deploy_path}}/shared/var/log; touch {{deploy_path}}/shared/var/log/bank-sync.log {{deploy_path}}/shared/var/log/bank-webhooks-refresh.log; echo \"=== bank-sync.log ===\"; if [ -s {{deploy_path}}/shared/var/log/bank-sync.log ]; then tail -n 150 {{deploy_path}}/shared/var/log/bank-sync.log; else echo \"(empty)\"; fi; echo; echo \"=== bank-webhooks-refresh.log ===\"; if [ -s {{deploy_path}}/shared/var/log/bank-webhooks-refresh.log ]; then tail -n 150 {{deploy_path}}/shared/var/log/bank-webhooks-refresh.log; else echo \"(empty)\"; fi'");
    writeln($output);
})->desc('Tail bank sync and webhook refresh logs on the remote host');

task('app:logs', function () {
    run('tail -n 150 {{deploy_path}}/shared/var/log/prod.log');
})->desc('Tail last 150 lines of the remote production log');

task('app:shell', function () {
    $host = currentHost();
    $conn = "{$host->getRemoteUser()}@{$host->getHostname()}";
    writeln("<info>Opening shell → $conn</info>");
    runLocally("ssh $conn", ['tty' => true, 'timeout' => 0]);
})->desc('Open an interactive SSH session on the remote host');

task('app:php:restart', function () {
    // Try common service names; silently ignore whichever does not exist.
    run('sudo systemctl reload php8.2-fpm 2>/dev/null || sudo systemctl reload php-fpm 2>/dev/null || true');
    writeln('<info>✓ PHP-FPM reloaded.</info>');
})->desc('Reload PHP-FPM on remote (needed after OPcache / php.ini changes)');

task('app:php_ini:upload', function () {
    upload(__DIR__ . '/php.ini', '{{deploy_path}}/shared/php.ini');
    invoke('app:php:restart');
    writeln('<info>✓ php.ini uploaded and PHP-FPM reloaded.</info>');
})->desc('Push local php.ini to the remote shared directory and reload PHP-FPM');

// ── Utility: production .env ───────────────────────────────────────────────────
task('env:pull', function () {
    $local = __DIR__ . '/.env.production';
    download('{{deploy_path}}/shared/.env', $local);
    writeln("<info>✓ Production .env pulled → $local</info>");
})->desc('Download production shared/.env to .env.production locally');

task('env:push', function () {
    $local = __DIR__ . '/.env.production';
    if (!file_exists($local)) {
        throw new \RuntimeException("Local .env.production not found. Run env:pull first or create it manually.");
    }
    if (!askConfirmation('⚠️  Overwrite production shared/.env with local .env.production?', false)) {
        writeln('<comment>Aborted.</comment>');
        return;
    }
    upload($local, '{{deploy_path}}/shared/.env');
    writeln('<info>✓ .env.production pushed to production shared/.env</info>');
})->desc('Upload local .env.production to production shared/.env');

// ── Utility: database ─────────────────────────────────────────────────────────
task('db:backup', function () {
    $db   = get('db_remote');
    $ts   = date('Y-m-d_H-i-s');
    $file = "{{deploy_path}}/shared/backups/dump-{$ts}.sql";
    run("mysqldump -h {$db['host']} -u {$db['username']} -p{$db['password']} {$db['name']} > $file");
    writeln("<info>✓ Remote DB backed up → $file</info>");
})->desc('Create a timestamped SQL dump of the remote database');

task('db:backup:download', function () {
    download('{{deploy_path}}/shared/backups/', './backups/');
    writeln('<info>✓ Backups downloaded to ./backups/</info>');
})->desc('Download all remote SQL backups to ./backups/ locally');

task('db:pull', function () {
    $remote = get('db_remote');
    $local  = get('db_local');
    $tmp    = sys_get_temp_dir() . '/dep-dump-' . time() . '.sql';

    writeln('<info>Dumping remote database…</info>');
    runLocally("mysqldump -h {$remote['host']} -u {$remote['username']} -p{$remote['password']} {$remote['name']} > $tmp");

    writeln('<info>Importing into local database…</info>');
    $pass = $local['password'] !== '' ? "-p{$local['password']}" : '';
    runLocally("mysql -u {$local['username']} $pass {$local['name']} < $tmp && rm $tmp");

    writeln('<info>✓ Remote DB copied to local.</info>');
})->desc('Copy the remote database to your local environment');

task('db:push', function () {
    if (!askConfirmation('⚠️  Overwrite PRODUCTION database with local? This cannot be undone.', false)) {
        writeln('<comment>Aborted.</comment>');
        return;
    }

    invoke('db:backup'); // always back up before overwriting

    $local  = get('db_local');
    $remote = get('db_remote');
    $tmp    = sys_get_temp_dir() . '/dep-dump-' . time() . '.sql';

    writeln('<info>Dumping local database…</info>');
    $pass = $local['password'] !== '' ? "-p{$local['password']}" : '';
    runLocally("mysqldump -u {$local['username']} $pass {$local['name']} > $tmp");

    writeln('<info>Uploading to remote database…</info>');
    runLocally("mysql -h {$remote['host']} -u {$remote['username']} -p{$remote['password']} {$remote['name']} < $tmp && rm $tmp");

    writeln('<info>✓ Local DB pushed to remote.</info>');
})->desc('Push local database to remote (automatically backs up remote first)');
