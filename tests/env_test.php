<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Env;
use Or81\Eloquent\Jalali;
use Or81\Eloquent\NDB;

/**
 * Reading connection settings from a .env file, and overriding them by hand.
 */

$directory = sys_get_temp_dir() . '/ndb_env_' . getmypid();
@mkdir($directory, 0777, true);

$sqlite = $directory . '/from_env.sqlite';

function writeEnv(string $directory, string $contents): string
{
    file_put_contents($directory . '/.env', $contents);

    return $directory;
}

section('the built-in parser');

writeEnv($directory, <<<ENV
# a comment line
DB_CONNECTION=sqlite
DB_DATABASE={$sqlite}
DB_USERNAME=root
DB_PASSWORD="a secret with spaces"
DB_PORT=3307
QUOTED_SINGLE='kept as is'
INLINE_COMMENT=value # trailing note
export EXPORTED=yes
EMPTY_VALUE=
BOOL_TRUE=true
BOOL_FALSE=false
NULL_VALUE=null
NOT-A-KEY=skipped
ENV);

Env::use($directory);

check('a plain value', Env::get('DB_CONNECTION'), 'sqlite');
check('a double-quoted value keeps its spaces', Env::get('DB_PASSWORD'), 'a secret with spaces');
check('a single-quoted value', Env::get('QUOTED_SINGLE'), 'kept as is');
check('an inline comment is stripped', Env::get('INLINE_COMMENT'), 'value');
check('the export prefix is ignored', Env::get('EXPORTED'), 'yes');
check('an empty value stays empty', Env::get('EMPTY_VALUE'), '');
check('true becomes a bool', Env::get('BOOL_TRUE'), true);
check('false becomes a bool', Env::get('BOOL_FALSE'), false);
check('null becomes null', Env::get('NULL_VALUE', 'fallback'), null);
check('an invalid key is skipped', Env::get('NOT-A-KEY', 'skipped-key'), 'skipped-key');
check('a missing key returns the default', Env::get('NOPE', 'default'), 'default');
check('has() on a present key', Env::has('DB_USERNAME'), true);
check('has() on a missing key', Env::has('DEFINITELY_MISSING'), false);
check('loadedFrom points at the file',
    str_replace('\\', '/', Env::loadedFrom()), str_replace('\\', '/', $directory) . '/.env');
check('all() exposes what was parsed', Env::all()['DB_USERNAME'], 'root');

throws('use() rejects a path that does not exist',
    fn () => Env::use($directory . '/missing/.env'),
    RuntimeException::class,
    'No .env file');

section('the real environment wins over the file');

$_ENV['DB_USERNAME'] = 'from_the_process';
check('a value in $_ENV takes priority', Env::get('DB_USERNAME'), 'from_the_process');
unset($_ENV['DB_USERNAME']);
check('and the file value comes back once it is gone', Env::get('DB_USERNAME'), 'root');

Env::set('DB_USERNAME', 'set_at_runtime');
check('set() overrides', Env::get('DB_USERNAME'), 'set_at_runtime');
Env::set('DB_USERNAME', null);
check('set(null) clears the override', Env::get('DB_USERNAME'), 'root');

section('the connection config env produces');

NDB::purge();
NDB::useEnv($directory);

$config = NDB::envConfig();

check('driver', $config['driver'], 'sqlite');
check('database', $config['database'], $sqlite);
check('username', $config['username'], 'root');
check('password', $config['password'], 'a secret with spaces');
check('port is cast to an int', $config['port'], 3307);
check('keys the file does not mention are left out', isset($config['collation']), false);

section('connecting with no configure() call at all');

NDB::table('things')->getConnection()->unprepared('create table things (id integer primary key, label text)');
NDB::table('things')->insert(['label' => 'straight from .env']);

check('the query ran against the file named in .env',
    NDB::table('things')->value('label'), 'straight from .env');
check('and that file was really created', file_exists($sqlite), true);
check('the driver came from DB_CONNECTION', NDB::connection()->getDriver(), 'sqlite');

section('configure() overrides, and only what it names');

$other = $directory . '/override.sqlite';

NDB::purge();
NDB::configure(['database' => $other]);

check('the named key is overridden', NDB::connection()->getConfig('database'), $other);
check('the rest still comes from .env', NDB::connection()->getConfig('username'), 'root');
check('a full override also works',
    (function () {
        NDB::purge();
        NDB::configure(['driver' => 'sqlite', 'database' => ':memory:', 'username' => 'someone']);
        return [NDB::connection()->getConfig('database'), NDB::connection()->getConfig('username')];
    })(),
    [':memory:', 'someone']);

section('named connections need their own prefix');

writeEnv($directory, <<<ENV
DB_CONNECTION=sqlite
DB_DATABASE={$sqlite}
REPORTING_DB_CONNECTION=sqlite
REPORTING_DB_DATABASE=:memory:
ENV);

NDB::purge();
Env::reset();
NDB::useEnv($directory);

check('a prefixed connection is read', NDB::envConfig('reporting')['database'], ':memory:');
check('it really connects', NDB::connection('reporting')->getDriver(), 'sqlite');
check('an unprefixed name says nothing', NDB::envConfig('nowhere'), null);

throws('so a typo in the name still fails loudly',
    fn () => NDB::connection('nowhere'),
    InvalidArgumentException::class,
    'is not configured');

section('jalali storage can come from .env too');

writeEnv($directory, <<<ENV
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
DB_DATE_STORAGE=jalali
DB_DATE_FORMAT=Y/m/d
ENV);

NDB::purge();
Env::reset();
NDB::useEnv($directory);

check('the storage mode is picked up', NDB::envConfig()['date_storage'], Jalali::JALALI);

NDB::unprepared('create table invoices (id integer primary key, issued_at text)');
NDB::table('invoices')->insert([['issued_at' => '1403/05/26'], ['issued_at' => '1403/06/02']]);

check('and the whole connection defaults to jalali columns',
    NDB::table('invoices')->whereJalaliDate('issued_at', '1403/05/26')->pluck('issued_at'),
    ['1403/05/26']);

section('no .env at all');

unlink($directory . '/.env');
Env::reset();
NDB::forget();

check('envConfig gives up quietly', NDB::envConfig(), null);

throws('and the error explains both ways out',
    fn () => NDB::connection(),
    InvalidArgumentException::class,
    '.env');

foreach (glob($directory . '/*') as $leftover) {
    @unlink($leftover);
}
@rmdir($directory);

summary();
