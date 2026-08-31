<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBackupCode extends Model
{
    protected $fillable = ['user_id', 'code', 'used_at'];

    protected $casts = [
        'used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function scopeUnused($query)
    {
        return $query->whereNull('used_at');
    }
}
