<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    use HasFactory;

    protected $table = 'leave_requests';
    protected $primaryKey = 'id_izin_keluar';

    protected $fillable = [
        'id_siswa',
        'id_pengaju',
        'verified_by',
        'start_date',
        'end_date',
        'reason',
        'document_path',
        'status_approval',
        'reason_ditolak',
    ];

    protected $casts = [
        'document_path' => 'array',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'id_siswa', 'id_user');
    }

    public function applicant()
    {
        return $this->belongsTo(User::class, 'id_pengaju', 'id_user');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by', 'id_user');
    }
}