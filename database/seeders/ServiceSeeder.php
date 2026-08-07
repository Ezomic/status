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
     * Anything beyond name and url is optional and falls back to the column defaults.
     *
     * @var list<array{name: string, url: string, expected_body?: string, is_public?: bool}>
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
        ['name' => 'Tempo', 'url' => 'https://tempo.thijssensoftware.nl', 'is_public' => true],
        // Tempo's Garmin sidecar fails independently of the web app: when it stops,
        // tempo.thijssensoftware.nl keeps answering 200 while every sync fails. It is
        // bound to loopback by design, so this is only reachable from the droplet and
        // must never appear on the public page. The body check stops a process that
        // answers but cannot write its token store from counting as healthy.
        [
            'name' => 'Tempo Garmin sidecar',
            'url' => 'http://127.0.0.1:8790/health',
            'expected_body' => '"status":"ok"',
            'is_public' => false,
        ],
    ];

    public function run(): void
    {
        foreach (self::SERVICES as $service) {
            // Keyed on url so re-running never disturbs a service that has since been
            // retuned by hand in the app. The fallbacks restate the column defaults, so
            // an entry that omits them seeds exactly as it did before.
            Service::firstOrCreate(['url' => $service['url']], [
                'name' => $service['name'],
                'expected_body' => $service['expected_body'] ?? null,
                'is_public' => $service['is_public'] ?? false,
            ]);
        }
    }
}
