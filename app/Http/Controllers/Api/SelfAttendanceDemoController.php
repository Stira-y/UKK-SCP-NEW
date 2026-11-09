<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\User;

class SelfAttendanceDemoController extends Controller
{
    // Koordinat Default SMK Negeri 1 Bantul
    const DEFAULT_SCHOOL_LAT = -7.8899;
    const DEFAULT_SCHOOL_LNG = 110.3556;
    const DEFAULT_RADIUS_METERS = 200; // Radius toleransi sekolah default 200m

    private function getLocationConfigPath()
    {
        $dir = storage_path('app/demo_data');
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir . '/school_location.json';
    }

    public function readLocationConfig()
    {
        $path = $this->getLocationConfigPath();
        if (!file_exists($path)) {
            return [
                'school_name'   => 'SMK Negeri 1 Bantul',
                'latitude'      => self::DEFAULT_SCHOOL_LAT,
                'longitude'     => self::DEFAULT_SCHOOL_LNG,
                'radius_meters' => self::DEFAULT_RADIUS_METERS
            ];
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return [
                'school_name'   => 'SMK Negeri 1 Bantul',
                'latitude'      => self::DEFAULT_SCHOOL_LAT,
                'longitude'     => self::DEFAULT_SCHOOL_LNG,
                'radius_meters' => self::DEFAULT_RADIUS_METERS
            ];
        }
        return [
            'school_name'   => $data['school_name'] ?? 'SMK Negeri 1 Bantul',
            'latitude'      => (float) ($data['latitude'] ?? self::DEFAULT_SCHOOL_LAT),
            'longitude'     => (float) ($data['longitude'] ?? self::DEFAULT_SCHOOL_LNG),
            'radius_meters' => (int) ($data['radius_meters'] ?? self::DEFAULT_RADIUS_METERS),
        ];
    }

    public function getLocationConfig()
    {
        return response()->json([
            'status' => 'success',
            'data'   => $this->readLocationConfig()
        ], 200);
    }

