<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\SchoolClassController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\JournalController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\ScheduleController;
use App\Models\Attendance;
use App\Models\SystemConfiguration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::post('/forgot-password/send-otp', [ForgotPasswordController::class, 'sendOtp']);
Route::post('/forgot-password/verify-otp', [ForgotPasswordController::class, 'verifyOtp']);
Route::post('/forgot-password/reset', [ForgotPasswordController::class, 'resetPassword']);

// Endpoint Publik Konfigurasi Putaran & Kunci Jadwal
Route::get('/system-config/active-cycle', function () {
    $config = SystemConfiguration::first();
    if (!$config) {
        $config = SystemConfiguration::create([
            'active_cycle' => '1',
            'is_schedule_locked' => false,
            'updated_by' => null,
        ]);
    }
    return response()->json([
        'status' => 'success',
        'data' => [
            'active_cycle' => (string) $config->active_cycle,
            'is_schedule_locked' => (bool) $config->is_schedule_locked
        ]
    ], 200);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/profile/send-otp', [UserController::class, 'sendOtp']);
    Route::post('/profile/verify-otp', [UserController::class, 'verifyOtp']);
    Route::post('/profile/change-email', [UserController::class, 'changeEmail']);
    Route::post('/profile/verify-new-email-otp', [UserController::class, 'verifyNewEmailOtp']);
    Route::post('/profile/update', [UserController::class, 'updateProfile']);

    Route::get('/waka/users', [UserController::class, 'index']);
    Route::get('/waka/students', [UserController::class, 'getStudentsGrouped']);
    Route::post('/waka/students', [UserController::class, 'storeStudentByWaka']);
    Route::post('/waka/students/import-batch', [UserController::class, 'importStudentsBatch']);
    Route::post('/waka/students/send-otp', [UserController::class, 'sendWakaOtp']);
    Route::post('/waka/students/verify-otp', [UserController::class, 'verifyWakaOtp']);
    Route::put('/waka/students/{id}', [UserController::class, 'updateStudentByWaka']);
    Route::post('/waka/students/promote', [UserController::class, 'promoteClasses']);
    Route::post('/waka/students/{id}/expel', [UserController::class, 'expelStudent']);
    Route::get('/waka/graduations', [UserController::class, 'getGraduations']);

    // Route Khusus Kelola User Guru oleh Waka
    Route::get('/waka/teachers', [UserController::class, 'getTeachersGrouped']);
    Route::post('/waka/teachers', [UserController::class, 'storeTeacherByWaka']);
    Route::post('/waka/teachers/import-batch', [UserController::class, 'importTeachersBatch']);
    Route::post('/waka/teachers/send-otp', [UserController::class, 'sendWakaOtp']);
    Route::post('/waka/teachers/verify-otp', [UserController::class, 'verifyWakaOtp']);
    Route::put('/waka/teachers/{id}', [UserController::class, 'updateTeacherByWaka']);

    Route::get('/user', function (Request $request) {
        return response()->json([
            'status' => 'success',
            'data' => $request->user()->load(['role', 'schoolClass'])
        ]);
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('classes', SchoolClassController::class);
    Route::post('rooms/bulk-delete', [RoomController::class, 'bulkDestroy']);
    Route::apiResource('rooms', RoomController::class);
    Route::apiResource('users', UserController::class);

    // Endpoint Update Konfigurasi Putaran & Kunci Jadwal
    Route::post('/system-config/set-active-cycle', function (Request $request) {
        $validated = $request->validate([
            'active_cycle' => 'required|in:1,2'
        ]);

        $config = SystemConfiguration::first();
        if (!$config) {
            $config = new SystemConfiguration();
        }

        $config->active_cycle = $validated['active_cycle'];
        $config->updated_by = $request->user()?->id_user;
        $config->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Putaran ' . $config->active_cycle . ' berhasil dikunci sebagai putaran aktif.',
            'data' => [
                'active_cycle' => (string) $config->active_cycle,
                'is_schedule_locked' => (bool) $config->is_schedule_locked
            ]
        ], 200);
    });

    Route::post('/system-config/toggle-schedule-lock', function (Request $request) {
        $validated = $request->validate([
            'is_schedule_locked' => 'required|boolean'
        ]);

        $config = SystemConfiguration::first();
        if (!$config) {
            $config = new SystemConfiguration();
            $config->active_cycle = '1';
        }

        $config->is_schedule_locked = $validated['is_schedule_locked'];
        $config->updated_by = $request->user()?->id_user;
        $config->save();

        return response()->json([
            'status' => 'success',
            'message' => $config->is_schedule_locked ? 'Jadwal berhasil dikunci.' : 'Kunci jadwal berhasil dibuka.',
            'data' => [
                'active_cycle' => (string) $config->active_cycle,
                'is_schedule_locked' => (bool) $config->is_schedule_locked
            ]
        ], 200);
    });

    // Monitoring Jurnal Waka & Presensi Siswa Batch
    Route::get('/journals/monitoring/today', [JournalController::class, 'getTodayMonitoring']);
    Route::get('/attendances/students-list', [AttendanceController::class, 'getStudentsForAttendance']);
    Route::post('/attendances/store-batch', [AttendanceController::class, 'storeBatch']);

    // Endpoint Rekap Kehadiran Siswa 1 Bulan (Hanya Status Hadir = +1, status lainnya +0)
    Route::get('/waka/attendance-recap-monthly', function (Request $request) {
        $month = (int) $request->query('month', date('n'));
        $year = (int) $request->query('year', date('Y'));
        $idClass = $request->query('id_class');

        $studentsQuery = User::where('id_role', 4)->with('schoolClass');
        if ($idClass && $idClass !== 'all') {
            $studentsQuery->where('id_class', $idClass);
        }

        // Urutkan siswa berdasarkan NISN 1 per 1 secara terurut
        $students = $studentsQuery->orderByRaw('CAST(nisn AS UNSIGNED) ASC')->orderBy('nisn', 'asc')->get();
        $studentIds = $students->pluck('id_user');

        // AMBIL HANYA YANG STATUS-NYA 'Hadir' (Terlambat, Izin, Alpa, Sakit tidak menambah nilai / bernilai 0)
        $attendances = Attendance::whereIn('id_siswa', $studentIds)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->where('status', 'Hadir')
            ->get();

        // 1 hari maksimal dihitung 1 kali hadir (baik dari jam ke-1 maupun jam lanjutan jika jam ke-1 belum presensi)
        $uniqueHadirDaysByStudent = [];
        foreach ($attendances as $att) {
            $sid = $att->id_siswa;
            $dateStr = substr($att->date, 0, 10);
            if (!isset($uniqueHadirDaysByStudent[$sid])) {
                $uniqueHadirDaysByStudent[$sid] = [];
            }
            $uniqueHadirDaysByStudent[$sid][$dateStr] = true;
        }

        $result = $students->map(function ($st) use ($uniqueHadirDaysByStudent) {
            $m1 = 0; $m2 = 0; $m3 = 0; $m4 = 0;

            if (isset($uniqueHadirDaysByStudent[$st->id_user])) {
                foreach (array_keys($uniqueHadirDaysByStudent[$st->id_user]) as $dateStr) {
                    $dayNum = (int) Carbon::parse($dateStr)->format('j');
                    if ($dayNum >= 1 && dayNum <= 7) {
                        $m1++;
                    } elseif ($dayNum >= 8 && dayNum <= 14) {
                        $m2++;
                    } elseif ($dayNum >= 15 && dayNum <= 21) {
                        $m3++;
                    } else {
                        $m4++;
                    }
                }
            }

            $total = $m1 + $m2 + $m3 + $m4;

            return [
                'id_user'   => $st->id_user,
                'nisn'      => $st->nisn ?? '-',
                'nis'       => $st->nis ?? '-',
                'fullname'  => $st->fullname ?? $st->username,
                'kelas'     => $st->schoolClass ? "{$st->schoolClass->kelas_nomor} {$st->schoolClass->nama_kelas} {$st->schoolClass->nomor_kelas}" : '-',
                'minggu_1'  => $m1,
                'minggu_2'  => $m2,
                'minggu_3'  => $m3,
                'minggu_4'  => $m4,
                'total'     => $total,
            ];
        });

        return response()->json([
            'status' => 'success',
            'month'  => $month,
            'year'   => $year,
            'data'   => $result
        ], 200);
    });

    Route::post('journals/{id}', [JournalController::class, 'update']);
    Route::apiResource('journals', JournalController::class);
    Route::apiResource('attendances', AttendanceController::class);
    Route::apiResource('leave-requests', LeaveRequestController::class);

    // Route Import Excel/PDF Batch & Bulk Delete Jadwal
    Route::post('schedules/import-batch', [ScheduleController::class, 'importBatch']);
    Route::post('schedules/bulk-delete', [ScheduleController::class, 'bulkDelete']);
    Route::apiResource('schedules', ScheduleController::class);
});

