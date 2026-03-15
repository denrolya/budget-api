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
 *   vendor/bin/dep app:info production        — show current release/env/debug info
 *   vendor/bin/dep app:shell production       — interactive SSH session
 *   vendor/bin/dep app:cache:clear production — clear + warm Symfony cache remotely
 * 
 *   vendor/bin/dep app:bank:sync production — run polling sync on remote
 *   vendor/bin/dep app:bank:webhooks:refresh production — refresh webhooks on remote
 *   vendor/bin/dep app:bank:maintenance production — run webhook refresh + polling sync on remote
 *   vendor/bin/dep app:bank:sync:logs production — tail polling sync log on remote
 *   vendor/bin/dep app:bank:webhooks:logs production — tail webhook refresh log on remote
 *   vendor/bin/dep app:bank:logs production — dump last 200 lines of bank log on remote
 *   vendor/bin/dep app:bank:logs:follow production — live-follow bank log (Ctrl+C to stop)
 *   vendor/bin/dep app:logs:follow production — live-follow prod.log (Ctrl+C to stop)
 * 
 *   vendor/bin/dep app:php:restart production — reload php-fpm (after php.ini changes)
 *   vendor/bin/dep app:php_ini:upload production — push local php.ini to server
 *
 *   vendor/bin/dep server:status production        — RAM, CPU load, services, FPM workers
 *   vendor/bin/dep server:nginx:test production    — test nginx config validity
 *   vendor/bin/dep server:nginx:reload production  — graceful reload nginx
 *   vendor/bin/dep server:opcache:reset production — reset OPcache via FPM reload
 *   vendor/bin/dep server:mysql:vars production    — show key MySQL runtime variables
 *
 *   Xdebug is managed automatically:
 *     deploy production     → disables Xdebug after symlink (prod performance restored)
 *     deploy:dev production → enables  Xdebug after symlink (profiler + breakpoints)
 *
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
    'deploy:run_tests',       // run PHPUnit locally — abort on failure
    'deploy:ensure_env:prod', // prompt if APP_ENV != prod on server
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
    'deploy:ensure_env:dev',  // prompt if APP_ENV != dev on server
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

// ── APP_ENV guards ────────────────────────────────────────────────────────────
// Reads APP_ENV from shared/.env before deploy and prompts to fix if mismatched.

function ensureAppEnv(string $expected): void
{
    $envFile = get('deploy_path') . '/shared/.env';
    $current = trim(run("grep -E '^APP_ENV=' $envFile 2>/dev/null | cut -d= -f2 | tr -d '\"\\047' || echo ''"));

    if ($current === $expected) {
        writeln("<info>✓ APP_ENV=$expected (correct)</info>");
        return;
    }

    $label = $current !== '' ? $current : '(not set)';
    writeln("<comment>⚠  APP_ENV is '$label' on server — expected '$expected'</comment>");

    if (askConfirmation("  Change APP_ENV to '$expected' in shared/.env?", true)) {
        if ($current !== '') {
            run("sed -i 's|^APP_ENV=.*|APP_ENV=$expected|' $envFile");
        } else {
            run("echo 'APP_ENV=$expected' >> $envFile");
        }
        writeln("<info>✓ APP_ENV set to $expected.</info>");
    } else {
        writeln('<comment>  Proceeding without changing APP_ENV.</comment>');
    }
}

task('deploy:ensure_env:prod', fn() => ensureAppEnv('prod'))
    ->desc('[internal] Ensure APP_ENV=prod before production deploy');

task('deploy:ensure_env:dev', fn() => ensureAppEnv('dev'))
    ->desc('[internal] Ensure APP_ENV=dev before dev deploy');

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

// Xdebug: automatically managed as part of deploy pipelines.
// prod deploy → disable (ensure clean prod state after every release)
// dev  deploy → enable  (profiler + breakpoints ready immediately)
after('deploy',     'server:xdebug:off');
after('deploy:dev', 'server:xdebug:on');

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
    run("sh -lc 'mkdir -p {{deploy_path}}/shared/var/log; touch {{deploy_path}}/shared/var/log/bank.log; printf \"[%s] START app:bank:sync\\n\" \"$(date -u +%FT%TZ)\" >> {{deploy_path}}/shared/var/log/bank.log; {{bin/php}} {{release_or_current_path}}/bin/console app:bank:sync --env=prod --no-debug >> {{deploy_path}}/shared/var/log/bank.log 2>&1; status=$?; printf \"[%s] END app:bank:sync status=%s\\n\" \"$(date -u +%FT%TZ)\" \"\$status\" >> {{deploy_path}}/shared/var/log/bank.log; echo \"=== bank.log (latest bank entries) ===\" >&2; tail -n 120 {{deploy_path}}/shared/var/log/bank.log >&2; test \"\$status\" -eq 0'");
    writeln('<info>✓ Remote bank polling sync completed.</info>');
})->desc('Run polling bank sync on the remote host');

