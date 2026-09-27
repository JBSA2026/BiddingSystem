<?php
/**
 * CLI: load DEMO properties for training / testing. Never run on a live site with real data.
 *   php tools/seed_demo.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

$types = array_column(DB::all('SELECT id, name FROM property_types'), 'id', 'name');
$demo = [
    ['Studio Unit 1205 — Cityland Pioneer Tower', 'Condominium Unit', 'Pioneer St., Mandaluyong City', 'Mandaluyong', 24.5, 2850000, 50000, -1, 3, 'multiple', 1, 1],
    ['1-Bedroom Unit 2108 — Cityland Shaw Tower', 'Condominium Unit', 'Shaw Blvd., Mandaluyong City', 'Mandaluyong', 36.0, 4200000, 50000, -2, 5, 'multiple', 0, 0],
    ['Parking Slot P2-045 — Cityland Makati Executive', 'Parking Slot', 'Makati Ave., Makati City', 'Makati', 12.5, 850000, 10000, 2, 9, 'single', 0, 0],
    ['Office Unit 9F-B — Cityland Condo Tower', 'Office Unit', 'H.V. dela Costa St., Makati City', 'Makati', 68.0, 9500000, 100000, -3, 7, 'multiple', 1, 1],
];
foreach ($demo as [$name, $type, $loc, $city, $area, $price, $inc, $openDays, $closeDays, $mode, $showRank, $snipe]) {
    $open = date('Y-m-d H:i:00', strtotime("{$openDays} days"));
    $close = date('Y-m-d H:i:00', strtotime("{$closeDays} days"));
    $id = DB::insert('properties', [
        'ref_no' => Bidding::nextPropertyRef(), 'name' => $name, 'property_type_id' => $types[$type], 'location' => $loc, 'city' => $city,
        'description' => "DEMO LISTING. Well-maintained {$type} offered for bidding on an \"as-is, where-is\" basis.\n\nViewing by appointment.",
        'floor_area' => $area, 'specifications' => "Floor area: {$area} sqm\nTurnover: as-is", 'starting_price' => $price, 'min_increment' => $inc,
        'opening_at' => $open, 'closing_at' => $close, 'original_closing_at' => $close, 'status' => 'upcoming', 'is_published' => 1,
        'contact_info' => "Cityland Bidding Team\nbidding@example.com\n+63 2 0000 0000",
        'terms' => '<p>The property is sold on an as-is, where-is basis. The winning bidder shall pay 10% of the bid price within 5 banking days of award notice.</p>',
        'bid_mode' => $mode, 'show_ranking' => $showRank, 'show_bidder_count' => $showRank, 'antisnipe_enabled' => $snipe, 'created_at' => now(),
    ]);
    Audit::log('property_created', 'property', $id, null, ['demo' => true, 'name' => $name]);
    echo "Created demo property #{$id}: {$name}\n";
}
Bidding::syncStatuses();
