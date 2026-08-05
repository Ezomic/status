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
     * @return array<int, array<int, array{date: string, state: string, uptime: float|null, maintenance: bool}>>
     */
    public function handle(int $days = 60): array
    {
        return Cache::remember(
            "uptime-strip:{$days}",
            60,
            fn (): array => $this->build($days),
        );
    }

    /** @return array<int, array<int, array{date: string, state: string, uptime: float|null, maintenance: bool}>> */
    private function build(int $days): array
    {
        $since = CarbonImmutable::today()->subDays($days - 1);

        $buckets = Check::query()
            ->selectRaw('service_id')
            ->selectRaw('date(checked_at) as day')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when state = ? then 1 else 0 end) as down_count', [ServiceState::Down->value])
            ->selectRaw('sum(case when state = ? then 1 else 0 end) as degraded_count', [ServiceState::Degraded->value])
            ->selectRaw('sum(case when state = ? then 1 else 0 end) as maintenance_count', [ServiceState::Maintenance->value])
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

    /** @return array{date: string, state: string, uptime: float|null, maintenance: bool} */
    private function slot(string $date, ?Model $row): array
    {
        if ($row === null) {
            return ['date' => $date, 'state' => 'none', 'uptime' => null, 'maintenance' => false];
        }

        $total = $this->toInt($row->getAttribute('total'));
        $down = $this->toInt($row->getAttribute('down_count'));
        $degraded = $this->toInt($row->getAttribute('degraded_count'));
        $maintenance = $this->toInt($row->getAttribute('maintenance_count'));

        // Maintenance leaves the ratio entirely rather than counting either way:
        // availability is not measurable while a service is deliberately offline,
        // and counting it as up would let a long deploy inflate the number.
        $measured = $total - $maintenance;

        // Worst thing seen that day wins. Maintenance only claims the slot when the
        // whole day was maintenance: a three minute deploy should not repaint an
        // otherwise healthy day, so a mixed day reads as up and says "some
        // maintenance" in the tooltip instead.
        $state = match (true) {
            $down > 0 => ServiceState::Down->value,
            $degraded > 0 => ServiceState::Degraded->value,
            $measured === 0 => ServiceState::Maintenance->value,
            default => ServiceState::Up->value,
        };

        return [
            'date' => $date,
            'state' => $state,
            'uptime' => $measured > 0
                ? round((($measured - $down) / $measured) * 100, 2)
                : null,
            // Kept separate from $state so a mixed day can read as up and still say a
            // deploy happened, rather than the deploy hiding the day's real story.
            'maintenance' => $maintenance > 0,
        ];
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
