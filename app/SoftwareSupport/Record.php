<?php

namespace App\SoftwareSupport;

use Illuminate\Database\Eloquent\Model;

class Record extends Model
{
    protected $table = 'software_support_records';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['payload', 'request_key'];

    protected $casts = ['payload' => 'encrypted:array', 'owner_tenant_id' => 'integer', 'client_tenant_id' => 'integer', 'ticket_id' => 'integer', 'created_by' => 'integer'];
}
