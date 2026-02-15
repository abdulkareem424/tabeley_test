<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$role = \App\Models\Role::firstOrCreate(['name' => 'admin']);

$user = \App\Models\User::firstOrCreate(
    ['email' => 'admin@admin.admin'],
    [
        'first_name' => 'Admin',
        'last_name' => 'User',
        'phone' => '0000000000',
        'password' => '000000',
    ]
);

$user->roles()->syncWithoutDetaching([$role->id]);

echo "OK\n";
