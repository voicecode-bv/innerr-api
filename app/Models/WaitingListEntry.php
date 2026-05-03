<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WaitingListEntry extends Model
{
    use HasUuids;

    protected $fillable = ['email'];
}
