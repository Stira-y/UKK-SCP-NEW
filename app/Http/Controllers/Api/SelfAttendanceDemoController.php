<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\User;

class SelfAttendanceDemoController extends Controller
{
    // Koordinat Resmi SMK Negeri 1 Bantul
    const SCHOOL_LAT = -7.8899;
    const SCHOOL_LNG = 110.3556;
    const DEFAULT_RADIUS_METERS = 500; // Radius toleransi sekolah

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

        $user = User::find($request->input('id_siswa'));
        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Data siswa tidak ditemukan.'
            ], 404);
        }

        $lat = (float) $request->input('latitude');
        $lng = (float) $request->input('longitude');
        $distance = $this->calculateDistance($lat, $lng, self::SCHOOL_LAT, self::SCHOOL_LNG);

        $allowedRadius = self::DEFAULT_RADIUS_METERS;
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
                $fileName = 'self_att_' . $user->id_user . '_' . time() . '.jpg';
                file_put_contents($dirPhoto . '/' . $fileName, $photoDecoded);
                $photoPath = 'storage/self_attendances/' . $fileName;
            }
        }

        $now = Carbon::now('Asia/Jakarta');
        $dateStr = $now->toDateString();
        $timeStr = $now->toTimeString();

        $attendances = $this->readAttendances();

        $newRecord = [
            'id'             => 'att_' . time() . '_' . rand(100, 999),
            'id_siswa'       => $user->id_user,
            'nisn'           => $user->nisn ?? '-',
            'nis'            => $user->nis ?? '-',
            'fullname'       => $user->fullname ?? $user->username,
            'kelas'          => $user->schoolClass ? ($user->schoolClass->kelas_nomor . ' ' . $user->schoolClass->nama_kelas . ' ' . ($user->schoolClass->nomor_kelas ?? '')) : '-',
            'date'           => $dateStr,
            'time'           => $timeStr,
            'status'         => $status,
            'latitude'       => $lat,
            'longitude'      => $lng,
            'distance_m'     => $distance,
            'is_in_school'   => $isWithinSchool,
            'photo_url'      => $photoPath ? url($photoPath) : null,
            'created_at'     => $now->toDateTimeString()
        ];

        // Ganti presensi jika siswa sudah absen di tanggal yang sama
        $replaced = false;
        foreach ($attendances as $k => $att) {
            if ($att['id_siswa'] == $user->id_user && $att['date'] === $dateStr) {
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
            'status'          => 'success',
            'message'         => $isWithinSchool 
                ? 'Presensi Mandiri Berhasil! Anda berada di area sekolah (Hadir).' 
                : 'Presensi Tercatat! Anda terdeteksi di luar area sekolah (' . $distance . 'm) status: Alpa.',
            'attendance_status' => $status,
            'distance_meters'   => $distance,
            'is_in_school'      => $isWithinSchool,
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

        return response()->json([
            'status' => 'success',
            'date'   => $date,
            'total'  => count($attendances),
            'data'   => $attendances
        ], 200);
    }
}
