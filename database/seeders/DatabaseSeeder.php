<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            UserSeeder::class,
            CategorySeeder::class,
            ModifierSeeder::class,
            ProductSeeder::class,
            IngredientSeeder::class,
            RecipeSeeder::class,
            SupplierSeeder::class,
            PaymentTenderSeeder::class,
            PageSectionSeeder::class,
        ]);
    }
}
