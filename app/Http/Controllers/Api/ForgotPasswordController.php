<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ForgotPasswordController extends Controller
{
    /**
     * Kirim OTP ke email jika username dan email cocok.
     */
    public function sendOtp(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'email'    => 'required|string|email',
        ]);

        $username = trim($request->username);
        $email    = trim(strtolower($request->email));

        // Pencocokan kebal spasi dan case-insensitive
        $user = User::whereRaw('LOWER(TRIM(username)) = ?', [strtolower($username)])
                    ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                    ->first();

        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Username atau email yang Anda masukkan tidak cocok / tidak ditemukan.'
            ], 404);
        }

        $otp = sprintf('%06d', mt_rand(0, 999999));
        $cacheKey = 'forgot_pw_otp_' . md5($username . '_' . $email);

        Cache::put($cacheKey, [
            'user_id'    => $user->id_user,
            'code'       => (string)$otp,
            'expires_at' => now()->addMinutes(5)->timestamp
        ], now()->addMinutes(5));

        try {
            Mail::raw("Halo {$user->fullname},\n\nKode OTP verifikasi Lupa Password Anda adalah: {$otp}\n\nKode ini berlaku selama 5 menit. Jangan berikan kode ini kepada siapa pun.", function ($message) use ($user, $email) {
                $message->to($email)->subject('Kode OTP Verifikasi Lupa Password');
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Kode OTP berhasil dikirim ke email.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal mengirim email OTP: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verifikasi OTP untuk Lupa Password.
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'email'    => 'required|string|email',
            'otp'      => 'required|string|digits:6'
        ]);

        $username = trim($request->username);
        $email    = trim(strtolower($request->email));
        $cacheKey = 'forgot_pw_otp_' . md5($username . '_' . $email);

        $cachedOtpData = Cache::get($cacheKey);

        if (!$cachedOtpData || now()->timestamp > $cachedOtpData['expires_at']) {
            if ($cachedOtpData) Cache::forget($cacheKey);
            return response()->json([
                'status'  => 'expired',
                'message' => 'Kode verifikasi kadaluarsa. Silakan minta kode baru.'
            ], 422);
        }

        if ((string)$cachedOtpData['code'] !== (string)$request->otp) {
            return response()->json([
                'status'  => 'invalid',
                'message' => 'Kode verifikasi salah.'
            ], 422);
        }

        $resetToken = Str::random(40);
        $tokenKey   = 'forgot_pw_token_' . $resetToken;

        Cache::put($tokenKey, [
            'user_id' => $cachedOtpData['user_id']
        ], now()->addMinutes(15));

        Cache::forget($cacheKey);

        return response()->json([
            'status'      => 'success',
            'message'     => 'Verifikasi OTP berhasil!',
            'reset_token' => $resetToken
        ], 200);
    }

    /**
     * Reset password user.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'password'    => [
                'required',
                'string',
                'min:8',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[\W_]/',
                'regex:/^[^\s]+$/'
            ],
            'reset_token' => 'required|string'
        ]);

        $tokenKey = 'forgot_pw_token_' . $request->reset_token;
        $tokenData = Cache::get($tokenKey);

        if (!$tokenData) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Sesi reset password tidak valid atau telah kadaluarsa.'
            ], 422);
        }

        $user = User::find($tokenData['user_id']);

        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'User tidak ditemukan.'
            ], 404);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        Cache::forget($tokenKey);

        return response()->json([
            'status'  => 'success',
            'message' => 'Password berhasil diperbarui!'
        ], 200);
    }
}