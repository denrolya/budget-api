<?php

namespace Deployer;

require 'recipe/symfony4.php';

// Project name
set('application', 'api');
set('http_user', 'www-data');
set('host_address', 'INSERT_HOSTNAME');
set('repo', 'INSERT_GITHUB_REPO_URL');
set('remote_url', 'http://{{ host_address }}:{{ port }}');

set('db', [
    'local' => [
        'name' => 'INSERT_LOCAL_DB_NAME',
        'username' => 'INSERT_USERNAME_LOCAL',
        'password' => 'INSERT_PASSWORD_LOCAL'
    ],
    'production' => [
        'host' => get('host_address'),
        'name' => 'INSERT_DB_NAME_REMOTE',
        'username' => 'INSERT_USERNAME_REMOTE',
        'password' => 'INSERT_PASSWORD_REMOTE'
    ]
]);

// [Optional] Allocate tty for git clone. Default value is false.
set('git_tty', false);

// Hosts
host('production')
    ->hostname(get('host_address'))
    ->stage('production')
    ->user('INSERT_USERNAME')
    ->configFile('~/.ssh/config')
    ->identityFile('~/.ssh/id_rsa')
    ->multiplexing(true)
    ->set('repository', '{{repo}}')
    ->set('branch', 'master')
    ->set('clear_paths', [])
    ->set('symfony_env', 'prod')
    ->set('deploy_path', '/var/www/{{application}}')
    ->set('writable_mode', 'acl')
    ->set('composer_options', '{{composer_action}}')
    ->set('shared_dirs', ['var/log', 'var/sessions', 'config/jwt'])
    ->set('shared_files', ['.env', 'php.ini'])
    ->set('writable_dirs', ['var']);

after('deploy', 'success');
after('deploy:failed', 'deploy:unlock'); // [Optional] if deploy fails automatically unlock.

// Tasks
task('deploy', [
    'deploy:run_tests',
    'deploy:info',
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:shared',
    'deploy:writable',
    'deploy:vendors',
    'deploy:cache:clear',
    'deploy:cache:warmup',
    'deploy:symlink',
    'deploy:unlock',
    'cleanup',
])->desc('Deploy Application');

task('deploy:run_tests', function () {
    writeln('<info>Running local tests...</info>');
    try {
        runLocally('composer test', [
            'timeout' => 600,  // Adjust the timeout as needed
            'tty' => true      // Allows real-time output from the test runner
        ]);
        writeln('<info>All tests passed.</info>');
    } catch (\Exception $e) {
        writeln('<error>Tests failed. Deployment aborted.</error>');
        throw $e; // Re-throw the exception to halt the deployment
    }
})->desc('Run local tests before deployment');

task('database:copy:to_local', function () {
    $dumpFilename = 'dump-' . time() . '.sql';
    $stage = input()->getArgument('stage');
    $remoteDb = get('db')[$stage];
    $localDb = get('db')['local'];

    writeln('<info>Dumping database from remote host.</info>');
    runLocally("mysqldump -h {$remoteDb['host']} -u {$remoteDb['username']} -p{$remoteDb['password']} {$remoteDb['name']} > ./$dumpFilename");

    writeln('<info>Dump success. Exporting dump into local database.</info>');
    runLocally("mysql -u {$localDb['username']} -p{$localDb['password']} {$localDb['name']} < ./$dumpFilename");
    writeln('<info>Successfully exported! Removing leftover files...</info>');
    runLocally("rm ./$dumpFilename");

    writeln('<info>Database migration finished...</info>');
})->desc('Copy database from remote stage to local environment');

task('database:copy:to_remote', function () {
    $stage = input()->getArgument('stage');
    if (!askConfirmation('You sure you want to upload your local DB to [' . $stage . ']?')) {
        writeln('<info>Aborted by user!</info>');
        die;
    }

    backupRemoteDatabaseLocally();

    $dumpFilename = 'dump-' . time() . '.sql';
    $remoteDb = get('db')[$stage];
    $localDb = get('db')['local'];

    writeln('<info>Dumping database locally.</info>');
    runLocally("mysqldump -u {$localDb['username']} -p{$localDb['password']} {$localDb['name']} > ./$dumpFilename");

    writeln('<info>Dump complete. Exporting dump into remote database.</info>');
    runLocally("mysql -h {$remoteDb['host']} -u {$remoteDb['username']} -p{$remoteDb['password']} {$remoteDb['name']} < ./$dumpFilename");

    writeln('<info>Successfully exported! Removing leftover files...</info>');
    runLocally("rm ./$dumpFilename");

    writeln('<info>Done</info>');
})->desc('Back up remote database locally & copy local database to remote stage');

task('database:backup:to_local', function() {
    backupRemoteDatabaseLocally();
})->desc('Create local backup of remote database');

task('database:backup:download', function() {
    download('{{deploy_path}}/backups', './');
    writeln('<info>Backups successfully downloaded</info>');
})->desc('Download database backups from remote');

task('success', function () {
    // TODO: Add global OS notification
    writeln('<info>Successfully deployed! Check it out under: {{ remote_url }}<info>');
});

function backupRemoteDatabaseLocally() {
    $filename = './backups/dump-' . time() . '.sql';
    $stage = input()->getArgument('stage');
    $remoteDb = get('db')[$stage];

    writeln('<info>Backing up database from remote host.</info>');
    runLocally("mysqldump -h {$remoteDb['host']} -u {$remoteDb['username']} -p{$remoteDb['password']} {$remoteDb['name']} > $filename");

    writeln("<info>Database backup was successfully created in $filename.</info>");
}