    public function updateLocationConfig(Request $request)
    {
        $request->validate([
            'latitude'      => 'required|numeric',
            'longitude'     => 'required|numeric',
            'radius_meters' => 'required|integer|min:10|max:5000',
            'school_name'   => 'nullable|string|max:255'
        ]);

        $config = [
            'school_name'   => $request->input('school_name', 'SMK Negeri 1 Bantul'),
            'latitude'      => (float) $request->input('latitude'),
            'longitude'     => (float) $request->input('longitude'),
            'radius_meters' => (int) $request->input('radius_meters', self::DEFAULT_RADIUS_METERS),
            'updated_at'    => Carbon::now('Asia/Jakarta')->toIso8601String()
        ];

        file_put_contents($this->getLocationConfigPath(), json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return response()->json([
            'status'  => 'success',
            'message' => 'Titik lokasi dan radius presensi sekolah berhasil disimpan!',
            'data'    => $config
        ], 200);
    }

    private function getStoragePath()
    {
        $dir = storage_path('app/demo_data');
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir . '/self_attendances.json';
    }

    private function readAttendances()
    {
        $path = $this->getStoragePath();
        if (!file_exists($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function saveAttendances(array $list)
    {
        $path = $this->getStoragePath();
        file_put_contents($path, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // Hitung jarak Haversine (dalam meter)
    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000; // meter
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return round($earthRadius * $c);
    }

    public function submit(Request $request)
    {
        $request->validate([
            'id_siswa'  => 'required',
            'latitude'  => 'required|numeric',
            'longitude' => 'required|numeric',
            'photo'     => 'required|string', // Base64 data URL dari kamera langsung
        ]);

        $user = User::with('schoolClass')->find($request->input('id_siswa'));
        $fullName = $user->fullname ?? $user->username ?? $request->input('student_name', 'Siswa');
        $nis = $user->nis ?? $request->input('student_nis', '-');
        $nisn = $user->nisn ?? '-';
        $className = '-';
        if ($user && $user->schoolClass) {
            $className = trim(($user->schoolClass->kelas_nomor ?? '') . ' ' . ($user->schoolClass->nama_kelas ?? '') . ' ' . ($user->schoolClass->nomor_kelas ?? ''));
        }
        if ($className === '-' && $request->filled('student_class')) {
            $className = $request->input('student_class');
        }

        $locConfig = $this->readLocationConfig();
        $targetLat = (float) $locConfig['latitude'];
        $targetLng = (float) $locConfig['longitude'];
        $allowedRadius = (int) $locConfig['radius_meters'];

        $lat = (float) $request->input('latitude');
        $lng = (float) $request->input('longitude');
        $distance = $this->calculateDistance($lat, $lng, $targetLat, $targetLng);

        $isWithinSchool = ($distance <= $allowedRadius);

        $status = $isWithinSchool ? 'Hadir' : 'Alpa';

        // Simpan foto base64 ke storage publik
        $photoBase64 = $request->input('photo');
        $photoPath = null;
        if (preg_match('/^data:image\/(\w+);base64,/', $photoBase64, $type)) {
            $photoData = substr($photoBase64, strpos($photoBase64, ',') + 1);
            $photoDecoded = base64_decode($photoData);
            if ($photoDecoded !== false) {
                $dirPhoto = public_path('storage/self_attendances');
                if (!file_exists($dirPhoto)) {
                    mkdir($dirPhoto, 0777, true);
                }
                $userId = $user ? $user->id_user : time();
                $fileName = 'self_att_' . $userId . '_' . time() . '.jpg';
                file_put_contents($dirPhoto . '/' . $fileName, $photoDecoded);
                $photoPath = 'storage/self_attendances/' . $fileName;
            }
        }

        $now = Carbon::now('Asia/Jakarta');
        $dateStr = $now->toDateString();
        $timeStr = $now->toTimeString();

        $attendances = $this->readAttendances();

        $newRecord = [
            'id'                => 'att_' . time() . '_' . rand(100, 999),
            'id_siswa'          => $user ? $user->id_user : $request->input('id_siswa'),
            'nisn'              => $nisn,
            'nis'               => $nis,
            'student_nis'       => $nis,
            'fullname'          => $fullName,
            'student_name'      => $fullName,
            'kelas'             => $className,
            'student_class'     => $className,
            'date'              => $dateStr,
            'time'              => $timeStr,
            'submitted_at'      => $timeStr,
            'status'            => $status,
            'latitude'          => $lat,
            'longitude'         => $lng,
            'distance_m'        => $distance,
            'distance_meters'   => $distance,
            'is_in_school'      => $isWithinSchool,
            'is_within_radius'  => $isWithinSchool,
            'photo_url'         => $photoPath ? url($photoPath) : null,
            'created_at'        => $now->toDateTimeString()
        ];

        // Ganti presensi jika siswa sudah absen di tanggal yang sama
        $replaced = false;
        $studentIdentifier = $user ? $user->id_user : $request->input('id_siswa');
        foreach ($attendances as $k => $att) {
            if ($att['id_siswa'] == $studentIdentifier && $att['date'] === $dateStr) {
                $attendances[$k] = $newRecord;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            array_unshift($attendances, $newRecord);
        }

        $this->saveAttendances($attendances);

        return response()->json([
            'status'            => 'success',
            'message'           => $isWithinSchool 
                ? 'Presensi Mandiri Berhasil! Anda berada di area sekolah (Hadir).' 
                : 'Presensi Tercatat! Anda terdeteksi di luar area sekolah (' . $distance . 'm) status: Alpa.',
            'attendance_status' => $status,
            'distance_meters'   => $distance,
            'distance_m'        => $distance,
            'is_in_school'      => $isWithinSchool,
            'is_within_radius'  => $isWithinSchool,
            'data'              => $newRecord
        ], 200);
    }

    public function list(Request $request)
    {
        $attendances = $this->readAttendances();
        $date = $request->query('date', Carbon::now('Asia/Jakarta')->toDateString());
        $statusFilter = $request->query('status');

        if ($date) {
            $attendances = array_values(array_filter($attendances, function($a) use ($date) {
                return isset($a['date']) && $a['date'] === $date;
            }));
        }

        if ($statusFilter && $statusFilter !== 'all') {
            $attendances = array_values(array_filter($attendances, function($a) use ($statusFilter) {
                return isset($a['status']) && strtolower($a['status']) === strtolower($statusFilter);
            }));
        }

        $locConfig = $this->readLocationConfig();

        return response()->json([
            'status'          => 'success',
            'date'            => $date,
            'total'           => count($attendances),
            'location_config' => $locConfig,
            'data'            => $attendances
        ], 200);
    }
}

