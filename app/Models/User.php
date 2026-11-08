<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens; 

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable; 

    protected $table = 'users';
    protected $primaryKey = 'id_user';

    protected $fillable = [
        'id_role',
        'id_class',
        'username',
        'no_telp',
        'password',
        'fullname',
        'email',
        'nis',
        'nisn',
        'nip',
        'title',
        'avatar', 
        'bio',
    ];

    protected $hidden = [
        'password',
    ];

    protected $appends = [
        'avatar_url',
    ];

    public function getAvatarUrlAttribute()
    {
        return $this->avatar ? url('storage/' . ltrim($this->avatar, '/')) : null;
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'id_role', 'id_role');
    }

    // Untuk Siswa & Wali Kelas (1 Kelas)
    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'id_class', 'id_class');
    }

    // Untuk Guru BK & Guru BK Pengajar (Multi Kelas)
    public function counselorClasses()
    {
        return $this->belongsToMany(SchoolClass::class, 'counselor_classes', 'id_user', 'id_class');
    }

    // Untuk Siswa: daftar Akun Orang Tua yang terhubung
    public function parents()
    {
        return $this->belongsToMany(User::class, 'parent_student', 'student_id', 'parent_id')->withTimestamps();
    }

    // Untuk Orang Tua: daftar Siswa (anak) yang terhubung
    public function linkedStudents()
    {
        return $this->belongsToMany(User::class, 'parent_student', 'parent_id', 'student_id')->withTimestamps();
    }
}