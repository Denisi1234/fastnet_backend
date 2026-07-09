<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Property;
use App\Models\Room;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create a host user
        $host = User::create([
            'name' => 'John Doe',
            'email' => 'host@fastnet.com',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'phone_number' => '+255 712 345 678',
        ]);

        // Create a regular guest user
        User::create([
            'name' => 'Alice Traveler',
            'email' => 'traveler@fastnet.com',
            'password' => Hash::make('password'),
            'role' => 'customer',
            'phone_number' => '+255 789 999 888',
        ]);

        // 2. Define standard properties matching the mobile app mock list
        $propertiesData = [
            [
                'name' => 'Palm Garden Lodge',
                'city' => 'Dar es Salaam',
                'area' => 'Mikocheni',
                'price_per_night' => 85000,
                'description' => 'Clean private room with air conditioning and secure parking.',
                'image_url' => 'assets/images/home.webp',
                'latitude' => -6.7780,
                'longitude' => 39.2345,
            ],
            [
                'name' => 'Sabasaba Comfort Rooms',
                'city' => 'Dodoma',
                'area' => 'Sabasaba',
                'price_per_night' => 45000,
                'description' => 'Quiet room near transport, shops, and local food places.',
                'image_url' => 'assets/images/room.webp',
                'latitude' => -6.1664,
                'longitude' => 35.7443,
            ],
            [
                'name' => 'Mtumba Executive Stay',
                'city' => 'Dodoma',
                'area' => 'Mtumba',
                'price_per_night' => 65000,
                'description' => 'Modern room close to government offices and main roads.',
                'image_url' => 'assets/images/home2.webp',
                'latitude' => -6.2167,
                'longitude' => 35.8895,
            ],
            [
                'name' => 'Kisasa Family Lodge',
                'city' => 'Dodoma',
                'area' => 'Kisasa',
                'price_per_night' => 95000,
                'description' => 'Spacious room for family stays with a calm compound.',
                'image_url' => 'assets/images/house2.webp',
                'latitude' => -6.1552,
                'longitude' => 35.7924,
            ],
            [
                'name' => 'Kariakoo Budget Lodge',
                'city' => 'Dar es Salaam',
                'area' => 'Kariakoo',
                'price_per_night' => 35000,
                'description' => 'Simple affordable room close to the market and bus routes.',
                'image_url' => 'assets/images/house3.webp',
                'latitude' => -6.8182,
                'longitude' => 39.2783,
            ],
            [
                'name' => 'Njiro Garden Rooms',
                'city' => 'Arusha',
                'area' => 'Njiro',
                'price_per_night' => 70000,
                'description' => 'Comfortable lodge room with garden space and mountain air.',
                'image_url' => 'assets/images/house4.webp',
                'latitude' => -3.4005,
                'longitude' => 36.7103,
            ],
            [
                'name' => 'Zanzibar Sunset Beach Villa',
                'city' => 'Zanzibar',
                'area' => 'Nungwi',
                'price_per_night' => 185000,
                'description' => 'Stunning luxury villa directly on the sands of Nungwi beach with pool access.',
                'image_url' => 'assets/images/home.webp',
                'latitude' => -5.7335,
                'longitude' => 39.2974,
            ],
            [
                'name' => 'Arusha Safari Lodge',
                'city' => 'Arusha',
                'area' => 'Sakina',
                'price_per_night' => 120000,
                'description' => 'Perfect safari chalet with panoramic views of Mount Meru and cozy fireplace.',
                'image_url' => 'assets/images/home2.webp',
                'latitude' => -3.3662,
                'longitude' => 36.6661,
            ],
            [
                'name' => 'Mbezi Beach Resort Suite',
                'city' => 'Dar es Salaam',
                'area' => 'Mbezi Beach',
                'price_per_night' => 150000,
                'description' => 'Elegant resort apartment steps away from Mbezi beach shores with modern kitchen.',
                'image_url' => 'assets/images/house2.webp',
                'latitude' => -6.7192,
                'longitude' => 39.2274,
            ],
            [
                'name' => 'Golden Crest Executive Stay',
                'city' => 'Dodoma',
                'area' => 'Area D',
                'price_per_night' => 80000,
                'description' => 'Premium executive room with business desk, high-speed fiber internet, and gym access.',
                'image_url' => 'assets/images/house3.webp',
                'latitude' => -6.1650,
                'longitude' => 35.7601,
            ],
        ];

        foreach ($propertiesData as $prop) {
            $property = Property::create(array_merge($prop, [
                'host_id' => $host->id,
                'address' => $prop['area'] . ', ' . $prop['city'],
            ]));

            // Add 5 rooms for this property
            for ($r = 1; $r <= 5; $r++) {
                Room::create([
                    'property_id' => $property->id,
                    'room_number' => 'Room ' . (100 + $r),
                    'status' => 'available',
                ]);
            }
        }
    }
}
