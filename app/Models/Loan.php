<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Loan extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $fillable = ['id', 'member_id', 'loaned_by_user_id', 'loaned_at'];
    protected $casts = ['loaned_at' => 'datetime'];
    public function items() { return $this->hasMany(LoanItem::class); }
}
