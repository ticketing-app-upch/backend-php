<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    private const BASELINE_START = '2026-11-04 15:00:00';

    private const FALLBACK_ZONE_CAPACITY = [
        'Entrada general' => 8000,
        'Pase degustación' => 1500,
    ];

    private const FOOTBALL_BG = [
        '1431324155629-1a6deb1dec8d',
        '1489944440615-453fc2b6a9a9',
    ];

    private const BY_KEYWORD = [
        '/sinf[oó]nic|sinfon[ií]a|orquesta|filarm[oó]nic|c[aá]mara/u' => [
            '1519683384663-c9b34271669a',
            '1465847899084-d164df4dedc6',
        ],
        '/marat[oó]n|running|trote|10k|21k|42k/u' => [
            '1452626038306-9aae5e071dd3',
            '1476480862126-209bfaa8edc6',
        ],
        '/gastron[oó]mic|sabores|feria|food\s*truck|culinari/u' => ['/events/perú.jpg'],
    ];

    private const BY_VENUE = [
        '/dib[oó]s/u' => '/events/coliseo-dibos.jpg',
        '/teatro municipal/u' => '/events/teatro-municipal-lima.jpg',
        '/parque de la exposici/u' => '/events/parque-exposicion.jpg',
    ];

    private const BY_CATEGORY = [
        'CONCIERTO' => [
            '1470229722913-7c0e2dbbafd3',
            '1493225457124-a3eb161ffa5f',
            '1501386761578-eac5c94b800a',
            '1516450360452-9312f5e86fc7',
        ],
        'DEPORTE' => [
            '1522778119026-d647f0596c20',
            '1431324155629-1a6deb1dec8d',
            '1489944440615-453fc2b6a9a9',
            '1553778263-73a83bab9b0c',
        ],
        'TEATRO' => [
            '1503095396549-807759245b35',
            '1507924538820-ede94a04019d',
            '1516307365426-bea591f05011',
        ],
        'FESTIVAL' => [
            '1514525253161-7a46d19cd819',
            '1470225620780-dba8ba36b745',
            '1506157786151-b8491531f063',
            '1459749411175-04bf5292ceea',
        ],
        'CONFERENCIA' => [
            '1540575467063-178a50c2df87',
            '1587825140708-dfaf72ae4b04',
            '1475721027785-f74eccf877e2',
            '1591115765373-5207764f72e7',
        ],
    ];

    public function run(): void
    {
        $organizer = $this->demoOrganizer();
        $this->demoClient();

        $catalog = json_decode(
            file_get_contents(database_path('data/frontend-events.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $shiftMinutes = $this->shiftMinutes();

        foreach ($catalog as $item) {
            $venue = Venue::where('name', $item['venue'])->first();

            if (! $venue) {
                $this->command?->warn('DemoDataSeeder omitió "'.$item['name'].'": no existe el recinto '.$item['venue'].'.');

                continue;
            }

            $zones = $this->resolveZones($item['zones'], $venue);

            if ($zones === null) {
                $this->command?->warn('DemoDataSeeder omitió "'.$item['name'].'": falta el aforo de una zona.');

                continue;
            }

            $event = Event::firstOrCreate(['title' => $item['name']], [
                'organizer_id' => $organizer->id,
                'venue_id' => $venue->id,
                'description' => $item['description'],
                'category' => $item['category'],
                'venue_name' => $venue->name,
                'venue_address' => $venue->address,
                'venue_capacity' => $venue->capacity,
                'starts_at' => $this->startsAt($item['startsAt'], $shiftMinutes),
                'ends_at' => null,
                'status' => 'PUBLISHED',
                'city' => $venue->city,
                'image_url' => $this->demoImageUrl($item['name'], $item['category'], $item['venue']),
                'published_at' => now(),
                'max_per_order' => $item['maxPerOrder'],
            ]);

            foreach ($zones as $zone) {
                $event->zones()->firstOrCreate(['name' => $zone['name']], $zone);
            }
        }
    }

    private function demoOrganizer(): User
    {
        $role = Role::where('name', 'ORGANIZER')->firstOrFail();
        $user = User::firstOrCreate(['email' => 'organizador@tkt.pe'], [
            'full_name' => 'José Manuel Ames',
            'password' => 'Organizador1$',
            'role_id' => $role->id,
            'active' => true,
            'terms_accepted_at' => now(),
        ]);
        $user->organizerProfile()->firstOrCreate([], [
            'tax_id' => '20123456789',
            'details' => ['displayName' => 'Ames Producciones'],
        ]);

        return $user;
    }

    private function demoClient(): void
    {
        $role = Role::where('name', 'CLIENT')->firstOrFail();
        $user = User::firstOrCreate(['email' => 'cliente@tkt.pe'], [
            'full_name' => 'Juan Aguilar',
            'password' => 'Cliente1$',
            'role_id' => $role->id,
            'active' => true,
            'terms_accepted_at' => now(),
        ]);
        $user->clientProfile()->firstOrCreate([], ['doc_number' => '12345678']);
    }

    private function shiftMinutes(): int
    {
        $timezone = config('ticketing.venue_timezone');
        $baseline = CarbonImmutable::parse(self::BASELINE_START, $timezone);
        $anchor = CarbonImmutable::now($timezone)->addDays(7)->setTime(15, 0, 0);

        return (int) round($baseline->diffInMinutes($anchor));
    }

    private function startsAt(string $startsAt, int $shiftMinutes): CarbonImmutable
    {
        return CarbonImmutable::parse($startsAt)->addMinutes($shiftMinutes)->utc();
    }

    private function resolveZones(array $eventZones, Venue $venue): ?array
    {
        $zones = [];

        foreach ($eventZones as $eventZone) {
            $venueZone = $venue->zones->firstWhere('name', $eventZone['name']);
            $capacity = $venueZone?->capacity ?? (self::FALLBACK_ZONE_CAPACITY[$eventZone['name']] ?? null);

            if ($capacity === null) {
                return null;
            }

            $zones[] = [
                'venue_zone_id' => $venueZone?->id,
                'name' => $eventZone['name'],
                'capacity' => $capacity,
                'available_capacity' => $capacity,
                'base_price' => $eventZone['price'],
            ];
        }

        return $zones;
    }

    private function demoImageUrl(string $name, string $category, string $venue): string
    {
        if ($category === 'DEPORTE' && $this->isFootballMatch($name)) {
            return $this->unsplash(self::FOOTBALL_BG[$this->pick($name, self::FOOTBALL_BG)]);
        }

        foreach (self::BY_KEYWORD as $pattern => $images) {
            if (preg_match($pattern, mb_strtolower($name))) {
                return $this->resolveImage($images, $name);
            }
        }

        foreach (self::BY_VENUE as $pattern => $path) {
            if (preg_match($pattern, mb_strtolower($venue))) {
                return $path;
            }
        }

        $pool = self::BY_CATEGORY[$category] ?? self::BY_CATEGORY['CONCIERTO'];

        return $this->unsplash($pool[$this->pick($name, $pool)]);
    }

    private function isFootballMatch(string $name): bool
    {
        $base = preg_split('/\s+[—–-]\s+/u', $name)[0];
        $teams = preg_split('/\s+(?:vs\.?|v\.?)\s+/iu', $base);

        return count($teams) === 2 && preg_match('/f[uú]tbol|cl[aá]sico|derbi|derby/iu', $name) === 1;
    }

    private function resolveImage(array $images, string $seed): string
    {
        $image = $images[$this->pick($seed, $images)];

        return str_starts_with($image, '/') ? $image : $this->unsplash($image);
    }

    private function pick(string $seed, array $images): int
    {
        return (int) (sprintf('%u', crc32($seed)) % count($images));
    }

    private function unsplash(string $id): string
    {
        return 'https://images.unsplash.com/photo-'.$id.'?auto=format&fit=crop&w=1400&q=72';
    }
}
