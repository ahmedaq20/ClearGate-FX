<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\ReceiptController;


Route::get('/', function () {
    return view('welcome');
});




Route::get('receipts/{transaction}', [ReceiptController::class, 'show'])->name('receipts.show');
