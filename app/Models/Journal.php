<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Journal extends Model
{
    use HasFactory;

    protected $table = 'journals';
    protected $primaryKey = 'id_jurnal'; // <--- PASTIKAN BARIS INI ADA

    protected $fillable = [
        'id_schedule',
        'date',
        'topic_description',
        'photo_path',
    ];

    public function schedule()
    {
        return $this->belongsTo(Schedule::class, 'id_schedule', 'id_schedule');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'id_jurnal', 'id_jurnal');
    }
}