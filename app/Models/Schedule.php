<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    use HasFactory;

    protected $table = 'schedules';
    protected $primaryKey = 'id_schedule';

    protected $fillable = [
        'id_guru',
        'id_class',
        'mapel',
        'day_of_week',
        'start_time',
        'end_time',
        'cycle_type',
        'id_room',
    ];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'id_guru', 'id_user');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'id_class', 'id_class');
    }

    public function room()
    {
        return $this->belongsTo(Room::class, 'id_room', 'id_room');
    }

    public function journals()
    {
        return $this->hasMany(Journal::class, 'id_schedule', 'id_schedule');
    }
}