<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Enums\ServiceState;
use App\Models\Check;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class BuildUptimeStrip
{
    /**
     * One bucket per day per service, oldest first, with empty days filled in so every
     * service returns exactly $days slots.
     *
     * This is the one hot query in the app: it scans the (service_id, checked_at) index
     * over roughly a million rows. A short cache is what keeps it off the page-load path;
     * a rollup table would be a second source of truth for no additional benefit.
     *
     * @return array<int, array<int, array{date: string, state: string, uptime: float|null}>>
     */
    public function handle(int $days = 60): array
    {
        return Cache::remember(
            "uptime-strip:{$days}",
            60,
            fn (): array => $this->build($days),
        );
    }

    /** @return array<int, array<int, array{date: string, state: string, uptime: float|null}>> */
    private function build(int $days): array
    {
        $since = CarbonImmutable::today()->subDays($days - 1);

        $buckets = Check::query()
            ->selectRaw('service_id')
            ->selectRaw('date(checked_at) as day')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when state = ? then 1 else 0 end) as down_count', [ServiceState::Down->value])
            ->selectRaw('sum(case when state = ? then 1 else 0 end) as degraded_count', [ServiceState::Degraded->value])
            ->where('checked_at', '>=', $since)
            ->groupBy('service_id', 'day')
            ->get()
            ->groupBy('service_id');

        $timeline = collect(range(0, $days - 1))
            ->map(fn (int $offset): string => $since->addDays($offset)->toDateString());

        $strips = [];

        foreach ($buckets as $serviceId => $rows) {
            /** @var Collection<int, Model> $rows */
            $byDay = $rows->keyBy('day');

            $strips[(int) $serviceId] = $timeline
                ->map(fn (string $date): array => $this->slot($date, $byDay->get($date)))
                ->all();
        }

        return $strips;
    }

    /** @return array{date: string, state: string, uptime: float|null} */
    private function slot(string $date, ?Model $row): array
    {
        if ($row === null) {
            return ['date' => $date, 'state' => 'none', 'uptime' => null];
        }

        $total = $this->toInt($row->getAttribute('total'));
        $down = $this->toInt($row->getAttribute('down_count'));
        $degraded = $this->toInt($row->getAttribute('degraded_count'));

        $state = match (true) {
            $down > 0 => ServiceState::Down->value,
            $degraded > 0 => ServiceState::Degraded->value,
            default => ServiceState::Up->value,
        };

        return [
            'date' => $date,
            'state' => $state,
            'uptime' => round((($total - $down) / $total) * 100, 2),
        ];
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
