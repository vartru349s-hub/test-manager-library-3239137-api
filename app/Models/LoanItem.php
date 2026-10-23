<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanItem extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $table = 'loan_items';
}
