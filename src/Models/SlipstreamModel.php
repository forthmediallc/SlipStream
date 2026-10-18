<?php

namespace Slipstream\Models;

use Illuminate\Database\Eloquent\Model;
use Slipstream\Slipstream;

/**
 * Every Slipstream table lives on one connection: SLIPSTREAM_DB_CONNECTION,
 * or the app's default when that is unset. A connection set on an instance
 * explicitly (Model::on(), setConnection(), a row hydrated from a query on a
 * named connection) still wins.
 */
abstract class SlipstreamModel extends Model
{
    public function getConnectionName()
    {
        return parent::getConnectionName() ?? Slipstream::databaseConnection();
    }
}
