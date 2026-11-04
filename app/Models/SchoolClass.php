<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolClass extends Model
{
    protected $table = 'classes';
    protected $primaryKey = 'id_class';
    protected $fillable = ['kelas_nomor', 'nama_kelas', 'nomor_kelas'];

    public function users()
    {
        return $this->hasMany(User::class, 'id_class', 'id_class');
    }
}