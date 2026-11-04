<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use HasFactory;

    protected $table = 'rooms';
    protected $primaryKey = 'id_room';

    protected $fillable = [
        'nama_room',
        'nomor_room',
    ];

    public function schedules()
    {
        return $this->hasMany(Schedule::class, 'id_room', 'id_room');
    }
}