task('app:bank:webhooks:refresh', function () {
    run("sh -lc 'mkdir -p {{deploy_path}}/shared/var/log; touch {{deploy_path}}/shared/var/log/bank.log; printf \"[%s] START app:bank:webhooks:refresh\\n\" \"$(date -u +%FT%TZ)\" >> {{deploy_path}}/shared/var/log/bank.log; {{bin/php}} {{release_or_current_path}}/bin/console app:bank:webhooks:refresh --env=prod --no-debug >> {{deploy_path}}/shared/var/log/bank.log 2>&1; status=$?; printf \"[%s] END app:bank:webhooks:refresh status=%s\\n\" \"$(date -u +%FT%TZ)\" \"\$status\" >> {{deploy_path}}/shared/var/log/bank.log; echo \"=== bank.log (latest bank entries) ===\" >&2; tail -n 120 {{deploy_path}}/shared/var/log/bank.log >&2; test \"\$status\" -eq 0'");
    writeln('<info>✓ Remote webhook refresh completed.</info>');
})->desc('Refresh bank webhooks on the remote host');

task('app:bank:maintenance', [
    'app:bank:webhooks:refresh',
    'app:bank:sync',
])->desc('Run webhook refresh and polling sync on the remote host');

task('app:bank:sync:logs', function () {
    $output = run("sh -lc 'mkdir -p {{deploy_path}}/shared/var/log; touch {{deploy_path}}/shared/var/log/bank.log; echo \"=== bank.log (sync-related) ===\"; if [ -s {{deploy_path}}/shared/var/log/bank.log ]; then grep -E \"app:bank:sync|\\[BankSync\\]\" {{deploy_path}}/shared/var/log/bank.log | tail -n 150; else echo \"(empty)\"; fi'");
    writeln($output);
})->desc('Show recent sync-related entries from the unified bank log');

task('app:bank:webhooks:logs', function () {
    $output = run("sh -lc 'mkdir -p {{deploy_path}}/shared/var/log; touch {{deploy_path}}/shared/var/log/bank.log; echo \"=== bank.log (webhook-related) ===\"; if [ -s {{deploy_path}}/shared/var/log/bank.log ]; then grep -E \"app:bank:webhooks:refresh|\\[BankWebhook\\]\" {{deploy_path}}/shared/var/log/bank.log | tail -n 150; else echo \"(empty)\"; fi'");
    writeln($output);
})->desc('Show recent webhook-related entries from the unified bank log');

task('app:bank:logs', function () {
    $output = run("sh -lc 'mkdir -p {{deploy_path}}/shared/var/log; touch {{deploy_path}}/shared/var/log/bank.log; echo \"=== bank.log ===\"; if [ -s {{deploy_path}}/shared/var/log/bank.log ]; then tail -n 200 {{deploy_path}}/shared/var/log/bank.log; else echo \"(empty)\"; fi'");
    writeln($output);
})->desc('Dump last 200 lines of the bank log on the remote host');

task('app:bank:logs:follow', function () {
    $host    = currentHost();
    $conn    = "{$host->getRemoteUser()}@{$host->getHostname()}";
    $logPath = get('deploy_path') . '/shared/var/log/bank.log';
    writeln("<info>Following $logPath — Ctrl+C to stop</info>");
    passthru("ssh -tt $conn 'touch $logPath && tail -f $logPath'");
})->desc('Live-follow the bank log (Ctrl+C to stop)');

task('app:logs', function () {
    $output = run('tail -n 150 {{deploy_path}}/shared/var/log/prod.log');
    writeln($output);
})->desc('Dump last 150 lines of the remote production log');

