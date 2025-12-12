<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReaderController;

Route::get('/reader/{bookId}', [ReaderController::class, 'index']);
