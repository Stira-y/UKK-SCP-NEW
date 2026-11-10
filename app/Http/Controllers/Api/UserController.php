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

    public function getGraduations(Request $request)
    {
        $type = $request->query('type');

        if ($type === 'guru_pensiun' || $type === 'pensiun') {
            $retiredTeachers = User::whereIn('id_role', [1, 2, 3])
                ->where('title', 'Pensiun')
                ->orderBy('fullname', 'asc')
                ->get();

            $result = $retiredTeachers->map(function ($t) {
                return [
                    'id_user'        => $t->id_user,
                    'nisn'           => '-',
                    'nis'            => '-',
                    'nip'            => $t->nip ?? '-',
                    'fullname'       => $t->fullname ?? $t->username,
                    'status_arsip'   => 'guru_pensiun',
                    'status_label'   => 'Guru Pensiun',
                    'total_izin'     => '-',
                    'total_presensi' => '-',
                    'tanggal_arsip'  => $t->updated_at ? date('d/m/Y', strtotime($t->updated_at)) : '-',
                    'tahun_lulus'    => $t->updated_at ? date('Y', strtotime($t->updated_at)) : date('Y'),
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

            return [
                'id_user'        => $st->id_user,
                'nisn'           => $st->nisn ?? '-',
                'nis'            => $st->nis ?? '-',
                'nip'            => '-',
                'fullname'       => $st->fullname ?? $st->username,
                'status_arsip'   => $isExpelled ? 'dikeluarkan' : 'alumni',
                'status_label'   => $isExpelled ? 'Dikeluarkan' : 'Lulus / Alumni',
                'total_izin'     => $totalIzin,
                'total_presensi' => $totalPresensi,
                'tanggal_arsip'  => $st->updated_at ? date('d/m/Y', strtotime($st->updated_at)) : '-',
                'tahun_lulus'    => $st->updated_at ? date('Y', strtotime($st->updated_at)) : date('Y'),
            ];
        });

        // Jika tidak memfilter secara spesifik type, sertakan juga guru pensiun
        if (!$type) {
            $retiredTeachers = User::whereIn('id_role', [1, 2, 3])
                ->where('title', 'Pensiun')
                ->orderBy('fullname', 'asc')
                ->get();
            foreach ($retiredTeachers as $t) {
                $result->push([
                    'id_user'        => $t->id_user,
                    'nisn'           => '-',
                    'nis'            => '-',
                    'nip'            => $t->nip ?? '-',
                    'fullname'       => $t->fullname ?? $t->username,
                    'status_arsip'   => 'guru_pensiun',
                    'status_label'   => 'Guru Pensiun',
                    'total_izin'     => '-',
                    'total_presensi' => '-',
                    'tanggal_arsip'  => $t->updated_at ? date('d/m/Y', strtotime($t->updated_at)) : '-',
                    'tahun_lulus'    => $t->updated_at ? date('Y', strtotime($t->updated_at)) : date('Y'),
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
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.fullname' => 'required|string|max:255',
            'items.*.username' => 'required|string|max:100',
            'items.*.email'    => 'required|string|email:rfc,dns|max:255',
            'items.*.role'     => 'required|string',
            'items.*.title'    => 'required|string',
            'items.*.nip'      => 'nullable|string|max:50',
            'items.*.password' => 'nullable|string',
            'items.*.kelas'    => 'nullable|string',
        ]);

        $classes = SchoolClass::all();
        $importedCount = 0;
        $errors = [];

        $roleNameToId = [
            'waka'      => 1,
            'guru'      => 2,
            'kesiswaan' => 3
        ];

        foreach ($request->items as $idx => $item) {
            $rowNum = $idx + 2;

            if (User::where('email', $item['email'])->exists()) {
                $errors[] = "Baris {$rowNum}: Email '{$item['email']}' sudah terdaftar.";
                continue;
            }

            if (User::where('username', $item['username'])->exists()) {
                $errors[] = "Baris {$rowNum}: Username '{$item['username']}' sudah digunakan.";
                continue;
            }

            if (!empty($item['nip']) && User::where('nip', $item['nip'])->exists()) {
                $errors[] = "Baris {$rowNum}: NIP '{$item['nip']}' sudah terdaftar.";
                continue;
            }

            $rawRole = strtolower(trim($item['role']));
            $roleId = $roleNameToId[$rawRole] ?? 2;

            $title = trim($item['title']);
            $isWali = strcasecmp(str_replace(' ', '', $title), 'walikelas') === 0;

            $matchedClassId = null;
            $bkClassIds = [];

            if ($isWali) {
                if (empty($item['kelas']) || trim($item['kelas']) === '-') {
                    $errors[] = "Baris {$rowNum}: Wali Kelas wajib menentukan kelas binaan.";
                    continue;
                }

                $cleanClassStr = preg_replace('/\s+/', ' ', trim($item['kelas']));
                foreach ($classes as $c) {
                    $fullC1 = trim($c->kelas_nomor . ' ' . $c->nama_kelas . ' ' . ($c->nomor_kelas ?? ''));
                    $fullC2 = trim($c->kelas_nomor . ' ' . $c->nama_kelas . ($c->nomor_kelas ?? ''));
                    if (strcasecmp($cleanClassStr, $fullC1) === 0 || strcasecmp($cleanClassStr, $fullC2) === 0) {
                        $matchedClassId = $c->id_class;
                        break;
                    }
                }

                if (!$matchedClassId) {
                    $errors[] = "Baris {$rowNum}: Kelas binaan '{$item['kelas']}' tidak ditemukan di database.";
                    continue;
                }
            } else {
                $rawKelasBk = trim($item['kelas'] ?? '');
                if (!empty($rawKelasBk) && $rawKelasBk !== '-') {
                    $parts = array_map('trim', explode(',', $rawKelasBk));
                    foreach ($parts as $p) {
                        foreach ($classes as $c) {
                            $fullC1 = trim($c->kelas_nomor . ' ' . $c->nama_kelas . ' ' . ($c->nomor_kelas ?? ''));
                            $fullC2 = trim($c->kelas_nomor . ' ' . $c->nama_kelas . ($c->nomor_kelas ?? ''));
                            if (strcasecmp($p, $fullC1) === 0 || strcasecmp($p, $fullC2) === 0) {
                                $bkClassIds[] = $c->id_class;
                                break;
                            }
                        }
                    }
                }
            }

            $plainPassword = !empty($item['password']) ? trim($item['password']) : 'Peh_1234';

            $user = User::create([
                'fullname' => trim($item['fullname']),
                'nip'      => !empty($item['nip']) && trim($item['nip']) !== '-' ? trim($item['nip']) : null,
                'username' => trim($item['username']),
                'email'    => strtolower(trim($item['email'])),
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

        if ($importedCount === 0 && count($errors) > 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Seluruh baris gagal diimpor:',
                'errors'  => $errors
            ], 422);
        }

        return response()->json([
            'status'         => 'success',
            'message'        => "Berhasil mengimpor {$importedCount} data guru.",
            'imported_count' => $importedCount,
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
        // 1. Matikan limit eksekusi agar tidak timeout jika memproses ribuan data
        set_time_limit(300);

        // Validasi payload (tanpa dns dan tanpa min:8 agar fleksibel untuk semua format password)
        $request->validate([
            'items'            => 'required|array|min:1',
            'items.*.kelas'    => 'required|string',
            'items.*.fullname' => 'required|string|max:255',
            'items.*.username' => 'required|string|max:100',
            'items.*.email'    => 'required|string|email|max:255',
            'items.*.password' => 'required|string',
            'items.*.nis'      => 'nullable|string|max:50',
            'items.*.nisn'     => 'nullable|string|max:50',
        ]);

        $importedCount = 0;
        $errors = [];

        // 2. KUNCI OPTIMASI #1: Muat seluruh kelas ke memory RAM
        $classes = SchoolClass::all();
        $classMap = [];
        foreach ($classes as $c) {
            $c1 = strtolower(trim($c->kelas_nomor . ' ' . $c->nama_kelas . ' ' . ($c->nomor_kelas ?? '')));
            $c2 = strtolower(trim($c->kelas_nomor . ' ' . $c->nama_kelas . ($c->nomor_kelas ?? '')));
            $classMap[$c1] = $c->id_class;
            $classMap[$c2] = $c->id_class;
        }

        // Ambil data unik yang sudah ada di database ke array PHP (O(1) hash map lookup di RAM)
        $existingEmails    = array_flip(User::pluck('email')->map(fn($e) => strtolower(trim($e)))->toArray());
        $existingUsernames = array_flip(User::pluck('username')->map(fn($u) => strtolower(trim($u)))->toArray());
        $existingNis       = array_flip(User::whereNotNull('nis')->pluck('nis')->map(fn($n) => trim($n))->toArray());
        $existingNisn      = array_flip(User::whereNotNull('nisn')->pluck('nisn')->map(fn($n) => trim($n))->toArray());

        $rowsToInsert = [];
        $now = now();

        foreach ($request->items as $idx => $item) {
            $rowNum = $idx + 2;

            // Cocokkan kelas langsung di memory RAM
            $cleanClassStr = strtolower(preg_replace('/\s+/', ' ', trim($item['kelas'])));
            $matchedClassId = $classMap[$cleanClassStr] ?? null;

            if (!$matchedClassId) {
                $errors[] = "Baris {$rowNum}: Kelas '{$item['kelas']}' tidak terdaftar di sistem.";
                continue;
            }

            $cleanEmail = strtolower(trim($item['email']));
            $cleanUser  = strtolower(trim($item['username']));
            $cleanNis   = (!empty($item['nis']) && trim($item['nis']) !== '-') ? trim($item['nis']) : null;
            $cleanNisn  = (!empty($item['nisn']) && trim($item['nisn']) !== '-') ? trim($item['nisn']) : null;

            // Pengecekan duplikat instan di RAM (0 ms, tanpa query SQL bolak-balik)
            if (isset($existingEmails[$cleanEmail])) {
                $errors[] = "Baris {$rowNum}: Email '{$item['email']}' sudah terdaftar.";
                continue;
            }

            if (isset($existingUsernames[$cleanUser])) {
                $errors[] = "Baris {$rowNum}: Username '{$item['username']}' sudah digunakan.";
                continue;
            }

            if ($cleanNis && isset($existingNis[$cleanNis])) {
                $errors[] = "Baris {$rowNum}: NIS '{$cleanNis}' sudah terdaftar.";
                continue;
            }

            if ($cleanNisn && isset($existingNisn[$cleanNisn])) {
                $errors[] = "Baris {$rowNum}: NISN '{$cleanNisn}' sudah terdaftar.";
                continue;
            }

            // Tandai terpakai di memori agar baris di bawahnya tidak menabrak baris sekarang
            $existingEmails[$cleanEmail] = true;
            $existingUsernames[$cleanUser] = true;
            if ($cleanNis)  $existingNis[$cleanNis] = true;
            if ($cleanNisn) $existingNisn[$cleanNisn] = true;

            // 3. KUNCI OPTIMASI #2: Hashing Bcrypt cepat (cost 4) agar 1.711 baris selesai dalam hitungan detik
            $pass = password_hash(trim($item['password']), PASSWORD_BCRYPT, ['cost' => 4]);

            $rowsToInsert[] = [
                'fullname'   => trim($item['fullname']),
                'username'   => trim($item['username']),
                'email'      => $cleanEmail,
                'password'   => $pass,
                'nis'        => $cleanNis,
                'nisn'       => $cleanNisn,
                'id_class'   => $matchedClassId,
                'id_role'    => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $importedCount++;

            // 4. KUNCI OPTIMASI #3: Insert bertahap per 200 baris agar progres langsung masuk ke phpMyAdmin
            if (count($rowsToInsert) >= 200) {
                User::insert($rowsToInsert);
                $rowsToInsert = [];
            }
        }

        // Masukkan sisa baris terakhir
        if (!empty($rowsToInsert)) {
            User::insert($rowsToInsert);
        }

        if ($importedCount === 0 && count($errors) > 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Seluruh baris gagal diimpor:',
                'errors'  => $errors
            ], 422);
        }

        return response()->json([
            'status'         => 'success',
            'message'        => "Berhasil mengimpor {$importedCount} data siswa.",
            'imported_count' => $importedCount,
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
                $student->id_class = null;
                $student->title = 'alumni';
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
}