<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AllUsersSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('&xzR2WsA&xzR2WsA');

        $users = [
            [
                'name'  => 'John Adrian Bacon',
                'email' => 'john.adrian.bacon2@gmail.com',
                'role'  => 'admin',
            ],
            [
                'name'  => 'Cashier',
                'email' => 'cashier@theboys.com',
                'role'  => 'cashier',
            ],
            [
                'name'  => 'Kitchen Staff',
                'email' => 'kitchen@theboys.com',
                'role'  => 'kitchen',
            ],
            [
                'name'  => 'Auditor',
                'email' => 'auditor@theboys.com',
                'role'  => 'auditor',
            ],
        ];

        foreach ($users as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name'              => $data['name'],
                    'password'          => $password,
                    'email_verified_at' => now(),
                ]
            );

            $user->syncRoles([$data['role']]);
        }
    }
}
