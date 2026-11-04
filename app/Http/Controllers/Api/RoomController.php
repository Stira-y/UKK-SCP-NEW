<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function index()
    {
        $rooms = Room::all();
        return response()->json([
            'status' => 'success', 
            'data' => $rooms
        ], 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nama_room'        => 'required|string|max:100',
            'nomor_room_awal'  => 'required|integer|min:1|max:50',
            'nomor_room_akhir' => 'nullable|integer|min:1|max:50|gte:nomor_room_awal',
        ]);

        $namaRoom = $request->nama_room;
        $awal = (int) $request->nomor_room_awal;
        $akhir = $request->filled('nomor_room_akhir') ? (int) $request->nomor_room_akhir : $awal;

        $createdRooms = [];
        $duplicateNumbers = [];

        for ($i = $awal; $i <= $akhir; $i++) {
            $nomorStr = (string) $i;
            $exists = Room::where('nama_room', $namaRoom)
                ->where('nomor_room', $nomorStr)
                ->exists();

            if ($exists) {
                $duplicateNumbers[] = $nomorStr;
            } else {
                $createdRooms[] = Room::create([
                    'nama_room'  => $namaRoom,
                    'nomor_room' => $nomorStr,
                ]);
            }
        }

        if (count($duplicateNumbers) > 0 && count($createdRooms) === 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Data sudah di tambahkan! ' . $namaRoom . ' nomor ' . implode(', ', $duplicateNumbers) . ' sudah terdaftar.'
            ], 422);
        }

        $message = 'Room berhasil ditambahkan.';
        if (count($duplicateNumbers) > 0) {
            $message = 'Beberapa room berhasil ditambahkan, namun nomor ' . implode(', ', $duplicateNumbers) . ' dilewati karena data sudah di tambahkan.';
        }

        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => $createdRooms
        ], 201);
    }

    public function show($id)
    {
        $room = Room::find($id);
        if (!$room) {
            return response()->json([
                'status'  => 'error', 
                'message' => 'Data tidak ditemukan'
            ], 404);
        }
        return response()->json([
            'status' => 'success', 
            'data'   => $room
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $room = Room::find($id);
        if (!$room) {
            return response()->json([
                'status'  => 'error', 
                'message' => 'Data tidak ditemukan'
            ], 404);
        }

        $validated = $request->validate([
            'nama_room'  => 'sometimes|required|string|max:100',
            'nomor_room' => 'sometimes|required|string|max:20',
        ]);

        $checkNama = $validated['nama_room'] ?? $room->nama_room;
        $checkNomor = $validated['nomor_room'] ?? $room->nomor_room;

        $exists = Room::where('nama_room', $checkNama)
            ->where('nomor_room', $checkNomor)
            ->where('id_room', '!=', $id)
            ->exists();

        if ($exists) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Data sudah di tambahkan! ' . $checkNama . ' nomor ' . $checkNomor . ' sudah terdaftar.'
            ], 422);
        }

        $room->update($validated);

        return response()->json([
            'status'  => 'success', 
            'message' => 'Ruangan berhasil diperbarui', 
            'data'    => $room
        ], 200);
    }

    public function destroy($id)
    {
        $room = Room::find($id);
        if (!$room) {
            return response()->json([
                'status'  => 'error', 
                'message' => 'Data tidak ditemukan'
            ], 404);
        }

        $room->delete();

        return response()->json([
            'status'  => 'success', 
            'message' => 'Ruangan berhasil dihapus'
        ], 200);
    }
}