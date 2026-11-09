<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LeaveRequestController extends Controller
{
    public function index(Request $request)
    {
        $query = LeaveRequest::with([
            'student' => function ($q) {
                $q->with('schoolClass');
            },
            'applicant',
            'verifier'
        ]);

        // Filter Siswa (Role 4)
        if ($request->has('id_siswa')) {
            $query->where('id_siswa', $request->id_siswa);
        }

        // Filter Kelas (Wali Kelas & Guru BK Pengajar) - Dioptimasi agar cepat tanpa subquery berat
        if ($request->has('id_class')) {
            $classIds = array_filter(array_map('trim', explode(',', (string) $request->id_class)));
            $studentIds = User::whereIn('id_class', $classIds)->pluck('id_user');
            $query->whereIn('id_siswa', $studentIds);
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
            'documents.*'     => 'file|mimes:pdf,jpg,jpeg,png|max:5120',
            'status_approval' => 'nullable|string',
            'reason_ditolak'  => 'nullable|string',
        ]);

        if (empty($validated['status_approval'])) {
            $validated['status_approval'] = 'Pending';
        }

        $validated['verified_by'] = null;

        if ($request->hasFile('documents')) {
            $uploadedPaths = [];
            foreach ($request->file('documents') as $file) {
                $fileName = time() . '_' . $file->getClientOriginalName();
                $uploadedPaths[] = $file->storeAs('leave_documents', $fileName, 'public');
            }
            $validated['document_path'] = $uploadedPaths;
        }

        $leaveRequest = LeaveRequest::create($validated);

        if (in_array(strtolower(trim($leaveRequest->status_approval ?? '')), ['disetujui', 'di terima', 'diterima'])) {
            \App\Models\Attendance::where('id_siswa', $leaveRequest->id_siswa)
                ->whereDate('date', '>=', substr((string)$leaveRequest->start_date, 0, 10))
                ->whereDate('date', '<=', substr((string)($leaveRequest->end_date ?? $leaveRequest->start_date), 0, 10))
                ->update(['status' => 'Izin']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Pengajuan izin berhasil dibuat',
            'data' => $leaveRequest->load(['student.schoolClass', 'applicant'])
        ], 201);
    }

    public function show($id)
    {
        $leaveRequest = $this->findLeaveRequest($id);

        if (!$leaveRequest) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data pengajuan izin tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $leaveRequest->load(['student.schoolClass', 'applicant', 'verifier'])
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $leaveRequest = $this->findLeaveRequest($id);

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
            'status_approval'   => 'sometimes|required|string',
            'reason_ditolak'    => 'nullable|string',
            'documents'         => 'nullable|array',
            'documents.*'       => 'file|mimes:pdf,jpg,jpeg,png|max:5120',
            'deleted_documents' => 'nullable|array',
        ]);

        $currentUser = $request->user();

        // Validasi jika surat izin telah disetujui / diproses oleh pengguna lain
        $targetStatus = $request->input('status_approval');
        if ($targetStatus && !in_array(strtolower($targetStatus), ['pending', 'aju banding'])) {
            $currentStatus = strtolower(trim($leaveRequest->status_approval ?? ''));
            if (!in_array($currentStatus, ['pending', 'aju banding']) && $leaveRequest->verified_by) {
                if ($currentUser && (int) $leaveRequest->verified_by !== (int) $currentUser->id_user) {
                    $verifierName = $leaveRequest->verifier?->fullname ?? $leaveRequest->verifier?->username ?? 'user lain';
                    return response()->json([
                        'status' => 'error',
                        'message' => "Surat izin ini telah diverifikasi oleh {$verifierName}."
                    ], 409);
                }
            }
        }

        if ($currentUser) {
            if ((int) $currentUser->id_role === 3) {
                $validated['verified_by'] = $currentUser->id_user;
            } else {
                $validated['verified_by'] = null;
            }
        } else {
            if ($request->filled('verified_by')) {
                $targetVerifier = User::find($request->input('verified_by'));
                if ($targetVerifier && (int) $targetVerifier->id_role === 3) {
                    $validated['verified_by'] = $targetVerifier->id_user;
                } else {
                    $validated['verified_by'] = null;
                }
            } else {
                $validated['verified_by'] = null;
            }
        }

        $currentPaths = $leaveRequest->document_path ?? [];
        if (is_string($currentPaths)) {
            $currentPaths = json_decode($currentPaths, true) ?? [];
        }

        if ($request->has('deleted_documents')) {
            $deletedDocs = $request->input('deleted_documents');
            foreach ($deletedDocs as $deletedPath) {
                $trimmedPath = trim($deletedPath);
                if (Storage::disk('public')->exists($trimmedPath)) {
                    Storage::disk('public')->delete($trimmedPath);
                }
                $currentPaths = array_values(array_filter($currentPaths, function ($p) use ($trimmedPath) {
                    return trim($p) !== $trimmedPath;
                }));
            }
        }

        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $file) {
                $fileName = time() . '_' . $file->getClientOriginalName();
                $currentPaths[] = $file->storeAs('leave_documents', $fileName, 'public');
            }
        }

        if ($request->hasFile('documents') || $request->has('deleted_documents')) {
            $validated['document_path'] = array_values($currentPaths);
        }

        $targetStatus = $request->input('status_approval');
        if ($targetStatus) {
            try {
                $leaveRequest->update($validated);
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'status_approval') || str_contains($e->getMessage(), '1265')) {
                    $fallback = match ($targetStatus) {
                        'Di Terima' => 'Disetujui',
                        'Di Tolak' => 'Ditolak',
                        default => 'Pending'
                    };
                    $validated['status_approval'] = $fallback;
                    $leaveRequest->update($validated);
                } else {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Gagal memperbarui status: ' . $e->getMessage()
                    ], 500);
                }
            }
        } else {
            $leaveRequest->update($validated);
        }

        // Jika status izin sudah disetujui / diterima, otomatis perbarui seluruh presensi pada rentang tanggal tersebut menjadi Izin
        if (in_array(strtolower(trim($leaveRequest->status_approval ?? '')), ['disetujui', 'di terima', 'diterima'])) {
            \App\Models\Attendance::where('id_siswa', $leaveRequest->id_siswa)
                ->whereDate('date', '>=', substr((string)$leaveRequest->start_date, 0, 10))
                ->whereDate('date', '<=', substr((string)($leaveRequest->end_date ?? $leaveRequest->start_date), 0, 10))
                ->update(['status' => 'Izin']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Data pengajuan izin berhasil diperbarui',
            'data' => $leaveRequest->load(['student.schoolClass', 'applicant', 'verifier'])
        ], 200);
    }

    public function destroy($id)
    {
        $leaveRequest = $this->findLeaveRequest($id);

        if (!$leaveRequest) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data pengajuan izin tidak ditemukan'
            ], 404);
        }

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

    private function findLeaveRequest($id)
    {
        $possibleKeys = ['id_izin_keluar', 'id_leave_request', 'id_izin', 'id'];

        try {
            $pk = (new LeaveRequest)->getKeyName();
            $found = LeaveRequest::where($pk, $id)->first();
            if ($found) return $found;
        } catch (\Throwable $e) {}

        foreach ($possibleKeys as $key) {
            try {
                $found = LeaveRequest::where($key, $id)->first();
                if ($found) return $found;
            } catch (\Throwable $e) {}
        }

        return null;
    }
}