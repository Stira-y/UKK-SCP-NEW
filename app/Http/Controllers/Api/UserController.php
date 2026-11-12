<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\SchoolClass;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    private function releaseArchivedEmailCollisions(string $email, $excludeUserId = null): void
    {
        $cleanEmail = strtolower(trim($email));
        if ($cleanEmail === '') {
            return;
        }

        $query = User::whereRaw('LOWER(TRIM(email)) = ?', [$cleanEmail]);
        if ($excludeUserId !== null && $excludeUserId !== 'general') {
            $query->where('id_user', '!=', $excludeUserId);
        }

        $query->where(function ($q) {
            $q->whereRaw("LOWER(TRIM(COALESCE(title, ''))) IN ('dikeluarkan', 'pensiun', 'alumni', 'lulus')")
              ->orWhere(function ($sub) {
                  $sub->where('id_role', 4)
                      ->whereNull('id_class')
                      ->where(function ($p) {
                          $p->whereNull('title')
                            ->orWhereRaw("LOWER(TRIM(title)) != 'orang tua'");
                      });
              });
        })
        ->get()
        ->each(function ($archivedUser) {
            $archivedUser->email = 'archived_' . $archivedUser->id_user . '_' . time() . '_' . $archivedUser->email;
            $archivedUser->save();
        });
    }

    private function isEmailTakenByActiveUser(string $email, $excludeUserId = null): bool
    {
        $cleanEmail = strtolower(trim($email));
        if ($cleanEmail === '') {
            return false;
        }

        $this->releaseArchivedEmailCollisions($cleanEmail, $excludeUserId);

        $query = User::whereRaw('LOWER(TRIM(email)) = ?', [$cleanEmail]);
        if ($excludeUserId !== null && $excludeUserId !== 'general') {
            $query->where('id_user', '!=', $excludeUserId);
        }

        return $query
            ->whereRaw("LOWER(TRIM(COALESCE(title, ''))) NOT IN ('dikeluarkan', 'pensiun', 'alumni', 'lulus', 'orang tua')")
            ->where(function ($q) {
                $q->where('id_role', '!=', 4)
                  ->orWhereNotNull('id_class');
            })
            ->exists();
    }
    public function index(Request $request)
    {
        $query = User::with(['role', 'schoolClass', 'counselorClasses', 'parents', 'linkedStudents.schoolClass']);

        if ($request->has('id_role')) {
            $query->where('id_role', $request->id_role);
        }

        if ($request->has('id_class')) {
            $query->where('id_class', $request->id_class);
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->latest()->get()
        ], 200);
    }

    public function getStudentsGrouped(Request $request)
    {
        $category = $request->query('category', 'all');

        if ($category === 'orang_tua') {
            $parents = User::with(['role', 'schoolClass', 'linkedStudents.schoolClass'])
                ->where('id_role', 4)
                ->whereRaw("LOWER(TRIM(COALESCE(title, ''))) = 'orang tua'")
                ->get()
                ->sortBy(fn($u) => strtolower($u->fullname ?? ''))
                ->values();

            $parents->transform(function ($p) {
                if (!$p->schoolClass && $p->linkedStudents && $p->linkedStudents->count() > 0) {
                    $firstChild = $p->linkedStudents->first();
                    if ($firstChild && $firstChild->schoolClass) {
                        $p->setRelation('schoolClass', $firstChild->schoolClass);
                    }
                }
                return $p;
            });

            return response()->json([
                'status' => 'success',
                'data' => $parents
            ], 200);
        }

        $query = User::with(['role', 'schoolClass', 'parents'])
            ->where('id_role', 4)
            ->whereNotNull('id_class')
            ->where(function ($q) {
                $q->whereNull('title')
                  ->orWhereRaw("LOWER(TRIM(title)) != 'orang tua'");
            });

        if (in_array($category, ['X', 'XI', 'XII'], true)) {
            $query->whereHas('schoolClass', function ($q) use ($category) {
                $q->where('kelas_nomor', $category);
            });
        }

        $students = $query->get()->sort(function ($a, $b) {
            $classNameA = $a->schoolClass
                ? $a->schoolClass->kelas_nomor . ' ' . $a->schoolClass->nama_kelas
                : 'ZZZ';

            $classNameB = $b->schoolClass
                ? $b->schoolClass->kelas_nomor . ' ' . $b->schoolClass->nama_kelas
                : 'ZZZ';

            $cmp = strcasecmp($classNameA, $classNameB);

            if ($cmp === 0) {
                return strcasecmp($a->fullname ?? '', $b->fullname ?? '');
            }

            return $cmp;
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => $students
        ], 200);
    }

    public function expelStudent($id)
    {
        $student = User::where('id_role', 4)->findOrFail($id);

        // Simpan snapshot untuk batas undo 5 menit (300 detik)
        Cache::put("archive_snapshot_{$student->id_user}", [
            'id_class'    => $student->id_class,
            'email'       => $student->email,
            'password'    => $student->password,
            'title'       => $student->title,
            'archived_at' => now()->timestamp,
        ], 300);

        $student->id_class = null;
        $student->title = 'dikeluarkan';
        if ($student->email && !str_starts_with($student->email, 'archived_')) {
            $student->email = 'archived_' . $student->id_user . '_' . time() . '_' . $student->email;
        }
        $student->save();

        return response()->json([
            'status' => 'success',
            'message' => "Siswa {$student->fullname} berhasil dikeluarkan dan dipindahkan ke Data Arsip Siswa."
        ], 200);
    }

    public function retireTeacher($id)
    {
        $teacher = User::whereIn('id_role', [1, 2, 3])->findOrFail($id);

        // Simpan snapshot untuk batas undo 5 menit (300 detik)
        Cache::put("archive_snapshot_{$teacher->id_user}", [
            'id_class'    => $teacher->id_class,
            'email'       => $teacher->email,
            'password'    => $teacher->password,
            'title'       => $teacher->title,
            'archived_at' => now()->timestamp,
        ], 300);

        $teacher->id_class = null;
        $teacher->title = 'Pensiun';
        if ($teacher->email && !str_starts_with($teacher->email, 'archived_')) {
            $teacher->email = 'archived_' . $teacher->id_user . '_' . time() . '_' . $teacher->email;
        }
        $teacher->save();

        return response()->json([
            'status' => 'success',
            'message' => "Guru {$teacher->fullname} berhasil dipensiunkan dan dipindahkan ke Data Arsip."
        ], 200);
    }

    public function restoreArchivedUser(Request $request, $id)
    {
        $user = User::findOrFail($id);

        // Cek snapshot dari Cache (radius 5 menit / 300 detik)
        $snapshot = Cache::get("archive_snapshot_{$user->id_user}");
        $isWithin5Min = !empty($snapshot) && isset($snapshot['archived_at']) && ((now()->timestamp - $snapshot['archived_at']) <= 300);

        // 1. Jika Siswa (id_role == 4)
        if ((int)$user->id_role === 4) {
            $user->title = null;

            if ($isWithin5Min) {
                // --- KONDISI A: MASIH DALAM RADIUS 5 MENIT (UNDO INSTAN) ---
                $restoredClassId = $snapshot['id_class'] ?? null;
                if ($restoredClassId && SchoolClass::where('id_class', $restoredClassId)->exists()) {
                    $user->id_class = $restoredClassId;
                } else {
                    $user->id_class = $request->input('id_class') ?: SchoolClass::orderBy('kelas_nomor', 'asc')->value('id_class');
                }

                // Pulihkan email asli
                if (!empty($snapshot['email'])) {
                    $origEmail = $snapshot['email'];
                    if (!User::where('email', $origEmail)->where('id_user', '!=', $user->id_user)->exists()) {
                        $user->email = $origEmail;
                    }
                } elseif ($user->email && preg_match('/^archived_\d+_\d+_(.+)$/', $user->email, $matches)) {
                    $cleanEmail = $matches[1];
                    if (!User::where('email', $cleanEmail)->where('id_user', '!=', $user->id_user)->exists()) {
                        $user->email = $cleanEmail;
                    }
                }

                // Pulihkan password asli
                if (!empty($snapshot['password'])) {
                    $user->password = $snapshot['password'];
                }

                $user->save();
                Cache::forget("archive_snapshot_{$user->id_user}");

                $className = $user->schoolClass
                    ? trim("{$user->schoolClass->kelas_nomor} {$user->schoolClass->nama_kelas} " . ($user->schoolClass->nomor_kelas ?? ''))
                    : '';

                return response()->json([
                    'status'          => 'success',
                    'is_instant_undo' => true,
                    'message'         => "Pembatalan berhasil! Karena masih dalam radius 5 menit, data siswa {$user->fullname} langsung dikembalikan ke kelas aslinya ({$className}) beserta seluruh data akun (email & password) utuh tanpa perubahan."
                ], 200);

            } else {
                // --- KONDISI B: MELEBIHI 5 MENIT (PILIH KELAS MANUAL & RESET DATA) ---
                $manualClassId = $request->input('id_class');

                if (!$manualClassId) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Batas waktu 5 menit telah kedaluwarsa. Anda wajib memilih kelas secara manual untuk mengembalikan siswa ini.'
                    ], 422);
                }

                if (!SchoolClass::where('id_class', $manualClassId)->exists()) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Kelas yang dipilih tidak valid.'
                    ], 422);
                }

                $user->id_class = $manualClassId;

                // Email dan password lama tidak dikembalikan karena sudah terhapus
                $baseUser = $user->username ?: ('siswa_' . ($user->nis ?: $user->id_user));
                $newDefaultEmail = strtolower($baseUser) . '@smkn1purwosari.sch.id';
                if (User::where('email', $newDefaultEmail)->where('id_user', '!=', $user->id_user)->exists()) {
                    $newDefaultEmail = strtolower($baseUser) . '_' . $user->id_user . '@smkn1purwosari.sch.id';
                }
                $user->email = $newDefaultEmail;

                // Reset password baru default Peh_1234
                $user->password = Hash::make('Peh_1234');
                $user->save();

                $className = $user->schoolClass
                    ? trim("{$user->schoolClass->kelas_nomor} {$user->schoolClass->nama_kelas} " . ($user->schoolClass->nomor_kelas ?? ''))
                    : '';

                return response()->json([
                    'status'          => 'success',
                    'is_instant_undo' => false,
                    'message'         => "Data siswa {$user->fullname} berhasil dipulihkan ke kelas {$className}. Karena telah melebihi batas 5 menit, email lama & password telah di-reset (Email: {$user->email}, Password default: Peh_1234)."
                ], 200);
            }
        }

        // 2. Logika untuk Guru / Waka / Kesiswaan
        $user->title = 'Guru Normada';
        if (!in_array((int)$user->id_role, [1, 2, 3])) {
            $user->id_role = 2;
        }

        if ($isWithin5Min) {
            // Restore within 5 minutes
            if (!empty($snapshot['email'])) {
                $origEmail = $snapshot['email'];
                if (!User::where('email', $origEmail)->where('id_user', '!=', $user->id_user)->exists()) {
                    $user->email = $origEmail;
                }
            } elseif ($user->email && preg_match('/^archived_\d+_\d+_(.+)$/', $user->email, $matches)) {
                $user->email = $matches[1];
            }

            if (!empty($snapshot['password'])) {
                $user->password = $snapshot['password'];
            }

            $user->save();
            Cache::forget("archive_snapshot_{$user->id_user}");

            return response()->json([
                'status'          => 'success',
                'is_instant_undo' => true,
                'message'         => "Pembatalan berhasil! Guru {$user->fullname} langsung dipulihkan ke Kelola Data Guru beserta seluruh data akun utuh (dalam radius 5 menit)."
            ], 200);
        } else {
            // Melebihi 5 menit
            $baseUser = $user->username ?: ('guru_' . ($user->nip ?: $user->id_user));
            $newDefaultEmail = strtolower($baseUser) . '@smkn1purwosari.sch.id';
            if (User::where('email', $newDefaultEmail)->where('id_user', '!=', $user->id_user)->exists()) {
                $newDefaultEmail = strtolower($baseUser) . '_' . $user->id_user . '@smkn1purwosari.sch.id';
            }
            $user->email = $newDefaultEmail;
            $user->password = Hash::make('Peh_1234');
            $user->save();

            return response()->json([
                'status'          => 'success',
                'is_instant_undo' => false,
                'message'         => "Data guru {$user->fullname} berhasil dipulihkan ke Kelola Data Guru. Karena sudah melewati batas 5 menit, email lama & password di-reset (Email baru: {$user->email}, Password default: Peh_1234)."
            ], 200);
        }
    }

    public function getGraduations(Request $request)
    {
        $type = $request->query('type');

        if ($type === 'guru_pensiun' || $type === 'pensiun') {
            $retiredTeachers = User::whereIn('id_role', [1, 2, 3])
                ->where('title', 'Pensiun')
                ->orderBy('fullname', 'asc')
                ->get();

            $result = $retiredTeachers->map(function ($t) {
                $snapshot = Cache::get("archive_snapshot_{$t->id_user}");
                $isWithinUndo = false;
                $remainingUndoSec = 0;
                if (!empty($snapshot) && isset($snapshot['archived_at'])) {
                    $elapsed = now()->timestamp - $snapshot['archived_at'];
                    if ($elapsed <= 300) {
                        $isWithinUndo = true;
                        $remainingUndoSec = max(0, 300 - $elapsed);
                    }
                }

                return [
                    'id_user'            => $t->id_user,
                    'nisn'               => '-',
                    'nis'                => '-',
                    'nip'                => $t->nip ?? '-',
                    'fullname'           => $t->fullname ?? $t->username,
                    'status_arsip'       => 'guru_pensiun',
                    'status_label'       => 'Guru Pensiun',
                    'total_izin'         => '-',
                    'total_presensi'     => '-',
                    'tanggal_arsip'      => $t->updated_at ? date('d/m/Y', strtotime($t->updated_at)) : '-',
                    'tahun_lulus'        => $t->updated_at ? date('Y', strtotime($t->updated_at)) : date('Y'),
                    'is_within_undo'     => $isWithinUndo,
                    'remaining_undo_sec' => $remainingUndoSec,
                    'prev_class_id'      => null,
                    'prev_class_name'    => '',
                ];
            });

            return response()->json([
                'status' => 'success',
                'data'   => $result
            ], 200);
        }

        $query = User::where('id_role', 4)
            ->whereNull('id_class')
            ->where(function ($q) {
                $q->whereNull('title')
                  ->orWhereRaw("LOWER(TRIM(title)) != 'orang tua'");
            });

        if ($type === 'dikeluarkan') {
            $query->where('title', 'dikeluarkan');
        } elseif ($type === 'alumni' || $type === 'lulus') {
            $query->where(function ($q) {
                $q->whereNull('title')
                  ->orWhere('title', '!=', 'dikeluarkan');
            });
        }

        $graduates = $query->orderByRaw('CAST(nisn AS UNSIGNED) ASC')
            ->orderBy('nisn', 'asc')
            ->get();

        $userIds = $graduates->pluck('id_user');

        $attendancesCounts = Attendance::whereIn('id_siswa', $userIds)
            ->where('status', 'Hadir')
            ->selectRaw('id_siswa, count(*) as total')
            ->groupBy('id_siswa')
            ->pluck('total', 'id_siswa');

        $leaveCounts = LeaveRequest::whereIn('id_siswa', $userIds)
            ->whereIn('status_approval', ['Disetujui', 'Di Terima', 'di terima', 'disetujui'])
            ->selectRaw('id_siswa, count(*) as total')
            ->groupBy('id_siswa')
            ->pluck('total', 'id_siswa');

        $result = $graduates->map(function ($st) use ($attendancesCounts, $leaveCounts) {
            $totalIzin = $leaveCounts[$st->id_user] ?? 0;
            $totalPresensi = $attendancesCounts[$st->id_user] ?? 0;
            $isExpelled = (strtolower(trim($st->title ?? '')) === 'dikeluarkan');

            $snapshot = Cache::get("archive_snapshot_{$st->id_user}");
            $isWithinUndo = false;
            $remainingUndoSec = 0;
            $prevClassId = null;
            $prevClassName = '';

            if (!empty($snapshot) && isset($snapshot['archived_at'])) {
                $elapsed = now()->timestamp - $snapshot['archived_at'];
                if ($elapsed <= 300) {
                    $isWithinUndo = true;
                    $remainingUndoSec = max(0, 300 - $elapsed);
                    $prevClassId = $snapshot['id_class'] ?? null;
                    if ($prevClassId) {
                        $cls = SchoolClass::find($prevClassId);
                        if ($cls) {
                            $prevClassName = trim("{$cls->kelas_nomor} {$cls->nama_kelas} " . ($cls->nomor_kelas ?? ''));
                        }
                    }
                }
            }

            return [
                'id_user'            => $st->id_user,
                'nisn'               => $st->nisn ?? '-',
                'nis'                => $st->nis ?? '-',
                'nip'                => '-',
                'fullname'           => $st->fullname ?? $st->username,
                'status_arsip'       => $isExpelled ? 'dikeluarkan' : 'alumni',
                'status_label'       => $isExpelled ? 'Dikeluarkan' : 'Lulus / Alumni',
                'total_izin'         => $totalIzin,
                'total_presensi'     => $totalPresensi,
                'tanggal_arsip'      => $st->updated_at ? date('d/m/Y', strtotime($st->updated_at)) : '-',
                'tahun_lulus'        => $st->updated_at ? date('Y', strtotime($st->updated_at)) : date('Y'),
                'is_within_undo'     => $isWithinUndo,
                'remaining_undo_sec' => $remainingUndoSec,
                'prev_class_id'      => $prevClassId,
                'prev_class_name'    => $prevClassName,
            ];
        });

        // Jika tidak memfilter secara spesifik type, sertakan juga guru pensiun
        if (!$type) {
            $retiredTeachers = User::whereIn('id_role', [1, 2, 3])
                ->where('title', 'Pensiun')
                ->orderBy('fullname', 'asc')
                ->get();
            foreach ($retiredTeachers as $t) {
                $snapshot = Cache::get("archive_snapshot_{$t->id_user}");
                $isWithinUndo = false;
                $remainingUndoSec = 0;
                if (!empty($snapshot) && isset($snapshot['archived_at'])) {
                    $elapsed = now()->timestamp - $snapshot['archived_at'];
                    if ($elapsed <= 300) {
                        $isWithinUndo = true;
                        $remainingUndoSec = max(0, 300 - $elapsed);
                    }
                }

                $result->push([
                    'id_user'            => $t->id_user,
                    'nisn'               => '-',
                    'nis'                => '-',
                    'nip'                => $t->nip ?? '-',
                    'fullname'           => $t->fullname ?? $t->username,
                    'status_arsip'       => 'guru_pensiun',
                    'status_label'       => 'Guru Pensiun',
                    'total_izin'         => '-',
                    'total_presensi'     => '-',
                    'tanggal_arsip'      => $t->updated_at ? date('d/m/Y', strtotime($t->updated_at)) : '-',
                    'tahun_lulus'        => $t->updated_at ? date('Y', strtotime($t->updated_at)) : date('Y'),
                    'is_within_undo'     => $isWithinUndo,
                    'remaining_undo_sec' => $remainingUndoSec,
                    'prev_class_id'      => null,
                    'prev_class_name'    => '',
                ]);
            }
        }

        return response()->json([
            'status' => 'success',
            'data'   => $result
        ], 200);
    }

    public function getTeachersGrouped(Request $request)
    {
        \App\Http\Controllers\Api\ScheduleController::cleanupDummyTeachers();

        $category = $request->query('category', 'all');

        $query = User::with(['role', 'schoolClass', 'counselorClasses'])
            ->where(function ($q) {
                $q->whereIn('id_role', [1, 2, 3])
                  ->orWhereNotNull('nip');
            })
            ->where(function ($q) {
                $q->whereNull('email')
                  ->orWhere('email', 'not like', '%@school.id');
            })
            ->where('fullname', '!=', '-')
            ->where(function ($q) {
                $q->whereNull('title')
                  ->orWhere('title', '!=', 'Pensiun');
            });

        if ($category !== 'all') {
            if ($category === 'Waka') {
                $query->where('id_role', 1);
            } elseif ($category === 'Walikelas') {
                $query->where('id_role', 3)->where('title', 'Walikelas');
            } elseif ($category === 'Guru BK' || $category === 'Guru BK Pengajar') {
                $query->where('id_role', 3)->whereIn('title', ['Guru BK', 'Guru BK Pengajar', 'gurubk', 'gurubkpengajar']);
            } elseif ($category === 'Guru Normada') {
                $query->where('id_role', 2)->whereIn('title', ['Guru Normada', 'Guru Biasa']);
            } elseif ($category === 'Guru Produktif') {
                $query->where('id_role', 2)->where('title', 'Guru Produktif');
            } elseif ($category === 'Guru Biasa' || $category === 'Guru') {
                $query->where('id_role', 2);
            }
        }

        $teachers = $query->get()->sortBy('fullname')->values();

        // Menyertakan format id_class gabungan agar frontend yang membaca string koma tetap berjalan
        $teachers->transform(function ($t) {
            if (strtolower(str_replace(' ', '', trim($t->title ?? ''))) === 'gurubkpengajar') {
                $t->title = 'Guru BK';
            }
            if ($t->counselorClasses && $t->counselorClasses->count() > 0) {
                $t->id_class = $t->counselorClasses->pluck('id_class')->implode(',');
            }
            return $t;
        });

        return response()->json([
            'status' => 'success',
            'data' => $teachers
        ], 200);
    }

    public function storeTeacherByWaka(Request $request)
    {
        if ($request->has('nip') && trim((string) $request->nip) === '') {
            $request->merge(['nip' => null]);
        }

        if ($request->filled('email')) {
            $this->releaseArchivedEmailCollisions((string) $request->email);
            if ($this->isEmailTakenByActiveUser((string) $request->email)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Email sudah digunakan oleh akun lain!'
                ], 422);
            }
        }

        $messages = [
            'nip.unique' => 'NIP sudah terdaftar!',
            'username.unique' => 'Username sudah digunakan!',
            'email.unique' => 'Email sudah digunakan oleh akun lain!',
            'id_role.required' => 'Role wajib dipilih!',
            'id_role.in' => 'Pilihan role tidak valid!',
        ];

        $validator = Validator::make($request->all(), [
            'fullname' => 'required|string|max:255',
            'nip' => 'nullable|string|max:50|unique:users,nip',
            'id_role' => 'required|integer|in:1,2,3',
            'id_class' => 'nullable',
            'title' => 'required|string|max:100',
            'username' => 'required|string|max:100|unique:users,username',
            'email' => 'required|string|email|max:255',
            'password' => 'nullable|string|min:8'
        ], $messages);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();
        if (strtolower(str_replace(' ', '', trim($data['title'] ?? ''))) === 'gurubkpengajar') {
            $data['title'] = 'Guru BK';
        }
        $isGuruBk = strtolower(str_replace(' ', '', trim($data['title'] ?? ''))) === 'gurubk';

        $plainPassword = !empty($data['password']) ? $data['password'] : 'Peh_1234';
        $data['password'] = Hash::make($plainPassword);

        // Parsing multi-kelas (khusus Guru BK, baik 1 kelas maupun banyak kelas tetap masuk ke counselor_classes)
        $multiClasses = [];
        if (!empty($request->id_class)) {
            if ($isGuruBk || (is_string($request->id_class) && str_contains($request->id_class, ','))) {
                $multiClasses = array_values(array_filter(array_map('trim', explode(',', (string) $request->id_class))));
                $data['id_class'] = null;
            } elseif (is_numeric($request->id_class)) {
                $data['id_class'] = (int) $request->id_class;
            } else {
                $data['id_class'] = null;
            }
        }

        $teacher = User::create($data);

        // Simpan ke tabel relasi counselor_classes
        if (!empty($multiClasses)) {
            $teacher->counselorClasses()->sync($multiClasses);
        }

        $teacher->load(['role', 'schoolClass', 'counselorClasses']);
        if ($teacher->counselorClasses && $teacher->counselorClasses->count() > 0) {
            $teacher->id_class = $teacher->counselorClasses->pluck('id_class')->implode(',');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Data guru baru berhasil ditambahkan',
            'data' => $teacher
        ], 201);
    }

    public function updateTeacherByWaka(Request $request, $id)
    {
        $teacher = User::find($id);

        if (!$teacher) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data guru tidak ditemukan'
            ], 404);
        }

        if ($request->has('nip') && trim((string) $request->nip) === '') {
            $request->merge(['nip' => null]);
        }
        if ($request->has('email') && trim((string) $request->email) === '') {
            $request->request->remove('email');
        }

        if ($request->filled('email')) {
            $this->releaseArchivedEmailCollisions((string) $request->email, $id);
            if ($this->isEmailTakenByActiveUser((string) $request->email, $id)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Email sudah digunakan oleh akun lain!'
                ], 422);
            }
        }

        $messages = [
            'nip.unique' => 'NIP sudah terdaftar!',
            'username.unique' => 'Username sudah digunakan!',
            'email.unique' => 'Email sudah digunakan oleh akun lain!',
            'id_role.in' => 'Pilihan role tidak valid!',
        ];

        $validator = Validator::make($request->all(), [
            'nip' => 'nullable|string|max:50|unique:users,nip,' . $id . ',id_user',
            'id_role' => 'sometimes|required|integer|in:1,2,3',
            'id_class' => 'nullable',
            'title' => 'required|string|max:100',
            'username' => 'required|string|max:100|unique:users,username,' . $id . ',id_user',
            'email' => 'nullable|string|email|max:255',
            'password' => 'nullable|string|min:8'
        ], $messages);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();
        if (strtolower(str_replace(' ', '', trim($data['title'] ?? ''))) === 'gurubkpengajar') {
            $data['title'] = 'Guru BK';
        }
        $isGuruBk = strtolower(str_replace(' ', '', trim($data['title'] ?? ''))) === 'gurubk';

        if (empty($data['email'])) {
            unset($data['email']);
        }

        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        // Parsing multi-kelas saat update (Guru BK baik 1 maupun banyak kelas tetap tersimpan di counselor_classes)
        $multiClasses = null;
        if (array_key_exists('id_class', $data) || $isGuruBk) {
            if ($isGuruBk) {
                $rawCls = (string) ($request->id_class ?? '');
                $multiClasses = array_values(array_filter(array_map('trim', explode(',', $rawCls))));
                $data['id_class'] = null;
            } elseif (is_string($request->id_class) && str_contains($request->id_class, ',')) {
                $multiClasses = array_values(array_filter(array_map('trim', explode(',', $request->id_class))));
                $data['id_class'] = null;
            } elseif (is_numeric($request->id_class)) {
                $data['id_class'] = (int) $request->id_class;
                $multiClasses = [];
            } else {
                $data['id_class'] = null;
                $multiClasses = [];
            }
        }

        $teacher->update($data);

        // Perbarui tabel relasi counselor_classes
        if ($multiClasses !== null) {
            $teacher->counselorClasses()->sync($multiClasses);
        }

        $wakaUser = $request->user();
        if ($wakaUser) {
            Cache::forget("waka_edit_otp_new_email_{$wakaUser->id_user}_{$id}");
            Cache::forget("waka_edit_otp_password_{$wakaUser->id_user}_{$id}");
            Cache::forget("waka_verified_new_email_{$wakaUser->id_user}_{$id}");
            Cache::forget("waka_verified_password_{$wakaUser->id_user}_{$id}");
        }

        $teacher->load(['role', 'schoolClass', 'counselorClasses']);
        if ($teacher->counselorClasses && $teacher->counselorClasses->count() > 0) {
            $teacher->id_class = $teacher->counselorClasses->pluck('id_class')->implode(',');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Data guru berhasil diperbarui',
            'data' => $teacher
        ], 200);
    }

    public function importTeachersBatch(Request $request)
    {
        @set_time_limit(300);

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.fullname' => 'required|string|max:255',
            'items.*.username' => 'required|string|max:100',
            'items.*.email'    => 'required|string|email|max:255',
            'items.*.role'     => 'nullable|string',
            'items.*.title'    => 'nullable|string',
            'items.*.nip'      => 'nullable|string|max:50',
            'items.*.password' => 'nullable|string',
            'items.*.kelas'    => 'nullable|string',
        ]);

        $classes = SchoolClass::all();
        $importedCount = 0;
        $updatedCount = 0;
        $errors = [];

        foreach ($request->items as $idx => $item) {
            $rowNum = $idx + 2;

            $email = strtolower(trim($item['email']));
            $this->releaseArchivedEmailCollisions($email);

            $rawRole = strtolower(trim(str_replace(' ', '', (string)($item['role'] ?? ''))));
            $title = trim($item['title'] ?? 'Guru Normada');
            $cleanTitle = strtolower(str_replace(' ', '', $title));
            if ($cleanTitle === 'gurubiasa') {
                $title = 'Guru Normada';
                $cleanTitle = 'gurunormada';
            }

            $roleId = 2;
            if (in_array($rawRole, ['1', 'waka', 'wakakurikulum', 'admin']) || $cleanTitle === 'waka') {
                $roleId = 1;
            } elseif (in_array($rawRole, ['3', 'kesiswaan', 'walikelas', 'gurubk', 'gurubkpengajar']) || in_array($cleanTitle, ['walikelas', 'gurubk', 'gurubkpengajar'])) {
                $roleId = 3;
            }

            $isWali = ($cleanTitle === 'walikelas');
            $matchedClassId = null;
            $bkClassIds = [];

            if ($isWali) {
                if (empty($item['kelas']) || trim($item['kelas']) === '-') {
                    $errors[] = "Baris {$rowNum} ({$item['fullname']}): Wali Kelas wajib menentukan kelas binaan.";
                    continue;
                }

                $cleanClassStr = strtolower(preg_replace('/\s+/', ' ', trim($item['kelas'])));
                $noSpace = str_replace([' ', '-', '_'], '', $cleanClassStr);

                foreach ($classes as $c) {
                    $fullC1 = strtolower(trim($c->kelas_nomor . ' ' . $c->nama_kelas . ' ' . ($c->nomor_kelas ?? '')));
                    $fullC2 = strtolower(trim($c->kelas_nomor . ' ' . $c->nama_kelas . ($c->nomor_kelas ?? '')));
                    $ns1 = str_replace([' ', '-', '_'], '', $fullC1);
                    $ns2 = str_replace([' ', '-', '_'], '', $fullC2);

                    if ($cleanClassStr === $fullC1 || $cleanClassStr === $fullC2 || $noSpace === $ns1 || $noSpace === $ns2) {
                        $matchedClassId = $c->id_class;
                        break;
                    }
                }

                if (!$matchedClassId) {
                    $errors[] = "Baris {$rowNum} ({$item['fullname']}): Kelas binaan '{$item['kelas']}' tidak ditemukan di database.";
                    continue;
                }
            } else {
                $rawKelasBk = trim($item['kelas'] ?? '');
                if (!empty($rawKelasBk) && $rawKelasBk !== '-') {
                    $parts = array_map('trim', explode(',', $rawKelasBk));
                    foreach ($parts as $p) {
                        $cleanP = strtolower(preg_replace('/\s+/', ' ', $p));
                        $noSpaceP = str_replace([' ', '-', '_'], '', $cleanP);

                        foreach ($classes as $c) {
                            $fullC1 = strtolower(trim($c->kelas_nomor . ' ' . $c->nama_kelas . ' ' . ($c->nomor_kelas ?? '')));
                            $fullC2 = strtolower(trim($c->kelas_nomor . ' ' . $c->nama_kelas . ($c->nomor_kelas ?? '')));
                            $ns1 = str_replace([' ', '-', '_'], '', $fullC1);
                            $ns2 = str_replace([' ', '-', '_'], '', $fullC2);

                            if ($cleanP === $fullC1 || $cleanP === $fullC2 || $noSpaceP === $ns1 || $noSpaceP === $ns2) {
                                $bkClassIds[] = $c->id_class;
                                break;
                            }
                        }
                    }
                }
            }

            // Cek apakah data guru sudah terdaftar di sistem (berdasarkan NIP, Email, atau Username)
            $existingTeacher = null;
            $cleanNip = (!empty($item['nip']) && trim($item['nip']) !== '-') ? trim($item['nip']) : null;

            if ($cleanNip) {
                $existingTeacher = User::where('nip', $cleanNip)->first();
            }
            if (!$existingTeacher && !empty($email)) {
                $existingTeacher = User::where('email', $email)->first();
            }
            if (!$existingTeacher && !empty($item['username'])) {
                $existingTeacher = User::where('username', trim($item['username']))->first();
            }

            if ($existingTeacher) {
                // UPDATE GURU YANG SUDAH ADA (Upsert)
                $existingTeacher->fullname = trim($item['fullname']);
                $existingTeacher->title    = $title;
                $existingTeacher->id_role  = $roleId;
                if ($matchedClassId) {
                    $existingTeacher->id_class = $matchedClassId;
                }
                if (!empty($item['password']) && trim($item['password']) !== '') {
                    $existingTeacher->password = Hash::make(trim($item['password']));
                }
                $existingTeacher->save();

                if (!empty($bkClassIds)) {
                    $existingTeacher->counselorClasses()->sync($bkClassIds);
                }

                $updatedCount++;
                continue;
            }

            // Jika guru baru, cek keunikan email & username
            if ($this->isEmailTakenByActiveUser($email)) {
                $errors[] = "Baris {$rowNum}: Email '{$email}' sudah terdaftar pada akun aktif lain.";
                continue;
            }

            if (User::where('username', trim($item['username']))->exists()) {
                $errors[] = "Baris {$rowNum}: Username '{$item['username']}' sudah digunakan akun lain.";
                continue;
            }

            $plainPassword = !empty($item['password']) ? trim($item['password']) : 'Peh_1234';

            $user = User::create([
                'fullname' => trim($item['fullname']),
                'nip'      => $cleanNip,
                'username' => trim($item['username']),
                'email'    => $email,
                'password' => Hash::make($plainPassword),
                'id_role'  => $roleId,
                'title'    => $title,
                'id_class' => $matchedClassId
            ]);

            if (!empty($bkClassIds)) {
                $user->counselorClasses()->sync($bkClassIds);
            }

            $importedCount++;
        }

        $totalProcessed = $importedCount + $updatedCount;

        if ($totalProcessed === 0 && count($errors) > 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Seluruh baris gagal diimpor karena kendala data berikut:',
                'errors'  => $errors
            ], 422);
        }

        $msgParts = [];
        if ($importedCount > 0) $msgParts[] = "{$importedCount} guru baru berhasil ditambahkan";
        if ($updatedCount > 0) $msgParts[] = "{$updatedCount} data guru berhasil diperbarui";
        $summaryMsg = !empty($msgParts) ? "Berhasil memproses data guru: " . implode(', ', $msgParts) . "." : "Proses impor selesai.";

        return response()->json([
            'status'         => 'success',
            'message'        => $summaryMsg,
            'imported_count' => $importedCount,
            'updated_count'  => $updatedCount,
            'errors'         => $errors
        ], 200);
    }

    public function store(Request $request)
    {
        $messages = [
            'nis.unique' => 'NIS sudah digunakan',
            'nisn.unique' => 'NISN sudah digunakan',
            'fullname.unique' => 'Fullname sudah digunakan',
            'username.unique' => 'Username sudah digunakan',
            'email.unique' => 'Email ini sudah digunakan oleh akun lain.',
        ];

        $validator = Validator::make($request->all(), [
            'fullname' => 'required|string|max:255|unique:users,fullname',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'id_role' => 'required|exists:roles,id_role',
            'id_class' => 'nullable|exists:classes,id_class',
            'username' => 'nullable|string|max:100|unique:users,username',
            'nis' => 'nullable|string|max:50|unique:users,nis',
            'nisn' => 'nullable|string|max:50|unique:users,nisn',
            'nip' => 'nullable|string|max:50|unique:users,nip',
            'title' => 'nullable|string|max:100',
        ], $messages);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();
        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'User berhasil ditambahkan',
            'data' => $user->load(['role', 'schoolClass'])
        ], 201);
    }

    public function storeStudentByWaka(Request $request)
    {
        if ($request->filled('email')) {
            $this->releaseArchivedEmailCollisions((string) $request->email);
            if ($this->isEmailTakenByActiveUser((string) $request->email)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Email ini sudah digunakan oleh akun lain.'
                ], 422);
            }
        }

        $messages = [
            'nis.unique' => 'NIS sudah digunakan',
            'nisn.unique' => 'NISN sudah digunakan',
            'username.unique' => 'Username sudah digunakan',
            'email.unique' => 'Email ini sudah digunakan oleh akun lain.',
        ];

        $validator = Validator::make($request->all(), [
            'fullname' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'username' => 'nullable|string|max:100|unique:users,username',
            'no_telp' => 'nullable|string|max:30',
            'nis' => 'nullable|string|max:50|unique:users,nis',
            'nisn' => 'nullable|string|max:50|unique:users,nisn',
            'id_class' => 'nullable|exists:classes,id_class',
            'password' => 'required|string|min:8'
        ], $messages);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();
        $data['id_role'] = 4;
        $data['password'] = Hash::make($data['password']);

        $student = User::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Data siswa baru berhasil ditambahkan',
            'data' => $student->load(['role', 'schoolClass'])
        ], 201);
    }

    public function importStudentsBatch(Request $request)
    {
        @set_time_limit(300);

        // Validasi payload (tanpa dns dan tanpa min:8 agar fleksibel untuk semua format password)
        $request->validate([
            'items'            => 'required|array|min:1',
            'items.*.kelas'    => 'required|string',
            'items.*.fullname' => 'required|string|max:255',
            'items.*.username' => 'required|string|max:100',
            'items.*.email'    => 'required|string|email|max:255',
            'items.*.password' => 'nullable|string',
            'items.*.nis'      => 'nullable|string|max:50',
            'items.*.nisn'     => 'nullable|string|max:50',
        ]);

        $importedCount = 0;
        $updatedCount = 0;
        $errors = [];

        // 2. KUNCI OPTIMASI #1: Muat seluruh kelas ke memory RAM dengan berbagai variasi penamaan
        $classes = SchoolClass::all();
        $classMap = [];
        foreach ($classes as $c) {
            $nomorStr = trim((string)($c->nomor_kelas ?? ''));
            $c1 = strtolower(trim($c->kelas_nomor . ' ' . $c->nama_kelas . ' ' . $nomorStr));
            $c2 = strtolower(trim($c->kelas_nomor . ' ' . $c->nama_kelas . $nomorStr));
            $c3 = strtolower(trim($c->kelas_nomor . ' ' . $c->nama_kelas));
            $classMap[$c1] = $c->id_class;
            $classMap[$c2] = $c->id_class;
            if (!isset($classMap[$c3])) $classMap[$c3] = $c->id_class;
            $classMap[str_replace([' ', '-', '_'], '', $c1)] = $c->id_class;
            $classMap[str_replace([' ', '-', '_'], '', $c2)] = $c->id_class;
        }

        // Ambil data siswa aktif di database ke array PHP untuk cek Upsert (Update jika sudah ada)
        $activeStudents = User::where('id_role', 4)
            ->whereRaw("LOWER(TRIM(COALESCE(title, ''))) NOT IN ('dikeluarkan', 'pensiun', 'alumni', 'lulus')")
            ->get();

        $studentByNis = [];
        $studentByNisn = [];
        $studentByEmail = [];
        $studentByUsername = [];

        foreach ($activeStudents as $st) {
            if (!empty($st->nis) && trim($st->nis) !== '-') {
                $studentByNis[trim($st->nis)] = $st;
            }
            if (!empty($st->nisn) && trim($st->nisn) !== '-') {
                $studentByNisn[trim($st->nisn)] = $st;
            }
            if (!empty($st->email)) {
                $studentByEmail[strtolower(trim($st->email))] = $st;
            }
            if (!empty($st->username)) {
                $studentByUsername[strtolower(trim($st->username))] = $st;
            }
        }

        // Ambil email/username dari role lain (Waka, Guru, Wali Kelas, BK) untuk proteksi tabrakan
        $otherActiveUsers = User::where('id_role', '!=', 4)
            ->whereRaw("LOWER(TRIM(COALESCE(title, ''))) NOT IN ('dikeluarkan', 'pensiun', 'alumni', 'lulus')")
            ->get();
        $otherEmails = array_flip($otherActiveUsers->pluck('email')->filter()->map(fn($e) => strtolower(trim($e)))->toArray());
        $otherUsernames = array_flip($otherActiveUsers->pluck('username')->filter()->map(fn($u) => strtolower(trim($u)))->toArray());

        $rowsToInsert = [];
        $now = now();

        foreach ($request->items as $idx => $item) {
            $rowNum = $idx + 2;

            $rawKelas = strtolower(trim($item['kelas'] ?? ''));
            $cleanClassStr = strtolower(preg_replace('/\s+/', ' ', $rawKelas));
            $noSpaceClass = str_replace([' ', '-', '_'], '', $rawKelas);

            $matchedClassId = $classMap[$cleanClassStr] ?? ($classMap[$noSpaceClass] ?? null);

            if (!$matchedClassId) {
                $errors[] = "Baris {$rowNum} ({$item['fullname']}): Kelas '{$item['kelas']}' tidak terdaftar di sistem.";
                continue;
            }

            $cleanEmail = strtolower(trim($item['email'] ?? ''));
            $this->releaseArchivedEmailCollisions($cleanEmail);

            $cleanUser  = strtolower(trim($item['username'] ?? ''));
            $cleanNis   = (!empty($item['nis']) && trim($item['nis']) !== '-') ? trim($item['nis']) : null;
            $cleanNisn  = (!empty($item['nisn']) && trim($item['nisn']) !== '-') ? trim($item['nisn']) : null;

            // Cek apakah siswa ini sudah ada di database (Upsert)
            $existingStudent = null;
            if ($cleanNis && isset($studentByNis[$cleanNis])) {
                $existingStudent = $studentByNis[$cleanNis];
            } elseif ($cleanNisn && isset($studentByNisn[$cleanNisn])) {
                $existingStudent = $studentByNisn[$cleanNisn];
            } elseif ($cleanEmail && isset($studentByEmail[$cleanEmail])) {
                $existingStudent = $studentByEmail[$cleanEmail];
            } elseif ($cleanUser && isset($studentByUsername[$cleanUser])) {
                $existingStudent = $studentByUsername[$cleanUser];
            }

            if ($existingStudent) {
                // UPDATE SISWA YANG SUDAH ADA
                $existingStudent->fullname = trim($item['fullname']);
                $existingStudent->id_class = $matchedClassId;
                if ($cleanNis)  $existingStudent->nis = $cleanNis;
                if ($cleanNisn) $existingStudent->nisn = $cleanNisn;
                if (!empty($item['password']) && trim($item['password']) !== '') {
                    $existingStudent->password = password_hash(trim($item['password']), PASSWORD_BCRYPT, ['cost' => 4]);
                }
                $existingStudent->save();

                // Perbarui index memory
                if ($cleanNis)  $studentByNis[$cleanNis] = $existingStudent;
                if ($cleanNisn) $studentByNisn[$cleanNisn] = $existingStudent;
                if ($cleanEmail) $studentByEmail[$cleanEmail] = $existingStudent;
                if ($cleanUser)  $studentByUsername[$cleanUser] = $existingStudent;

                $updatedCount++;
                continue;
            }

            // Validasi jika akun baru tetapi email/username bentrok dengan pegawai (guru/waka/bk)
            if (isset($otherEmails[$cleanEmail])) {
                $errors[] = "Baris {$rowNum} ({$item['fullname']}): Email '{$cleanEmail}' telah digunakan oleh akun staf/guru.";
                continue;
            }

            if (isset($otherUsernames[$cleanUser])) {
                $errors[] = "Baris {$rowNum} ({$item['fullname']}): Username '{$cleanUser}' telah digunakan oleh akun staf/guru.";
                continue;
            }

            // Jika username atau email sudah pernah diantrekan di batch ini, buat variasi unik otomatis
            if (isset($studentByUsername[$cleanUser])) {
                $cleanUser = $cleanUser . '_' . ($cleanNis ?? rand(100, 999));
            }
            if (isset($studentByEmail[$cleanEmail])) {
                $cleanEmail = ($cleanNis ?? rand(100, 999)) . '_' . $cleanEmail;
            }

            $plainPass = !empty($item['password']) ? trim($item['password']) : 'Peh_1234';
            $pass = password_hash($plainPass, PASSWORD_BCRYPT, ['cost' => 4]);

            $rowsToInsert[] = [
                'fullname'   => trim($item['fullname']),
                'username'   => $cleanUser,
                'email'      => $cleanEmail,
                'password'   => $pass,
                'nis'        => $cleanNis,
                'nisn'       => $cleanNisn,
                'id_class'   => $matchedClassId,
                'id_role'    => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Daftarkan ke memory agar baris berikutnya di batch yang sama tidak bentrok
            $studentByUsername[$cleanUser] = true;
            $studentByEmail[$cleanEmail] = true;
            if ($cleanNis)  $studentByNis[$cleanNis] = true;
            if ($cleanNisn) $studentByNisn[$cleanNisn] = true;

            $importedCount++;

            if (count($rowsToInsert) >= 200) {
                User::insert($rowsToInsert);
                $rowsToInsert = [];
            }
        }

        // Masukkan sisa baris terakhir
        if (!empty($rowsToInsert)) {
            User::insert($rowsToInsert);
        }

        $totalProcessed = $importedCount + $updatedCount;

        if ($totalProcessed === 0 && count($errors) > 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Seluruh baris gagal diimpor karena kendala data berikut:',
                'errors'  => $errors
            ], 422);
        }

        $msgParts = [];
        if ($importedCount > 0) $msgParts[] = "{$importedCount} siswa baru berhasil ditambahkan";
        if ($updatedCount > 0) $msgParts[] = "{$updatedCount} data siswa berhasil diperbarui";
        $summaryMsg = !empty($msgParts) ? "Berhasil memproses data siswa: " . implode(', ', $msgParts) . "." : "Proses impor selesai.";

        return response()->json([
            'status'         => 'success',
            'message'        => $summaryMsg,
            'imported_count' => $importedCount,
            'updated_count'  => $updatedCount,
            'errors'         => $errors
        ], 200);
    }

    public function show($id)
    {
        $user = User::with(['role', 'schoolClass', 'counselorClasses', 'parents', 'linkedStudents.schoolClass'])->find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User tidak ditemukan'
            ], 404);
        }

        if (strtolower(str_replace(' ', '', trim($user->title ?? ''))) === 'gurubkpengajar') {
            $user->title = 'Guru BK';
        }

        if ($user->counselorClasses && $user->counselorClasses->count() > 0) {
            $user->id_class = $user->counselorClasses->pluck('id_class')->implode(',');
        }

        return response()->json([
            'status' => 'success',
            'data' => $user
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User tidak ditemukan'
            ], 404);
        }

        $messages = [
            'nis.unique' => 'NIS sudah digunakan',
            'nisn.unique' => 'NISN sudah digunakan',
            'fullname.unique' => 'Fullname sudah digunakan',
            'username.unique' => 'Username sudah digunakan',
            'email.unique' => 'Email ini sudah digunakan oleh akun lain.',
        ];

        $validator = Validator::make($request->all(), [
            'fullname' => 'sometimes|required|string|max:255|unique:users,fullname,' . $id . ',id_user',
            'email' => 'sometimes|required|string|email:rfc,dns|max:255|unique:users,email,' . $id . ',id_user',
            'password' => 'nullable|string|min:8',
            'id_role' => 'sometimes|required|exists:roles,id_role',
            'id_class' => 'nullable|exists:classes,id_class',
            'username' => 'nullable|string|max:100|unique:users,username,' . $id . ',id_user',
            'no_telp' => 'nullable|string|max:30',
            'nis' => 'nullable|string|max:50|unique:users,nis,' . $id . ',id_user',
            'nisn' => 'nullable|string|max:50|unique:users,nisn,' . $id . ',id_user',
            'nip' => 'nullable|string|max:50|unique:users,nip,' . $id . ',id_user',
            'title' => 'nullable|string|max:100',
        ], $messages);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        if (empty($validated['password'])) {
            unset($validated['password']);
        } else {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        // Sinkronisasi email ke akun orang tua jika yang diubah adalah akun siswa
        if (!empty($validated['email']) && (int) $user->id_role === 4 && strtolower(trim($user->title ?? '')) !== 'orang tua') {
            $newStudentEmail = strtolower(trim($validated['email']));
            foreach ($user->parents()->get() as $parentAccount) {
                $parentAccount->email = $newStudentEmail;
                $parentAccount->save();
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Data user berhasil diperbarui',
            'data' => $user->load(['role', 'schoolClass', 'counselorClasses', 'parents', 'linkedStudents.schoolClass'])
        ], 200);
    }

    public function destroy($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User tidak ditemukan'
            ], 404);
        }

        $user->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'User berhasil dihapus'
        ], 200);
    }

    public function sendWakaOtp(Request $request)
    {
        $wakaUser = $request->user();

        if (!$wakaUser || !$wakaUser->email) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email Waka/Admin tidak ditemukan.'
            ], 400);
        }

        $type = $request->input('type', 'password');
        $targetUserId = $request->input('current_user_id', 'general');

        if ($type === 'new_email' && $request->filled('new_email')) {
            $newEmail = strtolower(trim($request->input('new_email')));

            if ($this->isEmailTakenByActiveUser($newEmail, $targetUserId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Email ini sudah terdaftar/digunakan oleh akun lain.'
                ], 422);
            }
        }

        $otp = sprintf('%06d', random_int(0, 999999));
        $cacheKey = "waka_edit_otp_{$type}_{$wakaUser->id_user}_{$targetUserId}";

        Cache::put($cacheKey, [
            'code' => (string) $otp,
            'type' => $type,
            'target_user_id' => $targetUserId,
            'new_email' => $request->input('new_email'),
            'expires_at' => now()->addMinutes(10)->timestamp
        ], now()->addMinutes(10));

        try {
            $subject = ($type === 'new_email') 
                ? 'Kode Verifikasi OTP Ubah Email Akun' 
                : 'Kode Verifikasi OTP Ubah Password Akun';

            Mail::raw(
                "Halo {$wakaUser->fullname},\n\n" .
                "Kode verifikasi OTP Anda untuk aksi (" . strtoupper($type) . ") adalah: {$otp}\n\n" .
                "Kode ini berlaku selama 10 menit.",
                function ($message) use ($wakaUser, $subject) {
                    $message->to($wakaUser->email)
                        ->subject($subject);
                }
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Kode OTP berhasil dikirim ke email Waka/Admin.'
            ], 200);
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengirim email OTP. Silakan coba lagi.'
            ], 500);
        }
    }

    public function verifyWakaOtp(Request $request)
    {
        $request->validate([
            'otp' => 'required|string|digits:6'
        ]);

        $wakaUser = $request->user();
        $type = $request->input('type', 'password');
        $targetUserId = $request->input('current_user_id', 'general');

        $cacheKey = "waka_edit_otp_{$type}_{$wakaUser->id_user}_{$targetUserId}";
        $cachedOtpData = Cache::get($cacheKey);

        if (!$cachedOtpData || now()->timestamp > $cachedOtpData['expires_at']) {
            return response()->json([
                'status' => 'expired',
                'message' => 'Kode verifikasi kadaluarsa, silakan masukkan kode baru.'
            ], 422);
        }

        if ((string) $cachedOtpData['code'] !== (string) $request->otp) {
            return response()->json([
                'status' => 'invalid',
                'message' => 'Kode verifikasi OTP salah'
            ], 422);
        }

        Cache::put("waka_verified_{$type}_{$wakaUser->id_user}_{$targetUserId}", (string)$request->otp, now()->addMinutes(15));

        return response()->json([
            'status' => 'success',
            'message' => 'Verifikasi OTP berhasil!'
        ], 200);
    }

    public function updateStudentByWaka(Request $request, $id)
    {
        $student = User::where('id_role', 4)->find($id);

        if (!$student) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data siswa tidak ditemukan'
            ], 404);
        }

        $wakaUser = $request->user();
        $hasEmailChange = $request->filled('email') && strtolower(trim($request->email)) !== strtolower(trim($student->email));
        $hasPasswordChange = $request->filled('password');

        if ($hasPasswordChange) {
            $submittedOtp = $request->input('otp_password', $request->input('otp'));
            $verifiedPassToken = Cache::get("waka_verified_password_{$wakaUser->id_user}_{$id}");
            $cacheKey = "waka_edit_otp_password_{$wakaUser->id_user}_{$id}";
            $cachedData = Cache::get($cacheKey);

            $isValidPasswordOtp = ($verifiedPassToken && (!$submittedOtp || $submittedOtp === $verifiedPassToken))
                || ($cachedData && (string)$cachedData['code'] === (string)$submittedOtp);

            if (!$isValidPasswordOtp) {
                return response()->json([
                    'status' => 'invalid',
                    'message' => 'Kode verifikasi salah'
                ], 422);
            }

            Cache::forget("waka_verified_password_{$wakaUser->id_user}_{$id}");
            Cache::forget($cacheKey);
        }

        if ($hasEmailChange) {
            if ($this->isEmailTakenByActiveUser((string) $request->email, $id)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Email ini sudah digunakan oleh akun lain.'
                ], 422);
            }

            $submittedOtp = $request->input('otp_email', $request->input('otp'));
            $verifiedEmailToken = Cache::get("waka_verified_new_email_{$wakaUser->id_user}_{$id}");
            $cacheKey = "waka_edit_otp_new_email_{$wakaUser->id_user}_{$id}";
            $cachedData = Cache::get($cacheKey);

            $isValidEmailOtp = ($verifiedEmailToken && (!$submittedOtp || $submittedOtp === $verifiedEmailToken))
                || ($cachedData && (string)$cachedData['code'] === (string)$submittedOtp);

            if (!$isValidEmailOtp) {
                return response()->json([
                    'status' => 'invalid',
                    'message' => 'Kode verifikasi email salah atau belum diverifikasi'
                ], 422);
            }

            Cache::forget("waka_verified_new_email_{$wakaUser->id_user}_{$id}");
            Cache::forget($cacheKey);
        }

        $messages = [
            'fullname.unique' => 'Fullname sudah digunakan',
            'username.unique' => 'Username sudah digunakan',
            'email.unique' => 'Email ini sudah digunakan oleh akun lain.',
        ];

        $validator = Validator::make($request->all(), [
            'fullname' => 'sometimes|required|string|max:255|unique:users,fullname,' . $id . ',id_user',
            'username' => 'nullable|string|max:100|unique:users,username,' . $id . ',id_user',
            'no_telp' => 'nullable|string|max:30',
            'email' => 'sometimes|required|string|email|max:255',
            'id_class' => 'nullable|exists:classes,id_class',
            'password' => 'nullable|string|min:8'
        ], $messages);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        if (!$hasEmailChange) {
            unset($data['email']);
        }

        unset($data['nis'], $data['nisn']);
        $student->update($data);

        // Sinkronisasi email ke akun orang tua jika siswa mengganti email (termasuk jika orang tua mengampu >1 siswa)
        if ($hasEmailChange && !empty($student->email) && strtolower(trim($student->title ?? '')) !== 'orang tua') {
            $newStudentEmail = strtolower(trim($student->email));
            foreach ($student->parents()->get() as $parentAccount) {
                $parentAccount->email = $newStudentEmail;
                $parentAccount->save();
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Data siswa berhasil diperbarui',
            'data' => $student->load(['role', 'schoolClass', 'parents', 'linkedStudents.schoolClass'])
        ], 200);
    }

    public function promoteClasses(Request $request)
    {
        $userIds = $request->input('student_ids', $request->input('user_ids', []));

        if (empty($userIds)) {
            return response()->json([
                'status' => 'success',
                'message' => 'Tidak ada siswa yang dipilih untuk kenaikan kelas.'
            ], 200);
        }

        $students = User::whereIn('id_user', $userIds)
            ->where('id_role', 4)
            ->with('schoolClass')
            ->get();

        $allClasses = SchoolClass::all();
        $promotedCount = 0;
        $graduatedCount = 0;

        foreach ($students as $student) {
            $currentClass = $student->schoolClass;
            if (!$currentClass) continue;

            $currentTingkat = strtoupper(trim($currentClass->kelas_nomor));

            if ($currentTingkat === 'XII') {
                // Simpan snapshot untuk batas undo 5 menit (300 detik)
                Cache::put("archive_snapshot_{$student->id_user}", [
                    'id_class'    => $student->id_class,
                    'email'       => $student->email,
                    'password'    => $student->password,
                    'title'       => $student->title,
                    'archived_at' => now()->timestamp,
                ], 300);

                $student->id_class = null;
                $student->title = 'alumni';
                if ($student->email && !str_starts_with($student->email, 'archived_')) {
                    $student->email = 'archived_' . $student->id_user . '_' . time() . '_' . $student->email;
                }
                $student->save();
                $graduatedCount++;
            } elseif ($currentTingkat === 'X') {
                $targetClass = $allClasses->first(function ($c) use ($currentClass) {
                    return strtoupper(trim($c->kelas_nomor)) === 'XI' &&
                           strcasecmp(trim($c->nama_kelas), trim($currentClass->nama_kelas)) === 0 &&
                           trim((string)$c->nomor_kelas) === trim((string)$currentClass->nomor_kelas);
                });

                if ($targetClass) {
                    $student->id_class = $targetClass->id_class;
                    $student->save();
                    $promotedCount++;
                }
            } elseif ($currentTingkat === 'XI') {
                $targetClass = $allClasses->first(function ($c) use ($currentClass) {
                    return strtoupper(trim($c->kelas_nomor)) === 'XII' &&
                           strcasecmp(trim($c->nama_kelas), trim($currentClass->nama_kelas)) === 0 &&
                           trim((string)$c->nomor_kelas) === trim((string)$currentClass->nomor_kelas);
                });

                if ($targetClass) {
                    $student->id_class = $targetClass->id_class;
                    $student->save();
                    $promotedCount++;
                }
            }
        }

        $msg = "Proses kenaikan kelas berhasil dijalankan.";
        if ($promotedCount > 0) $msg .= " {$promotedCount} siswa berhasil dinaikkan kelas.";
        if ($graduatedCount > 0) $msg .= " {$graduatedCount} siswa kelas XII berhasil dinyatakan lulus dan dipindahkan ke data kelulusan.";

        return response()->json([
            'status'          => 'success',
            'message'         => $msg,
            'promoted_count'  => $promotedCount,
            'graduated_count' => $graduatedCount,
        ], 200);
    }

    public function sendOtp(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pengguna tidak ditemukan.'
            ], 400);
        }

        $target = $request->input('target', 'siswa');
        $studentId = $request->input('student_id');
        $isParent = strtolower(trim($user->title ?? '')) === 'orang tua';

        $recipientEmail = $user->email;
        $recipientName = $user->fullname;

        if ((int) $user->id_role === 4) {
            if ($isParent) {
                $linkedStudents = $user->linkedStudents()->get();
                $targetStudent = $studentId
                    ? $linkedStudents->firstWhere('id_user', (int) $studentId)
                    : $linkedStudents->first();
                if ($targetStudent && $targetStudent->email) {
                    $recipientEmail = $targetStudent->email;
                }
            } else {
                if ($target === 'ortu' && $user->parents()->count() === 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Akun orang tua belum dibuat. Silakan buat akun orang tua terlebih dahulu.'
                    ], 422);
                }
                $recipientEmail = $user->email;
            }
        }

        if (!$recipientEmail) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email siswa tidak ditemukan.'
            ], 400);
        }

        $otp = sprintf('%06d', random_int(0, 999999));
        $cacheKey = 'otp_user_' . $user->id_user;

        Cache::put($cacheKey, [
            'code' => (string) $otp,
            'target' => $target,
            'student_id' => $studentId,
            'expires_at' => now()->addMinutes(5)->timestamp
        ], now()->addMinutes(5));

        $targetLabel = ($target === 'ortu') ? 'Akun Orang Tua' : 'Akun Siswa';

        try {
            Mail::raw(
                "Halo {$recipientName},\n\n" .
                "Kode verifikasi OTP ubah password ({$targetLabel}) Anda adalah: {$otp}\n\n" .
                "Kode ini berlaku selama 5 menit.",
                function ($message) use ($recipientEmail) {
                    $message->to($recipientEmail)
                        ->subject('Kode Verifikasi OTP Ubah Password');
                }
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Kode OTP berhasil dikirim ke email siswa.'
            ], 200);
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengirim OTP. Silakan coba lagi.'
            ], 500);
        }
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'otp' => 'required|string|digits:6'
        ]);

        $user = $request->user();
        $cacheKey = 'otp_user_' . $user->id_user;
        $cachedOtpData = Cache::get($cacheKey);

        if (!$cachedOtpData) {
            return response()->json([
                'status' => 'expired',
                'message' => 'Kode verifikasi kadaluarsa.'
            ], 422);
        }

        $code = is_array($cachedOtpData) ? ($cachedOtpData['code'] ?? null) : $cachedOtpData;

        if ((string) $code !== (string) $request->otp) {
            return response()->json([
                'status' => 'invalid',
                'message' => 'Kode verifikasi salah'
            ], 422);
        }

        Cache::put('profile_verified_' . $user->id_user, $cachedOtpData, now()->addMinutes(15));
        Cache::forget($cacheKey);

        return response()->json([
            'status' => 'success',
            'message' => 'Verifikasi berhasil!',
            'target' => is_array($cachedOtpData) ? ($cachedOtpData['target'] ?? 'siswa') : 'siswa'
        ], 200);
    }

    public function changeEmail(Request $request)
    {
        $user = $request->user();

        if (!$user || !$user->email) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email pengguna tidak ditemukan.'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255'
        ], [
            'email.required' => 'Email harus diisi.',
            'email.email' => 'Silahkan masukkan format email (@...) yang benar.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        $newEmail = strtolower(trim($request->email));

        if ($newEmail === strtolower(trim($user->email))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email baru tidak boleh sama dengan email saat ini.'
            ], 422);
        }

        if ($this->isEmailTakenByActiveUser($newEmail, $user->id_user)) {
            return response()->json([
                'status' => 'already_taken',
                'message' => 'Email ini sudah digunakan oleh akun lain.'
            ], 422);
        }

        $otp = sprintf('%06d', random_int(0, 999999));
        $cacheKey = 'change_email_otp_' . $user->id_user;

        Cache::put($cacheKey, [
            'new_email' => $newEmail,
            'code' => (string) $otp,
            'expires_at' => now()->addMinutes(5)->timestamp
        ], now()->addMinutes(5));

        try {
            Mail::raw(
                "Halo {$user->fullname},\n\n" .
                "Anda meminta perubahan email ke: {$newEmail}.\n" .
                "Kode OTP Anda adalah: {$otp}",
                function ($message) use ($user) {
                    $message->to($user->email)
                        ->subject('Kode Verifikasi Perubahan Email');
                }
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Kode verifikasi telah dikirim.'
            ], 200);
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengirim OTP.'
            ], 500);
        }
    }

    public function verifyNewEmailOtp(Request $request)
    {
        $request->validate([
            'otp' => 'required|string|digits:6'
        ]);

        $user = $request->user();
        $cacheKey = 'change_email_otp_' . $user->id_user;
        $cachedData = Cache::get($cacheKey);

        if (!$cachedData || now()->timestamp > $cachedData['expires_at']) {
            Cache::forget($cacheKey);
            return response()->json([
                'status' => 'expired',
                'message' => 'Kode verifikasi kadaluarsa.'
            ], 422);
        }

        $code = is_array($cachedData) ? ($cachedData['code'] ?? null) : $cachedData;

        if ((string) $code !== (string) $request->otp) {
            return response()->json([
                'status' => 'invalid',
                'message' => 'Kode verifikasi salah'
            ], 422);
        }

        $newEmail = strtolower(trim($cachedData['new_email']));

        $this->releaseArchivedEmailCollisions($newEmail, $user->id_user);

        $user->email = $newEmail;
        $user->save();

        // Sinkronisasi email ke akun orang tua jika siswa mengganti email (termasuk jika orang tua mengampu 2 siswa)
        if ((int) $user->id_role === 4 && strtolower(trim($user->title ?? '')) !== 'orang tua') {
            foreach ($user->parents()->get() as $parentAccount) {
                $parentAccount->email = $newEmail;
                $parentAccount->save();
            }
        }

        Cache::forget($cacheKey);

        return response()->json([
            'status' => 'success',
            'message' => 'Email telah diperbarui.',
            'data' => $user->load(['role', 'schoolClass', 'parents', 'linkedStudents.schoolClass'])
        ], 200);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'username' => 'nullable|string|max:100|unique:users,username,' . $user->id_user . ',id_user',
            'no_telp' => 'nullable|string|max:30',
            'password' => 'nullable|string|min:8',
            'password_target' => 'nullable|string|in:siswa,ortu,self',
            'student_id' => 'nullable|integer',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->has('username') && $request->username) {
            $user->username = $request->username;
        }

        if ($request->has('no_telp')) {
            $valTelp = trim((string) $request->no_telp);
            $user->no_telp = $valTelp !== '' ? $valTelp : null;
        }

        if ($request->has('password') && $request->password) {
            $newHashedPassword = Hash::make($request->password);
            $passwordTarget = $request->input('password_target', 'self');
            $isParent = strtolower(trim($user->title ?? '')) === 'orang tua';

            if ((int) $user->id_role === 4 && $passwordTarget === 'ortu' && !$isParent) {
                $parentAccount = $user->parents()->first();
                if (!$parentAccount) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Akun orang tua belum terhubung.'
                    ], 422);
                }
                $parentAccount->password = $newHashedPassword;
                $parentAccount->save();
            } elseif ((int) $user->id_role === 4 && $passwordTarget === 'siswa' && $isParent) {
                $studentId = $request->input('student_id');
                $linkedStudents = $user->linkedStudents()->get();
                $targetStudent = $studentId
                    ? $linkedStudents->firstWhere('id_user', (int) $studentId)
                    : $linkedStudents->first();
                if (!$targetStudent) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Akun siswa tidak ditemukan.'
                    ], 422);
                }
                $targetStudent->password = $newHashedPassword;
                $targetStudent->save();
            } else {
                $user->password = $newHashedPassword;
            }
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
            $user->avatar = $request->file('avatar')->store('avatars', 'public');
        }

        $user->save();

        $freshUser = $user->load(['role', 'schoolClass', 'parents', 'linkedStudents.schoolClass']);
        if (strtolower(trim($freshUser->title ?? '')) === 'orang tua' && !$freshUser->schoolClass && $freshUser->linkedStudents->count() > 0) {
            $firstChild = $freshUser->linkedStudents->first();
            if ($firstChild && $firstChild->schoolClass) {
                $freshUser->setRelation('schoolClass', $firstChild->schoolClass);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Profil berhasil diperbarui',
            'data' => $freshUser
        ], 200);
    }

    public function getParentAccountStatus(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $isParent = strtolower(trim($user->title ?? '')) === 'orang tua';
        if ($isParent) {
            $user->load(['role', 'schoolClass', 'linkedStudents.schoolClass']);
            return response()->json([
                'status' => 'success',
                'is_parent' => true,
                'has_parent' => true,
                'linked_students' => $user->linkedStudents,
                'user' => $user
            ], 200);
        }

        $user->load(['role', 'schoolClass', 'parents']);
        return response()->json([
            'status' => 'success',
            'is_parent' => false,
            'has_parent' => $user->parents->count() > 0,
            'parents' => $user->parents,
            'user' => $user
        ], 200);
    }

    public function checkParentAccount(Request $request)
    {
        $fullname = trim((string) $request->query('fullname', ''));
        if ($fullname === '') {
            return response()->json([
                'status' => 'success',
                'exists' => false
            ], 200);
        }

        $existingParent = User::where('id_role', 4)
            ->whereRaw("LOWER(TRIM(COALESCE(title, ''))) = 'orang tua'")
            ->whereRaw('LOWER(TRIM(fullname)) = ?', [strtolower($fullname)])
            ->first();

        if ($existingParent) {
            $student = $request->user();
            $alreadyLinked = false;
            if ($student) {
                $alreadyLinked = $student->parents()->where('users.id_user', $existingParent->id_user)->exists();
            }

            return response()->json([
                'status' => 'success',
                'exists' => true,
                'already_linked' => $alreadyLinked,
                'parent_id' => $existingParent->id_user,
                'fullname' => $existingParent->fullname,
                'parent' => [
                    'id_user' => $existingParent->id_user,
                    'fullname' => $existingParent->fullname,
                    'username' => $existingParent->username,
                    'no_telp' => $existingParent->no_telp,
                ]
            ], 200);
        }

        return response()->json([
            'status' => 'success',
            'exists' => false
        ], 200);
    }

    public function createParentAccount(Request $request)
    {
        $student = $request->user();
        if (!$student || (int) $student->id_role !== 4) {
            return response()->json([
                'status' => 'error',
                'message' => 'Hanya akun siswa yang dapat membuat akun orang tua.'
            ], 403);
        }

        $messages = [
            'fullname.required' => 'Fullname wajib diisi.',
            'username.required' => 'Username wajib diisi.',
            'username.unique' => 'Username sudah digunakan oleh akun lain.',
            'no_telp.required' => 'Nomor telepon wajib diisi.',
            'password.required' => 'Password wajib diisi.',
        ];

        $validator = Validator::make($request->all(), [
            'fullname' => 'required|string|max:255',
            'username' => 'required|string|max:100|unique:users,username',
            'no_telp' => 'required|string|max:30',
            'password' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    if (preg_match('/\s/', $value) ||
                        !preg_match('/[a-z]/', $value) ||
                        !preg_match('/[A-Z]/', $value) ||
                        !preg_match('/\d/', $value) ||
                        !preg_match('/[^a-zA-Z0-9\s]/', $value)) {
                        $fail('Password harus berisi simbol, angka, huruf besar dan huruf kecil, tanpa spasi.');
                    }
                }
            ]
        ], $messages);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $cleanPhone = trim($request->no_telp);
        $phoneTaken = User::where('no_telp', $cleanPhone)->exists();
        if ($phoneTaken) {
            return response()->json([
                'status' => 'error',
                'message' => 'Nomor telepon sudah digunakan oleh akun lain.',
                'errors' => ['no_telp' => ['Nomor telepon sudah digunakan oleh akun lain.']]
            ], 422);
        }

        $parent = User::create([
            'id_role' => 4,
            'id_class' => null,
            'title' => 'Orang Tua',
            'fullname' => trim($request->fullname),
            'username' => trim($request->username),
            'no_telp' => $cleanPhone,
            'email' => $student->email ?? '',
            'password' => Hash::make($request->password),
        ]);

        $student->parents()->syncWithoutDetaching([$parent->id_user]);

        return response()->json([
            'status' => 'success',
            'message' => 'Akun telah dibuat',
            'data' => $parent,
            'user' => $student->load(['role', 'schoolClass', 'parents'])
        ], 201);
    }

    public function linkParentAccount(Request $request)
    {
        $student = $request->user();
        if (!$student || (int) $student->id_role !== 4) {
            return response()->json([
                'status' => 'error',
                'message' => 'Hanya akun siswa yang dapat menghubungkan akun orang tua.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'parent_id' => 'nullable|integer',
            'fullname' => 'nullable|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Password akun orang tua wajib diisi.'
            ], 422);
        }

        $parent = null;

        if (!empty($request->parent_id)) {
            $parent = User::where('id_role', 4)
                ->whereRaw("LOWER(TRIM(COALESCE(title, ''))) = 'orang tua'")
                ->where('id_user', (int) $request->parent_id)
                ->first();
        }

        if (!$parent && !empty($request->fullname) && strtolower(trim($request->fullname)) !== 'undefined') {
            $fullname = strtolower(trim($request->fullname));
            $parent = User::where('id_role', 4)
                ->whereRaw("LOWER(TRIM(COALESCE(title, ''))) = 'orang tua'")
                ->whereRaw('LOWER(TRIM(fullname)) = ?', [$fullname])
                ->first();
        }

        if (!$parent) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akun orang tua tidak ditemukan.'
            ], 404);
        }

        if (!Hash::check($request->password, $parent->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Password akun orang tua tidak sesuai!'
            ], 422);
        }

        $student->parents()->syncWithoutDetaching([$parent->id_user]);

        return response()->json([
            'status' => 'success',
            'message' => 'Akun telah dibuat',
            'data' => $parent,
            'user' => $student->load(['role', 'schoolClass', 'parents'])
        ], 200);
    }

    /**
     * Mengambil daftar guru beserta mata pelajaran yang diampu.
     */
    public function getTeachersWithSubjects(Request $request)
    {
        // Pastikan kolom mapel ada di database
        if (!\Illuminate\Support\Facades\Schema::hasColumn('users', 'mapel')) {
            try {
                \Illuminate\Support\Facades\Schema::table('users', function ($table) {
                    $table->string('mapel', 150)->nullable()->after('title');
                });
            } catch (\Throwable $e) {}
        }

        $teachers = User::with(['role', 'schoolClass', 'counselorClasses'])
            ->where(function ($q) {
                $q->whereIn('id_role', [1, 2, 3])
                  ->orWhereNotNull('nip');
            })
            ->where(function ($q) {
                $q->whereNull('email')
                  ->orWhere('email', 'not like', '%@school.id');
            })
            ->where('fullname', '!=', '-')
            ->where(function ($q) {
                $q->whereNull('title')
                  ->orWhere('title', '!=', 'Pensiun');
            })
            ->orderBy('fullname', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $teachers
        ], 200);
    }

    /**
     * Memperbarui mata pelajaran seorang guru secara langsung.
     */
    public function updateTeacherSubject(Request $request, $id)
    {
        $teacher = User::find($id);
        if (!$teacher) {
            return response()->json([
                'status' => 'error',
                'message' => 'Guru tidak ditemukan.'
            ], 404);
        }

        // Pastikan kolom mapel ada
        if (!\Illuminate\Support\Facades\Schema::hasColumn('users', 'mapel')) {
            try {
                \Illuminate\Support\Facades\Schema::table('users', function ($table) {
                    $table->string('mapel', 150)->nullable()->after('title');
                });
            } catch (\Throwable $e) {}
        }

        $validated = $request->validate([
            'mapel' => 'nullable|string|max:150',
        ]);

        $teacher->mapel = !empty($validated['mapel']) ? trim($validated['mapel']) : null;
        $teacher->save();

        return response()->json([
            'status' => 'success',
            'message' => "Mata pelajaran untuk {$teacher->fullname} berhasil diperbarui.",
            'data' => $teacher
        ], 200);
    }

    /**
     * Mengimpor data nominatif pegawai / guru dari Excel/CSV untuk memetakan Guru ke Mapel.
     */
    public function importTeachersNominatif(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.nama' => 'required|string',
            'items.*.mapel' => 'nullable|string',
            'items.*.nip' => 'nullable|string',
        ]);

        // Pastikan kolom mapel ada
        if (!\Illuminate\Support\Facades\Schema::hasColumn('users', 'mapel')) {
            try {
                \Illuminate\Support\Facades\Schema::table('users', function ($table) {
                    $table->string('mapel', 150)->nullable()->after('title');
                });
            } catch (\Throwable $e) {}
        }

        $allTeachers = User::whereIn('id_role', [1, 2, 3])
            ->where(function ($q) {
                $q->whereNull('title')
                  ->orWhere('title', '!=', 'Pensiun');
            })
            ->get();

        $updatedCount = 0;
        $insertedCount = 0;
        $errors = [];

        foreach ($request->items as $idx => $row) {
            $rawName  = trim($row['nama'] ?? ($row['fullname'] ?? ''));
            $rawMapel = trim($row['mapel'] ?? '');
            $rawNip   = trim($row['nip'] ?? '');

            if (empty($rawName) || $rawName === '-') continue;

            $cleanNip = (!empty($rawNip) && $rawNip !== '-' && strlen($rawNip) > 5) ? $rawNip : null;

            // Cari guru yang cocok
            $matchedTeacher = null;

            if ($cleanNip) {
                $matchedTeacher = $allTeachers->firstWhere('nip', $cleanNip);
            }

            if (!$matchedTeacher) {
                // Bersihkan nama dari gelar untuk pencarian fleksibel
                $normalizeName = function($name) {
                    $n = strtolower($name);
                    $n = preg_replace('/[,.]/', ' ', $n);
                    $n = preg_replace('/\b(drs|dra|dr|h|hj|s\.pd|s\.pd\.i|s\.t|s\.kom|s\.e|s\.ip|m\.pd|m\.m|m\.eng|m\.acc|ss)\b/i', '', $n);
                    $n = preg_replace('/\s+/', ' ', trim($n));
                    return $n;
                };

                $cleanSearch = $normalizeName($rawName);

                $matchedTeacher = $allTeachers->first(function ($t) use ($rawName, $cleanSearch, $normalizeName) {
                    if (strtolower(trim($t->fullname)) === strtolower(trim($rawName))) return true;
                    $cleanTarget = $normalizeName($t->fullname ?? '');
                    return !empty($cleanSearch) && ($cleanSearch === $cleanTarget || str_contains($cleanTarget, $cleanSearch) || str_contains($cleanSearch, $cleanTarget));
                });
            }

            if ($matchedTeacher) {
                // Update guru yang sudah ada
                if (!empty($rawMapel)) {
                    $matchedTeacher->mapel = $rawMapel;
                }
                if ($cleanNip && (empty($matchedTeacher->nip) || $matchedTeacher->nip === '-')) {
                    $matchedTeacher->nip = $cleanNip;
                }
                $matchedTeacher->save();
                $updatedCount++;
            } else {
                // Buat guru baru
                $username = strtolower(preg_replace('/[^a-z0-9]/', '', $rawName));
                if (strlen($username) > 15) $username = substr($username, 0, 15);
                $uniqueUser = $username . '_' . rand(10, 99);
                $uniqueEmail = $uniqueUser . '@smkn1bantul.sch.id';

                $newTeacher = User::create([
                    'id_role'  => 2,
                    'fullname' => $rawName,
                    'nip'      => $cleanNip,
                    'username' => $uniqueUser,
                    'email'    => $uniqueEmail,
                    'password' => Hash::make('Peh_1234'),
                    'title'    => 'Guru Normada',
                    'mapel'    => !empty($rawMapel) ? $rawMapel : null,
                ]);

                $allTeachers->push($newTeacher);
                $insertedCount++;
            }
        }

        return response()->json([
            'status'         => 'success',
            'message'        => "Berhasil memproses data guru & mapel: {$updatedCount} data guru diperbarui, {$insertedCount} guru baru ditambahkan.",
            'updated_count'  => $updatedCount,
            'inserted_count' => $insertedCount
        ], 200);
    }
}