task('app:logs:follow', function () {
    $host    = currentHost();
    $conn    = "{$host->getRemoteUser()}@{$host->getHostname()}";
    $logPath = get('deploy_path') . '/shared/var/log/prod.log';
    writeln("<info>Following $logPath — Ctrl+C to stop</info>");
    passthru("ssh -tt $conn 'touch $logPath && tail -f $logPath'");
})->desc('Live-follow the production log (Ctrl+C to stop)');

task('app:info', function () {
    $output = run("sh -lc '
        echo \"=== app info ===\";
        echo \"deploy_path: {{deploy_path}}\";
        echo \"release_or_current_path: {{release_or_current_path}}\";
        echo \"current_symlink: $(readlink {{deploy_path}}/current 2>/dev/null || echo missing)\";
        echo \"php: $({{bin/php}} -v | head -n 1)\";
        echo \"app_env: $(grep -E \"^APP_ENV=\" {{deploy_path}}/shared/.env 2>/dev/null | tail -n1 | cut -d= -f2- || echo missing)\";
        echo \"webhook_base_url: $(grep -E \"^WEBHOOK_BASE_URL=\" {{deploy_path}}/shared/.env 2>/dev/null | tail -n1 | cut -d= -f2- || echo missing)\";
        echo \"wise_base_url: $(grep -E \"^WISE_BASE_URL=\" {{deploy_path}}/shared/.env 2>/dev/null | tail -n1 | cut -d= -f2- || echo missing)\";
        echo \"repository_head: $(cd {{release_or_current_path}} 2>/dev/null && git rev-parse --short HEAD 2>/dev/null || echo unknown)\";
        echo;
        echo \"=== log files ===\";
        ls -lah {{deploy_path}}/shared/var/log/prod.log {{deploy_path}}/shared/var/log/bank.log 2>/dev/null || true;
        echo;
        echo \"=== console about ===\";
        {{bin/php}} {{release_or_current_path}}/bin/console about --env=prod --no-debug 2>&1 | sed -n \"1,40p\";
    '");
    writeln($output);
})->desc('Show production release, env, log, and console diagnostics');

task('app:shell', function () {
    $host = currentHost();
    $conn = "{$host->getRemoteUser()}@{$host->getHostname()}";
    $remotePath = get('deploy_path') . '/current';
    writeln("<info>Opening remote shell in $remotePath → $conn</info>");
    runLocally("ssh -tt $conn 'cd $remotePath && exec \${SHELL:-bash} -l'", ['tty' => true, 'timeout' => 0]);
})->desc('Open an interactive SSH session on the remote host in the current release directory');

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
// All db:* tasks run mysqldump/mysql on the remote host via SSH — port 3306 does
// NOT need to be open externally. The Deployer SSH connection is the only channel.

task('db:backup', function () {
    $db   = get('db_remote');
    $ts   = date('Y-m-d_H-i-s');
    $file = "{{deploy_path}}/shared/backups/dump-{$ts}.sql";
    run("mysqldump -h 127.0.0.1 -u {$db['username']} -p{$db['password']} {$db['name']} > $file");
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

    // Dump on remote (goes through SSH — port 3306 stays closed externally)
    writeln('<info>Dumping remote database via SSH…</info>');
    $dump = run("mysqldump -h 127.0.0.1 -u {$remote['username']} -p{$remote['password']} {$remote['name']}");
    file_put_contents($tmp, $dump);

    writeln('<info>Importing into local database…</info>');
    $pass = $local['password'] !== '' ? "-p{$local['password']}" : '';
    runLocally("mysql -u {$local['username']} $pass {$local['name']} < $tmp && rm $tmp");

    writeln('<info>✓ Remote DB copied to local.</info>');
})->desc('Copy the remote database to your local environment (via SSH, no open 3306 needed)');

task('db:push', function () {
    if (!askConfirmation('⚠️  Overwrite PRODUCTION database with local? This cannot be undone.', false)) {
        writeln('<comment>Aborted.</comment>');
        return;
    }

    invoke('db:backup'); // always back up before overwriting

    $local  = get('db_local');
    $remote = get('db_remote');
    $tmp    = sys_get_temp_dir() . '/dep-dump-' . time() . '.sql';
    $remoteTmp = '/tmp/dep-push-' . time() . '.sql';

    writeln('<info>Dumping local database…</info>');
    $pass = $local['password'] !== '' ? "-p{$local['password']}" : '';
    runLocally("mysqldump --set-gtid-purged=OFF -u {$local['username']} $pass {$local['name']} > $tmp");

    // Upload dump via SCP, then import on remote — port 3306 stays closed externally
    writeln('<info>Uploading dump to server…</info>');
    upload($tmp, $remoteTmp);
    runLocally("rm $tmp");

    writeln('<info>Importing on remote…</info>');
    run("mysql -h 127.0.0.1 -u {$remote['username']} -p{$remote['password']} {$remote['name']} < $remoteTmp && rm $remoteTmp");

    writeln('<info>✓ Local DB pushed to remote.</info>');
})->desc('Push local database to remote via SSH (backs up remote first, no open 3306 needed)');

// ── Utility: server management ────────────────────────────────────────────────

task('server:status', function () {
    $output = run("sh -lc '
        echo \"=== Memory ===\";
        free -h;
        echo \"\";
        echo \"=== Load ===\";
        uptime;
        echo \"\";
        echo \"=== Disk ===\";
        df -h /;
        echo \"\";
        echo \"=== Services ===\";
        systemctl is-active --quiet nginx     && echo \"nginx:    active\" || echo \"nginx:    INACTIVE\";
        systemctl is-active --quiet php8.2-fpm && echo \"php-fpm:  active\" || echo \"php-fpm:  INACTIVE\";
        systemctl is-active --quiet mysql     && echo \"mysql:    active\" || echo \"mysql:    INACTIVE\";
        echo \"\";
        echo \"=== PHP-FPM workers ===\";
        ps aux --no-headers | grep \"php-fpm: pool\" | grep -v grep | awk \"{print \\\$6/1024 \\\" MB \\\"\\\$11\\\" \\\"\\\$12}\" | sort -rn | head -12;
        echo \"\";
        echo \"=== Top memory consumers ===\";
        ps aux --sort=-%mem --no-headers | head -8 | awk \"{printf \\\"%-10s %5s%% %s\\\\n\\\", \\\$1, \\\$4, \\\$11}\";
    '");
    writeln($output);
})->desc('Show server RAM, load, disk, service status, and FPM workers');

// Internal tasks — called automatically by deploy hooks (not exposed in composer.json)
task('server:xdebug:on', function () {
    run('sudo phpenmod -s fpm xdebug 2>/dev/null; sudo systemctl reload php8.2-fpm');
    writeln('<info>✓ Xdebug ENABLED in PHP-FPM.</info>');
})->desc('[internal] Enable Xdebug — triggered automatically after deploy:dev');

task('server:xdebug:off', function () {
    run('sudo phpdismod -s fpm xdebug 2>/dev/null; sudo systemctl reload php8.2-fpm');
    writeln('<info>✓ Xdebug DISABLED in PHP-FPM (production mode).</info>');
})->desc('[internal] Disable Xdebug — triggered automatically after deploy');

task('server:nginx:test', function () {
    $out = run('sudo nginx -t 2>&1');
    writeln($out);
})->desc('Test nginx configuration validity');

task('server:nginx:reload', function () {
    run('sudo nginx -t 2>&1 && sudo systemctl reload nginx');
    writeln('<info>✓ Nginx reloaded.</info>');
})->desc('Test and gracefully reload nginx');

task('server:opcache:reset', function () {
    // Создаём временный файл сброса OPcache через PHP-CLI (FPM шарит shared memory)
    // Для полного сброса нужен reload FPM
    run('sudo systemctl reload php8.2-fpm');
    writeln('<info>✓ PHP-FPM reloaded — OPcache reset.</info>');
})->desc('Reset OPcache by reloading PHP-FPM (use after manual file changes on server)');

task('server:mysql:vars', function () {
    $db = get('db_remote');
    $output = run("mysql -h 127.0.0.1 -u {$db['username']} -p{$db['password']} -e \"
        SHOW VARIABLES WHERE Variable_name IN (
            'innodb_buffer_pool_size','innodb_buffer_pool_instances',
            'max_connections','thread_cache_size',
            'tmp_table_size','max_heap_table_size',
            'slow_query_log','long_query_time',
            'bind_address','mysqlx_bind_address'
        );
    \" 2>/dev/null");
    writeln($output);
})->desc('Show key MySQL runtime variables');
