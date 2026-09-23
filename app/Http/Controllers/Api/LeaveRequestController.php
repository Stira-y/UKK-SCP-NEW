<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LeaveRequestController extends Controller
{
    public function index(Request $request)
    {
        $query = LeaveRequest::with(['student', 'applicant', 'verifier']);

        if ($request->has('id_siswa')) {
            $query->where('id_siswa', $request->id_siswa);
        }

        if ($request->has('status_approval')) {
            $query->where('status_approval', $request->status_approval);
        }

        $leaveRequests = $query->latest('created_at')->get();

        return response()->json([
            'status' => 'success',
            'data' => $leaveRequests
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_siswa'        => 'required|exists:users,id_user',
            'id_pengaju'      => 'required|exists:users,id_user',
            'start_date'      => 'required|date',
            'end_date'        => 'required|date|after_or_equal:start_date',
            'reason'          => 'required|string',
            'documents'       => 'nullable|array',
            'documents.*'     => 'file|mimes:pdf,jpg,jpeg,png|max:5120', // Maksimal 5MB per file
            'status_approval' => 'nullable|in:Disetujui,Pending,Ditolak',
        ]);

        if (!isset($validated['status_approval'])) {
            $validated['status_approval'] = 'Pending';
        }

        // Menyimpan array file dokumen jika diunggah
        if ($request->hasFile('documents')) {
            $uploadedPaths = [];
            foreach ($request->file('documents') as $file) {
                $uploadedPaths[] = $file->store('leave_documents', 'public');
            }
            $validated['document_path'] = $uploadedPaths;
        }

        $leaveRequest = LeaveRequest::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Pengajuan izin berhasil dibuat',
            'data' => $leaveRequest->load(['student', 'applicant'])
        ], 201);
    }

    public function show($id)
    {
        $leaveRequest = LeaveRequest::with(['student', 'applicant', 'verifier'])->find($id);

        if (!$leaveRequest) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data pengajuan izin tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $leaveRequest
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $leaveRequest = LeaveRequest::find($id);

        if (!$leaveRequest) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data pengajuan izin tidak ditemukan'
            ], 404);
        }

        $validated = $request->validate([
            'id_siswa'          => 'sometimes|required|exists:users,id_user',
            'id_pengaju'        => 'sometimes|required|exists:users,id_user',
            'verified_by'       => 'nullable|exists:users,id_user',
            'start_date'        => 'sometimes|required|date',
            'end_date'          => 'sometimes|required|date|after_or_equal:start_date',
            'reason'            => 'sometimes|required|string',
            'status_approval'   => 'sometimes|required|in:Disetujui,Pending,Ditolak',
            'documents'         => 'nullable|array',
            'documents.*'       => 'file|mimes:pdf,jpg,jpeg,png|max:5120',
            'deleted_documents' => 'nullable|array',
        ]);

        // 1. Ambil daftar file lama dari database
        $currentPaths = $leaveRequest->document_path ?? [];
        if (is_string($currentPaths)) {
            $currentPaths = json_decode($currentPaths, true) ?? [];
        }

        // 2. Hapus file yang ditandai dihapus dari storage & array
        if ($request->has('deleted_documents')) {
            $deletedDocs = $request->input('deleted_documents');
            foreach ($deletedDocs as $deletedPath) {
                $trimmedPath = trim($deletedPath);
                
                // Hapus fisik berkas dari server
                if (Storage::disk('public')->exists($trimmedPath)) {
                    Storage::disk('public')->delete($trimmedPath);
                }
                
                // Keluarkan path dari array tersimpan
                $currentPaths = array_values(array_filter($currentPaths, function ($p) use ($trimmedPath) {
                    return trim($p) !== $trimmedPath;
                }));
            }
        }

        // 3. Tambahkan file baru jika ada unggahan baru
        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $file) {
                $currentPaths[] = $file->store('leave_documents', 'public');
            }
        }

        // 4. Set daftar path terbaru ke dalam data yang akan di-update
        $validated['document_path'] = array_values($currentPaths);

        $leaveRequest->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Data pengajuan izin berhasil diperbarui',
            'data' => $leaveRequest->load(['student', 'applicant', 'verifier'])
        ], 200);
    }

    public function destroy($id)
    {
        $leaveRequest = LeaveRequest::find($id);

        if (!$leaveRequest) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data pengajuan izin tidak ditemukan'
            ], 404);
        }

        // Hapus semua file terlampir dari storage saat data pengajuan dihapus
        $paths = $leaveRequest->document_path ?? [];
        if (is_string($paths)) {
            $paths = json_decode($paths, true) ?? [];
        }

        foreach ($paths as $path) {
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }

        $leaveRequest->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Data pengajuan izin berhasil dihapus'
        ], 200);
    }
}