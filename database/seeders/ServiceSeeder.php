<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    /**
     * The workflow apps that are actually deployed. Shop, Stocks, Groceries and String
     * Theory are deliberately absent: those hostnames do not resolve yet, and seeding
     * them would report a permanent outage for something that was never up.
     *
     * @var list<array{name: string, url: string}>
     */
    private const SERVICES = [
        ['name' => 'Thijssensoftware ID', 'url' => 'https://id.thijssensoftware.nl'],
        ['name' => 'Tracker', 'url' => 'https://tracker.thijssensoftware.nl'],
        ['name' => 'Zero', 'url' => 'https://zero.thijssensoftware.nl'],
        ['name' => 'Billr', 'url' => 'https://billr.thijssensoftware.nl'],
        ['name' => 'Portfolio CMS', 'url' => 'https://thijssensoftware.nl'],
        ['name' => 'Finance', 'url' => 'https://finance.thijssensoftware.nl'],
        ['name' => 'Hablas', 'url' => 'https://hablas.thijssensoftware.nl'],
        ['name' => 'Chronos', 'url' => 'https://chronos.thijssensoftware.nl'],
        ['name' => 'OB Weekregistratie', 'url' => 'https://obw.thijssensoftware.nl'],
    ];

    public function run(): void
    {
        foreach (self::SERVICES as $service) {
            Service::firstOrCreate(['url' => $service['url']], ['name' => $service['name']]);
        }
    }
}
