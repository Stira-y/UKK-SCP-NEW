<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with(['role', 'schoolClass']);

        if ($request->has('id_role')) {
            $query->where('id_role', $request->id_role);
        }

        $users = $query->latest()->get();

        return response()->json([
            'status' => 'success',
            'data'   => $users
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'fullname' => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:6',
            'id_role'  => 'required|exists:roles,id_role',
            'id_class' => 'nullable|exists:school_classes,id_class',
            'username' => 'nullable|string|max:100|unique:users,username',
            'nis'      => 'nullable|string|max:50|unique:users,nis',
            'nisn'     => 'nullable|string|max:50|unique:users,nisn',
            'nip'      => 'nullable|string|max:50|unique:users,nip',
            'title'    => 'nullable|string|max:50',
        ]);

        $user = User::create($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'User berhasil ditambahkan',
            'data'    => $user->load(['role', 'schoolClass'])
        ], 201);
    }

    public function show($id)
    {
        $user = User::with(['role', 'schoolClass'])->find($id);

        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'User tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $user
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'User tidak ditemukan'
            ], 404);
        }

        $validated = $request->validate([
            'fullname' => 'sometimes|required|string|max:255',
            'email'    => 'sometimes|required|string|email|max:255|unique:users,email,' . $id . ',id_user',
            'password' => 'nullable|string|min:6',
            'id_role'  => 'sometimes|required|exists:roles,id_role',
            'id_class' => 'nullable|exists:school_classes,id_class',
            'username' => 'nullable|string|max:100|unique:users,username,' . $id . ',id_user',
            'nis'      => 'nullable|string|max:50|unique:users,nis,' . $id . ',id_user',
            'nisn'     => 'nullable|string|max:50|unique:users,nisn,' . $id . ',id_user',
            'nip'      => 'nullable|string|max:50|unique:users,nip,' . $id . ',id_user',
            'title'    => 'nullable|string|max:50',
        ]);

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Data user berhasil diperbarui',
            'data'    => $user->load(['role', 'schoolClass'])
        ], 200);
    }

    public function destroy($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'User tidak ditemukan'
            ], 404);
        }

        $user->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'User berhasil dihapus'
        ], 200);
    }

    /**
     * Memperbarui Foto Profil (Avatar) & Bio Pengguna Login
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'username' => 'nullable|string|max:100|unique:users,username,' . $user->id_user . ',id_user',
            'password' => 'nullable|string|min:6',
            'avatar'   => 'nullable|image|mimes:jpeg,png,jpg|max:2048', // Maksimal 2MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors()
            ], 422);
        }

        // Update Username jika diisi
        if ($request->has('username') && $request->username) {
            $user->username = $request->username;
        }

        // Update Password jika diisi
        if ($request->has('password') && $request->password) {
            $user->password = Hash::make($request->password);
        }

        // Update Foto Profil / Avatar
        if ($request->hasFile('avatar')) {
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }

            $path = $request->file('avatar')->store('avatars', 'public');
            $user->avatar = $path;
        }

        $user->save();

        $user->avatar_url = $user->avatar ? asset('storage/' . $user->avatar) : null;

        return response()->json([
            'status'  => 'success',
            'message' => 'Profil berhasil diperbarui',
            'data'    => $user->load(['role', 'schoolClass'])
        ], 200);
    }
}