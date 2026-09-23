<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use HasFactory;

    // Nama tabel di database
    protected $table = 'subjects';

    // Primary key (sesuaikan jika di migration kamu pakai 'id_subject')
    protected $primaryKey = 'id_subject'; 

    // Kolom yang boleh diisi secara massal
    protected $fillable = [
        'nama_mapel',
        'kode_mapel',
    ];
}