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

    protected $appends = [
        'id_journal',
        'journal_date',
        'photos',
        'photos_list',
    ];

    public function getIdJournalAttribute()
    {
        return $this->id_jurnal;
    }

    public function getJournalDateAttribute()
    {
        return $this->date;
    }

    public function getPhotosAttribute()
    {
        if (empty($this->photo_path)) {
            return [];
        }
        $decoded = json_decode($this->photo_path, true);
        if (is_array($decoded)) {
            return array_map(function ($p) {
                $clean = ltrim($p, '/');
                return [
                    'photo_path' => str_starts_with($clean, 'http') ? $clean : url('storage/' . $clean)
                ];
            }, $decoded);
        }
        $clean = ltrim($this->photo_path, '/');
        return [
            ['photo_path' => str_starts_with($clean, 'http') ? $clean : url('storage/' . $clean)]
        ];
    }

    public function getPhotosListAttribute()
    {
        return $this->getPhotosAttribute();
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class, 'id_schedule', 'id_schedule');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'id_jurnal', 'id_jurnal');
    }
}