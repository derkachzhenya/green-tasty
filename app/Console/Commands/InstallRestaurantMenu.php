<?php

namespace App\Console\Commands;

use App\Services\RestaurantMenuInstaller;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class InstallRestaurantMenu extends Command
{
    protected $signature = 'menu:install {--restaurant=* : Restaurant slugs; default: three Green Tasty locations} {--apply : Persist missing records; without this flag only preview}';

    protected $description = 'Install the bundled Green Tasty menu without overwriting existing data';

    public function handle(RestaurantMenuInstaller $installer): int
    {
        $requested = $this->option('restaurant');
        if (! is_array($requested)) {
            $this->error('Restaurant slugs must be supplied as repeatable --restaurant options.');

            return self::FAILURE;
        }
        $slugs = [];
        foreach ($requested as $slug) {
            if (! is_string($slug) || $slug === '') {
                $this->error('Restaurant slugs must be nonempty strings.');

                return self::FAILURE;
            }
            $slugs[] = $slug;
        }
        $slugs = array_values(array_unique($slugs ?: $installer->defaultSlugs()));
        try {
            $installer->validateTargets($slugs);
            $catalog = $installer->catalog();
            $this->line('Environment: '.app()->environment().'; connection: '.config('database.default').'; database: '.(string) config('database.connections.'.config('database.default').'.database'));
            $this->line('Restaurants: '.implode(', ', $slugs));
            $this->line('Bundled menu: '.count($catalog['dishes']).' dishes; '.count($catalog['categories']).' categories per location.');
            if (! $this->option('apply')) {
                $this->info('Preview only. No rows changed. Add --apply to insert missing records.');

                return self::SUCCESS;
            }
            $result = $installer->install($slugs);
            foreach ($result as $kind => $count) {
                $this->line('Created '.$kind.': '.$count);
            }
            $this->info('Menu installed. Existing rows, prices, availability and reservations preserved.');

            return self::SUCCESS;
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
