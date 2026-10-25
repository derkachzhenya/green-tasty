<?php

namespace App\Services;

use App\Models\Restaurant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class RestaurantMenuInstaller
{
    /** @return array{categories: list<array{slug: string, name: string}>, dishes: list<array{name: string, category: string, price: string, available: bool, image: string, weight: string|null, crop: bool}>} */
    public function catalog(): array
    {
        try {
            $catalog = json_decode(File::get(resource_path('data/restaurant-menu.json')), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid menu catalog: malformed JSON.', previous: $exception);
        }
        if (! is_array($catalog) || ! isset($catalog['categories'], $catalog['dishes'])
            || ! is_array($catalog['categories']) || ! array_is_list($catalog['categories']) || $catalog['categories'] === []
            || ! is_array($catalog['dishes']) || ! array_is_list($catalog['dishes']) || $catalog['dishes'] === []) {
            throw new RuntimeException('Invalid menu catalog: nonempty category and dish lists are required.');
        }
        $categories = [];
        $slugs = [];
        foreach ($catalog['categories'] as $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException('Invalid menu catalog: category must be an object.');
            }
            $slug = $this->text($entry['slug'] ?? null, 'category slug');
            $name = $this->text($entry['name'] ?? null, 'category name');
            if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1 || isset($slugs[$slug])) {
                throw new RuntimeException('Invalid menu catalog: category slugs must be valid and unique.');
            }
            $slugs[$slug] = true;
            $categories[] = ['slug' => $slug, 'name' => $name];
        }
        $dishes = [];
        $names = [];
        $assetRoot = realpath(public_path('images/green-tasty/dishes'));
        foreach ($catalog['dishes'] as $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException('Invalid menu catalog: dish must be an object.');
            }
            $name = $this->text($entry['name'] ?? null, 'dish name');
            $category = $this->text($entry['category'] ?? null, 'dish category');
            $price = $entry['price'] ?? null;
            $available = $entry['available'] ?? null;
            $crop = $entry['crop'] ?? null;
            $weight = $entry['weight'] ?? null;
            if (! is_string($price) || preg_match('/\A(?:0|[1-9][0-9]{0,7})\.[0-9]{2}\z/', $price) !== 1) {
                throw new RuntimeException('Invalid menu catalog: prices must be nonnegative decimal strings with two places, within decimal(10,2).');
            }
            if (! isset($slugs[$category]) || isset($names[$category][mb_strtolower($name)])) {
                throw new RuntimeException('Invalid menu catalog: dishes must use known categories and unique names within each category.');
            }
            if (! is_bool($available) || ! is_bool($crop)) {
                throw new RuntimeException('Invalid menu catalog: availability and crop must be JSON booleans.');
            }
            if ($weight !== null) {
                $weight = $this->text($weight, 'dish weight', 32);
            }
            $image = $this->text($entry['image'] ?? null, 'dish image', 2048);
            $path = realpath(public_path(ltrim($image, '/')));
            if (! str_starts_with($image, '/images/green-tasty/dishes/') || str_contains($image, '..')
                || preg_match('/\.(?:png|jpe?g|webp|avif)\z/i', $image) !== 1
                || $assetRoot === false || $path === false || ! is_file($path)
                || ! str_starts_with($path, $assetRoot.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Invalid menu catalog: image must be an existing bundled dish asset.');
            }
            $names[$category][mb_strtolower($name)] = true;
            $dishes[] = ['name' => $name, 'category' => $category, 'price' => $price, 'available' => $available,
                'image' => $image, 'weight' => $weight, 'crop' => $crop];
        }

        return ['categories' => $categories, 'dishes' => $dishes];
    }

    private function text(mixed $value, string $field, int $limit = 255): string
    {
        if (! is_string($value) || $value === '' || trim($value) !== $value || mb_strlen($value) > $limit
            || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
            throw new RuntimeException('Invalid menu catalog: invalid '.$field.'.');
        }

        return $value;
    }

    /** @return list<string> */
    public function defaultSlugs(): array
    {
        return array_column($this->locations(), 'demoSlug');
    }

    /** @param list<string> $slugs */
    public function validateTargets(array $slugs): void
    {
        if ($slugs === []) {
            throw new InvalidArgumentException('Select at least one restaurant.');
        }
        foreach ($slugs as $slug) {
            if (! in_array($slug, $this->defaultSlugs(), true) && ! Restaurant::where('slug', $slug)->exists()) {
                throw new InvalidArgumentException('Unknown restaurant: '.$slug);
            }
        }
        $this->catalog();
    }

    /**
     * Insert missing catalog rows only. Never reset prices, availability or existing relationships.
     *
     * @param  list<string>  $slugs
     * @return array{restaurants: int, tables: int, categories: int, dishes: int}
     */
    public function install(array $slugs): array
    {
        $this->validateTargets($slugs);
        $catalog = $this->catalog();
        $locations = array_column($this->locations(), null, 'demoSlug');
        sort($slugs);

        return DB::transaction(function () use ($slugs, $catalog, $locations): array {
            $created = ['restaurants' => 0, 'tables' => 0, 'categories' => 0, 'dishes' => 0];
            foreach (array_unique($slugs) as $slug) {
                $restaurant = Restaurant::where('slug', $slug)->lockForUpdate()->first();
                if ($restaurant === null) {
                    if (! isset($locations[$slug])) {
                        throw new InvalidArgumentException('Restaurant disappeared: '.$slug);
                    }
                    $index = array_search($slug, $this->defaultSlugs(), true);
                    $restaurant = Restaurant::firstOrCreate(['slug' => $slug], [
                        'name' => 'Green Tasty - Location '.($index + 1),
                        'address' => $locations[$slug]['address'],
                        'is_active' => true,
                    ]);
                    $created['restaurants'] += (int) $restaurant->wasRecentlyCreated;
                    $restaurant = Restaurant::whereKey($restaurant->id)->lockForUpdate()->firstOrFail();
                    // Tables are bootstrapped only for a new installation; existing locations are untouched.
                    foreach ([2, 4, 6] as $tableIndex => $seats) {
                        $table = $restaurant->tables()->firstOrCreate(['name' => 'Table '.($tableIndex + 1)], ['seats' => $seats, 'is_active' => true]);
                        $created['tables'] += (int) $table->wasRecentlyCreated;
                    }
                }
                $categories = [];
                foreach ($catalog['categories'] as $index => $definition) {
                    $category = $restaurant->menuCategories()->firstOrCreate(['slug' => $definition['slug']], [
                        'name' => $definition['name'], 'sort_order' => $index, 'is_active' => true,
                    ]);
                    $created['categories'] += (int) $category->wasRecentlyCreated;
                    $categories[$definition['slug']] = $category;
                }
                foreach ($catalog['dishes'] as $index => $definition) {
                    $dish = $categories[$definition['category']]->items()->firstOrCreate(['name' => $definition['name']], [
                        'price' => $definition['price'], 'is_available' => $definition['available'], 'sort_order' => $index,
                    ]);
                    $created['dishes'] += (int) $dish->wasRecentlyCreated;
                }
            }

            return $created;
        }, attempts: 3);
    }

    /** @return list<array{demoSlug: string, address: string}> */
    private function locations(): array
    {
        return json_decode(File::get(resource_path('data/homepage.json')), true, flags: JSON_THROW_ON_ERROR)['locations'];
    }
}
