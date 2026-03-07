<?php

/**
 * Deployer 7.x — Budget API
 *
 * Config lives in .env.local (gitignored). Add DEPLOY_* and DB_* vars — see .env for the full list.
 *
 * Usage:
 *   vendor/bin/dep deploy production          — full deploy (tests → build → symlink)
 *   vendor/bin/dep app:logs production        — tail production log (last 100 lines)
 *   vendor/bin/dep app:shell production       — interactive SSH session
 *   vendor/bin/dep app:cache:clear production — clear + warm Symfony cache remotely
 *   vendor/bin/dep app:php:restart production — reload php-fpm (after php.ini changes)
 *   vendor/bin/dep app:php_ini:upload production — push local php.ini to server
 *   vendor/bin/dep db:pull production         — copy remote DB → local
 *   vendor/bin/dep db:push production         — copy local DB → remote (backs up first)
 *   vendor/bin/dep db:backup production       — create timestamped remote SQL dump
 *   vendor/bin/dep db:backup:download production — download all remote backups locally
 *   vendor/bin/dep list                       — list every available task
 */

namespace Deployer;

require 'recipe/symfony.php';

// ── Load .env → .env.local (same precedence order as Symfony) ─────────────────
function loadEnvFile(string $path): void
{
    if (!file_exists($path)) {
        return;
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
        if (getenv($key) === false) { // real env vars win
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
}

loadEnvFile(__DIR__ . '/.env');
loadEnvFile(__DIR__ . '/.env.local');

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
