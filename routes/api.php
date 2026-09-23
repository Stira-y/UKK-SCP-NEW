<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\SchoolClassController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\JournalController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\LeaveRequestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return response()->json([
            'status' => 'success',
            'data' => $request->user()->load(['role', 'schoolClass'])
        ]);
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    // Master Data Routes
    Route::apiResource('classes', SchoolClassController::class);
    Route::apiResource('rooms', RoomController::class);
    Route::apiResource('subjects', SubjectController::class);
    Route::apiResource('users', UserController::class);
    Route::apiResource('journals', JournalController::class);
    Route::apiResource('attendances', AttendanceController::class);
    Route::apiResource('leave-requests', LeaveRequestController::class);
    Route::post('/profile/update', [UserController::class, 'updateProfile']);
});
