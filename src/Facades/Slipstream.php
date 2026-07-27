<?php

namespace Slipstream\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void auth(\Closure $callback)
 * @method static bool check(\Illuminate\Http\Request $request)
 * @method static void healthCheck(string $name, \Closure $callback)
 * @method static string serverName()
 * @method static string appRoot()
 * @method static string path()
 * @method static bool viewEnabled(string $section)
 *
 * @see \Slipstream\Slipstream
 */
class Slipstream extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Slipstream\Slipstream::class;
    }
}
