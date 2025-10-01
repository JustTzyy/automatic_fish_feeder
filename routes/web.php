<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FeedScheduleController;


Route::get('/', function () {
    return view('welcome');
});


Route::get('/schedule', [FeedScheduleController::class, 'index'])->name('schedule.index');
Route::post('/schedule', [FeedScheduleController::class, 'update'])->name('schedule.update');
Route::post('/next-feed', [FeedScheduleController::class, 'nextFeed'])->name('schedule.nextFeed');
Route::get('/auto-feed', [FeedScheduleController::class, 'autoFeed'])->name('schedule.autoFeed');
Route::get('/schedule/timer', [FeedScheduleController::class, 'getTimer'])->name('schedule.timer');
Route::get('/diagnose-python', [FeedScheduleController::class, 'diagnose']);