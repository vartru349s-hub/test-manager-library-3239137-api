<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $fillable = [
        'id', 'title', 'author', 'isbn', 'category_id',
        'total_copies', 'available_copies', 'cover_key', 'deleted_at', 'version',
    ];
    protected $casts = ['deleted_at' => 'datetime'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function loanItems()
    {
        return $this->hasMany(LoanItem::class);
    }
}
