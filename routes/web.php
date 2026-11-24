<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/docs');
});

Route::get('/docs', function () {
    return view('swagger');
});

Route::get('/docs/openapi.yaml', function () {
    $path = base_path('docs/openapi.yaml');
    abort_unless(is_file($path), 404);

    return response()->file($path, [
        'Content-Type' => 'application/yaml; charset=UTF-8',
        'Cache-Control' => 'no-store',
    ]);
});
