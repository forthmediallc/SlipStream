<?php

namespace Slipstream\Fleet;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Slipstream\Database\Tables;

/**
 * The cache store nodes coordinate through: the migration lock, the record
 * of which commits are migrated, and the fleet's last good commit. It must be
 * shared by every node (redis, database, memcached, dynamodb) — a file or
 * array store gives each node its own — and should be one nothing else
 * flushes: a deploy hook running `cache:clear` against it would erase them.
 */
final class SharedStore
{
    public static function name(): string
    {
        $configured = config('slipstream.deploy.migration_lock_store');

        return is_string($configured) && $configured !== '' ? $configured : (string) config('cache.default');
    }

    public static function cache(): Repository
    {
        $configured = config('slipstream.deploy.migration_lock_store');

        return is_string($configured) && $configured !== '' ? Cache::store($configured) : Cache::store();
    }

    /**
     * What a key in the store belongs to besides its subject: the app's
     * database. Nodes on one database share locks and markers; another
     * environment pointed at the same cache server (staging beside
     * production, under the same cache prefix) never acts on them. A node
     * whose default database is a file of its own is a scope of its own —
     * that database needs migrating too.
     */
    public static function scope(): string
    {
        $tables = app(Tables::class);

        return substr(sha1($tables->identity($tables->defaultConnection())), 0, 12);
    }

    /**
     * Whether the store's driver can be shared at all. Answers nothing about
     * whether every node really points at the same one.
     */
    public static function isShareable(): bool
    {
        $driver = config('cache.stores.'.self::name().'.driver');

        return ! in_array($driver, ['file', 'array', 'null', null], true);
    }
}
