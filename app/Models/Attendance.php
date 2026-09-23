<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $table = 'attendances';
    protected $primaryKey = 'id_presensi';

    protected $fillable = [
        'id_jurnal',
        'id_siswa',
        'status',
        'date',
    ];

    public function journal()
    {
        return $this->belongsTo(Journal::class, 'id_jurnal', 'id_jurnal');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'id_siswa', 'id_user');
    }
}