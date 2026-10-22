<?php

namespace Database\Seeders;

use App\Models\BlogCategory;
use App\Models\BlogTag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class UpdateStoreBlogTagsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Delete outdated audio/keeb tags
        BlogTag::whereIn('name', ['Audiophile', 'MechanicalKeyboards', 'SetupInspo', 'HiResLossless', 'Travel'])->delete();

        // 2. Add business-tailored tags
        $storeTags = [
            'Pod Systems',
            'Disposable Vapes',
            'E-Liquids',
            'Flavors',
            'Device Care',
            'Battery Tips',
            'Hardware',
            'Guides',
            'Coils',
            'Safety',
            'Maintenance',
        ];

        foreach ($storeTags as $name) {
            BlogTag::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'slug' => Str::slug($name)]
            );
        }

        // 3. Rename obsolete audio categories to store categories
        $cat1 = BlogCategory::where('slug', 'audio-engineering-acoustics')->first();
        if ($cat1) {
            $cat1->update([
                'name' => 'Device Care & Maintenance',
                'slug' => 'device-care-maintenance',
                'description' => 'Battery care, coil priming routines, and hardware preservation guides.',
            ]);
        }

        $cat2 = BlogCategory::where('slug', 'ergonomics-workspace-tech')->first();
        if ($cat2) {
            $cat2->update([
                'name' => 'Flavors & E-Liquids',
                'slug' => 'flavors-and-e-liquids',
                'description' => 'Flavor profile breakdowns, PG/VG ratio guides, and blend comparisons.',
            ]);
        }

        $cat3 = BlogCategory::where('slug', 'software-firmware-updates')->first();
        if ($cat3) {
            $cat3->update([
                'name' => 'Store News & Announcements',
                'slug' => 'store-news-announcements',
                'description' => 'Official store announcements, catalog updates, and community news.',
            ]);
        }
    }
}
