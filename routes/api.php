<?php

use App\Http\Controllers\Api\WhatsappRoutineController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->group(function () {
    // Routines API - WhatsApp messaging for Claude Routines
    Route::post('/routines/whatsapp/send', [WhatsappRoutineController::class, 'sendToAdmin']);
    Route::post('/routines/whatsapp/send-to', [WhatsappRoutineController::class, 'sendToNumber']);
});
