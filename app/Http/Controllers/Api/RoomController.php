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
        return response()->json(['status' => 'success', 'data' => $rooms], 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'room_name' => 'required|string|max:50',
        ]);

        $room = Room::create($request->all());
        return response()->json(['status' => 'success', 'message' => 'Ruangan berhasil ditambahkan', 'data' => $room], 201);
    }

    public function show($id)
    {
        $room = Room::find($id);
        if (!$room) {
            return response()->json(['status' => 'error', 'message' => 'Data tidak ditemukan'], 404);
        }
        return response()->json(['status' => 'success', 'data' => $room], 200);
    }

    public function update(Request $request, $id)
    {
        $room = Room::find($id);
        if (!$room) {
            return response()->json(['status' => 'error', 'message' => 'Data tidak ditemukan'], 404);
        }

        $request->validate([
            'room_name' => 'required|string|max:50',
        ]);

        $room->update($request->all());
        return response()->json(['status' => 'success', 'message' => 'Ruangan berhasil diperbarui', 'data' => $room], 200);
    }

    public function destroy($id)
    {
        $room = Room::find($id);
        if (!$room) {
            return response()->json(['status' => 'error', 'message' => 'Data tidak ditemukan'], 404);
        }

        $room->delete();
        return response()->json(['status' => 'success', 'message' => 'Ruangan berhasil dihapus'], 200);
    }
}