<?php

use App\Http\Controllers\Alfar\AttendanceController;
use App\Http\Controllers\Alfar\AttendanceReportController;
use App\Http\Controllers\Alfar\DeleteController;
use App\Http\Controllers\Alfar\ManageController;
use App\Http\Controllers\Alfar\NotificationController;
use App\Http\Controllers\Alfar\PasswordController;
use App\Http\Controllers\Alfar\ScheduleController;
use App\Http\Controllers\Alfar\WorkController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');
Route::post('/api/fingerprint/events', [AttendanceController::class, 'fingerprint'])->middleware('throttle:120,1');
Route::middleware(['auth'])->group(function () {
    Route::get('/password/change', [PasswordController::class, 'edit']);
    Route::post('/password/change', [PasswordController::class, 'update'])->middleware('throttle:6,1');
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/feed', [NotificationController::class, 'feed']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'read']);
    Route::delete('/notifications', [NotificationController::class, 'destroyAll']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::get('/dashboard', [WorkController::class, 'index'])->name('dashboard');
    Route::post('/schedules/bulk', [ScheduleController::class, 'bulk']);
    Route::delete('/manage/{entity}/{id}', [DeleteController::class, 'destroy']);
    Route::get('/employees/{id}/calendar', [ScheduleController::class, 'calendar']);
    Route::delete('/employees/{id}/schedules', [ScheduleController::class, 'destroy']);
    Route::get('/attendance/report', [AttendanceReportController::class, 'index']);
    Route::post('/manage/{entity}', [ManageController::class, 'store']);
    Route::post('/targets/{id}/submit', [WorkController::class, 'submit']);
    Route::post('/targets/{id}/validate', [WorkController::class, 'validateSubmission']);
    Route::post('/targets/{id}/correct', [WorkController::class, 'correctSubmission']);
    Route::post('/reviews', [WorkController::class, 'review']);
    Route::post('/reviews/{id}/respond', [WorkController::class, 'respond']);
    Route::get('/evidence/{id}', [WorkController::class, 'evidence']);
    Route::post('/attendance/qr', [AttendanceController::class, 'qr'])->middleware('throttle:attendance-qr');
    Route::post('/attendance/scan', [AttendanceController::class, 'scan'])->middleware('throttle:120,1');
    Route::post('/attendance/clock', [AttendanceController::class, 'clock'])->middleware('throttle:12,1');
    Route::post('/attendance/requests', [AttendanceController::class, 'requestChange']);
    Route::post('/attendance/requests/{id}/decide', [AttendanceController::class, 'decide']);
    Route::post('/attendance/import', [AttendanceController::class, 'import']);
});

require __DIR__.'/settings.php';
