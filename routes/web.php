<?php

use App\Http\Controllers\DocusignController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('docusign',[DocusignController::class, 'index'])->name('docusign');

Route::get('connect-docusign',[DocusignController::class, 'connectDocusign'])->name('connect.docusign');

Route::get('docusign/callback',[DocusignController::class,'callback'])->name('docusign.callback');

Route::get('sign-document',[DocusignController::class,'signDocument'])->name('docusign.sign');