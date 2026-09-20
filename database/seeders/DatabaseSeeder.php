<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Storage;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $storages = collect([
            ['name' => 'Central warehouse', 'address' => 'Tashkent, Sergeli'],
            ['name' => 'Chilanzar depot', 'address' => 'Tashkent, Chilanzar'],
        ])->map(fn ($data) => Storage::create($data));

        collect([
            ['name' => 'Korzinka', 'phone' => '+998711000001'],
            ['name' => 'Makro', 'phone' => '+998711000002'],
            ['name' => 'Havas', 'phone' => '+998711000003'],
        ])->each(fn ($data) => Client::create($data));

        // Ahmad Tea: root category belongs to the provider, children hang under it.
        $ahmadTea = Provider::create(['name' => 'Ahmad Tea LLC', 'phone' => '+998712000001']);
        $root = Category::create(['name' => 'Ahmad Tea', 'provider_id' => $ahmadTea->id]);

        $black = Category::create(['name' => 'Black Tea', 'parent_id' => $root->id]);
        $green = Category::create(['name' => 'Green Tea', 'parent_id' => $root->id]);
        $white = Category::create(['name' => 'White Tea', 'parent_id' => $root->id]);

        Product::create(['category_id' => $black->id, 'name' => 'Ahmad Tea Earl Grey, 500g', 'price' => 62000]);
        Product::create(['category_id' => $black->id, 'name' => 'Ahmad Tea English Breakfast, 250g', 'price' => 38000]);
        Product::create(['category_id' => $green->id, 'name' => 'Ahmad Tea Green Jasmine, 200g', 'price' => 41000]);
        Product::create(['category_id' => $white->id, 'name' => 'Ahmad Tea White Peony, 100g', 'price' => 75000]);

        // A second provider, to prove products cannot be bought from the wrong one.
        $nestle = Provider::create(['name' => 'Nestle Uzbekistan', 'phone' => '+998712000002']);
        $nestleRoot = Category::create(['name' => 'Nestle', 'provider_id' => $nestle->id]);
        $coffee = Category::create(['name' => 'Instant Coffee', 'parent_id' => $nestleRoot->id]);

        Product::create(['category_id' => $coffee->id, 'name' => 'Nescafe Gold, 190g', 'price' => 89000]);

        $this->command->info('Seeded '.$storages->count().' storages, 3 clients, 2 providers, 6 products.');
    }
}
