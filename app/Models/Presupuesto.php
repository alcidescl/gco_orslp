<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Presupuesto extends Model
{
    protected $table = 'gcopresupuesto';
    protected $primaryKey = 'preId';
    public $timestamps = false;
}
