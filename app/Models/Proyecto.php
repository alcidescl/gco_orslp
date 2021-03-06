<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proyecto extends Model
{
    protected $table = 'gcoproyecto';
    protected $primaryKey = 'pryId';
    public $timestamps = false;

    public function ejecutor()
    {
    	return $this->belongsTo('App\Models\Uejecutora','pryId','ejeProject');	
    }
}
