<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Workbench\Database\Factories\UserFactory;

final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = UserFactory::new()->create([
            'name'  => 'Test User',
            'email' => 'test@example.com',
        ]);

        DB::table('legacy_sessions')->insert([
            'id'            => 'legacy-test-session',
            'payload'       => base64_encode(serialize(['user_id' => $user->id])),
            'last_activity' => now()->timestamp,
        ]);
    }
}
