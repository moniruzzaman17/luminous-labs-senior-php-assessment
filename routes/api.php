<?php

use App\Http\Controllers\UpcomingEventController;
use Illuminate\Support\Facades\Route;

Route::get('/events/upcoming', UpcomingEventController::class);
