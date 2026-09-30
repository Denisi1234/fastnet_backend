<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use App\Models\Property;
use App\Models\Room;

class PropertySeeder extends Seeder
{
    /**
     * Seed property and room storage image files from available local assets.
     */
    public function run(): void
    {
        $storageDir = storage_path('app/public/properties');
        if (!File::isDirectory($storageDir)) {
            File::makeDirectory($storageDir, 0755, true, true);
        }

        // Available real hotel/lodge image assets in the web project
        $sourceImages = [
            '/home/hp/Documents/web/webroot/assets/img/property/img-1.jpg',
            '/home/hp/Documents/web/webroot/assets/img/property/img-2.jpg',
            '/home/hp/Documents/web/webroot/assets/img/property/img-3.jpg',
            '/home/hp/Documents/web/webroot/assets/img/property/img-4.jpg',
            '/home/hp/Documents/web/webroot/assets/img/property/img-5.jpg',
            '/home/hp/Documents/web/webroot/assets/img/property/img-6.jpg',
            '/home/hp/Documents/web/webroot/assets/img/property/img-7.jpg',
            '/home/hp/Documents/web/webroot/assets/img/property/img-8.jpg',
            '/home/hp/Documents/web/webroot/assets/img/banner-hotel.jpg',
        ];

        // Filter for existing source files
        $validSources = array_values(array_filter($sourceImages, fn($path) => file_exists($path)));
        if (empty($validSources)) {
            $this->command?->warn('No source property images found to copy.');
            return;
        }

        $sourceCount = count($validSources);
        $sourceIndex = 0;

        // Collect all target filenames from Properties
        $properties = Property::all();
        foreach ($properties as $property) {
            if ($property->image_url) {
                $filename = basename(parse_url($property->image_url, PHP_URL_PATH));
                if ($filename && !file_exists($storageDir . '/' . $filename)) {
                    copy($validSources[$sourceIndex % $sourceCount], $storageDir . '/' . $filename);
                    $this->command?->info("Seeded property image: {$filename}");
                    $sourceIndex++;
                }
            }
        }

        // Collect all target filenames from Rooms
        $rooms = Room::all();
        foreach ($rooms as $room) {
            $photos = $room->photos;
            if (is_string($photos)) {
                $photos = json_decode($photos, true) ?: [];
            }
            if (is_array($photos)) {
                foreach ($photos as $photoUrl) {
                    if (is_string($photoUrl)) {
                        $filename = basename(parse_url($photoUrl, PHP_URL_PATH));
                        if ($filename && !file_exists($storageDir . '/' . $filename)) {
                            copy($validSources[$sourceIndex % $sourceCount], $storageDir . '/' . $filename);
                            $this->command?->info("Seeded room image: {$filename}");
                            $sourceIndex++;
                        }
                    }
                }
            }
        }

        // Specifically guarantee img_6a89cd8f6b138.jpeg exists
        $sunriseMain = 'img_6a89cd8f6b138.jpeg';
        if (!file_exists($storageDir . '/' . $sunriseMain)) {
            copy($validSources[0], $storageDir . '/' . $sunriseMain);
            $this->command?->info("Guaranteed Sunrise Lodge main image: {$sunriseMain}");
        }

        $fileCount = count(scandir($storageDir)) - 2;
        $this->command?->info("PropertySeeder completed successfully. Files in {$storageDir}: {$fileCount}");
    }
}
