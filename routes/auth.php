<?php

use Illuminate\Support\Facades\Route;


Route::group(['namespace' => 'App\Http\Controllers\Auth'], function() 
{
    Route::controller(AdminController::class)->group(function () {


    });






});

