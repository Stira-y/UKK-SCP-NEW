<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string', // Dapat diisi Username, Email, atau NIS
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        // Bersihkan input dari spasi di awal/akhir dan ubah ke huruf kecil
        $loginInput = trim($request->username);
        $cleanInput = strtolower($loginInput);

        // Cari user berdasarkan username, email, ATAU nis
        $user = User::with(['role', 'schoolClass'])
            ->whereRaw('LOWER(TRIM(username)) = ?', [$cleanInput])
            ->orWhereRaw("LOWER(TRIM(REPLACE(REPLACE(email, CHAR(13), ''), CHAR(10), '')))= ?", [$cleanInput])
            ->orWhere('nis', $loginInput)
            ->first();

        // Cek jika user tidak ditemukan ATAU password salah
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Username/Email/NIS atau password salah'
            ], 401);
        }

        // Tolak login jika pengguna merupakan Role 3 dengan Title "Guru BK" murni
        $cleanTitle = strtolower(str_replace(' ', '', $user->title ?? ''));
        if ((int) $user->id_role === 3 && $cleanTitle === 'gurubk') {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak. Title Guru BK tidak memiliki izin login ke sistem.'
            ], 403);
        }

        // Tolak login jika siswa sudah lulus / berstatus alumni atau dikeluarkan (nonaktif)
        if ((int) $user->id_role === 4) {
            $statusTitle = strtolower(trim($user->title ?? ''));
            if ($user->id_class === null || in_array($statusTitle, ['alumni', 'lulus', 'dikeluarkan'])) {
                $statusKet = ($statusTitle === 'dikeluarkan') ? 'dikeluarkan' : 'sudah lulus / alumni';
                return response()->json([
                    'status' => 'error',
                    'message' => "Akun Anda telah dinonaktifkan karena berstatus {$statusKet}. Silakan hubungi pihak sekolah jika terjadi kesalahan."
                ], 403);
            }
        }

        // Buat token autentikasi
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Login berhasil',
            'data' => [
                'user' => $user,
                'token' => $token
            ]
        ], 200);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Logout berhasil'
        ], 200);
    }

    // 1. Mengirim OTP untuk verifikasi ganti password
    public function sendOtp(Request $request)
    {
        $user = $request->user();
        
        $otp = rand(100000, 999999);
        
        $user->otp = $otp;
        $user->otp_expired_at = now()->addMinutes(5);
        $user->save();

        try {
            Mail::raw("Kode verifikasi OTP Anda adalah: $otp", function ($message) use ($user) {
                $message->to($user->email)
                        ->subject('Kode Verifikasi OTP SCP Sekolah');
            });
        } catch (\Exception $e) {}

        return response()->json([
            'status' => 'success',
            'message' => 'Kode OTP berhasil dikirim ke email.'
        ], 200);
    }

    // 2. Verifikasi OTP untuk ganti password
    public function verifyOtp(Request $request)
    {
        $request->validate(['otp' => 'required|string']);
        $user = $request->user();

        if ($user->otp !== $request->otp || now()->greaterThan($user->otp_expired_at)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode verifikasi salah atau sudah kadaluarsa.'
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'OTP terverifikasi.'
        ], 200);
    }

    // 3. Proses awal ganti email (Kirim OTP ke email lama/aktif)
    public function changeEmail(Request $request)
    {
        $request->validate(['email' => 'required|email|unique:users,email']);
        $user = $request->user();

        $otp = rand(100000, 999999);
        $user->otp = $otp;
        $user->otp_expired_at = now()->addMinutes(5);
        $user->pending_email = $request->email;
        $user->save();

        try {
            Mail::raw("Kode verifikasi perubahan email Anda adalah: $otp", function ($message) use ($user) {
                $message->to($user->email)
                        ->subject('Verifikasi Ganti Email SCP Sekolah');
            });
        } catch (\Exception $e) {}

        return response()->json([
            'status' => 'success',
            'message' => 'Kode OTP untuk perubahan email telah dikirim.'
        ], 200);
    }

    // 4. Verifikasi OTP email baru dan simpan permanen
    public function verifyNewEmailOtp(Request $request)
    {
        $request->validate(['otp' => 'required|string']);
        $user = $request->user();

        if ($user->otp !== $request->otp || now()->greaterThan($user->otp_expired_at) || !$user->pending_email) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode verifikasi salah atau sudah kadaluarsa.'
            ], 422);
        }

        $user->email = $user->pending_email;
        $user->pending_email = null;
        $user->otp = null;
        $user->otp_expired_at = null;
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Email berhasil diperbarui.'
        ], 200);
    }

    // 5. Update Profil (Username, Password, Avatar)
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'username' => 'sometimes|string|unique:users,username,' . $user->id_user . ',id_user',
            'password' => 'sometimes|string|min:8',
            'avatar' => 'sometimes|image|mimes:jpeg,png,jpg|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->has('username')) {
            $user->username = $request->username;
        }

        if ($request->has('password') && !empty($request->password)) {
            $user->password = Hash::make($request->password);
        }

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $filename = time() . '_' . $file->getClientOriginalName();
            $file->storeAs('public/avatars', $filename);
            $user->avatar = 'avatars/' . $filename;
        }

        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Profil berhasil diperbarui',
            'data' => $user
        ], 200);
    }
}