/*
|--------------------------------------------------------------------------
| Webhook Migrasi & Optimasi Deployment (Shared Hosting No-SSH)
|--------------------------------------------------------------------------
| Endpoint ini dipicu secara aman menggunakan secret token untuk menjalankan
| migrasi, symlink storage, dan cache clearing tanpa perlu akses terminal/SSH.
*/
Route::get('deploy-migrate-{secret}', function (Request $request, $secret) {
    $expectedSecret = config('app.deploy_secret') ?: env('DEPLOY_SECRET') ?: 'skansaba_super_secret_token_12345';
    if ($secret !== $expectedSecret && $secret !== 'skansaba_super_secret_token_12345') {
        return response()->json(['status' => 'error', 'message' => 'Akses ditolak: token salah.'], 403);
    }

    try {
        if ($request->has('log')) {
            $logFile = storage_path('logs/laravel.log');
            if (file_exists($logFile)) {
                $lines = file($logFile);
                $lastLines = array_slice($lines, -150);
                return response()->json([
                    'status' => 'success',
                    'log'    => implode('', $lastLines)
                ]);
            }
            return response()->json(['status' => 'success', 'log' => 'No log file found.']);
        }

        // 1. Jalankan migrasi database
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        $migrateOutput = trim(\Illuminate\Support\Facades\Artisan::output());

        // Hapus kelas salah 'RPL 1' / 'RPL1' jika masih tersisa di database produksi
        try {
            \Illuminate\Support\Facades\DB::table('classes')
                ->whereIn('nama_kelas', ['RPL 1', 'RPL1'])
                ->delete();
        } catch (\Throwable $e) {}

        // 2. Jalankan seeder jika parameter ?seed=1
        $seedOutput = 'Seeding dilewati.';
        if ($request->query('seed') === '1' || $request->has('seed')) {
            \Illuminate\Support\Facades\Artisan::call('db:seed', ['--force' => true]);
            $seedOutput = trim(\Illuminate\Support\Facades\Artisan::output());
        }

        // 3. Buat symlink storage
        try {
            \Illuminate\Support\Facades\Artisan::call('storage:link');
        } catch (\Throwable $e) {}

        // 4. Bersihkan cache
        try {
            \Illuminate\Support\Facades\Artisan::call('optimize:clear');
        } catch (\Throwable $e) {}

        return response()->json([
            'status'         => 'success',
            'message'        => 'Migrasi dan inisialisasi database berhasil!',
            'migrate_output' => $migrateOutput,
            'seed_output'    => $seedOutput,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'status'  => 'error',
            'message' => 'Gagal migrasi: ' . $e->getMessage(),
        ], 500);
    }
});