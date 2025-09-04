<?php
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Segment.php';

header('Content-Type: text/css');

$segments = Segment::allWithCategories();
$steps = [92,80,68,56,44];

echo ":root{\n";
foreach ($segments as $seg) {
    $h = $seg['hue_deg'] ?? 0;
    $lBase = $seg['base_l_pct'] ?? 67;
    $cBase = $seg['base_c'] ?? 0.12;
    // base
    echo "  --segment-{$seg['id']}-base: oklch({$lBase}% {$cBase} {$h});\n";
    $fgBase = ($lBase < 60) ? '#fff' : '#000';
    echo "  --segment-{$seg['id']}-fg: {$fgBase};\n";
    foreach ($steps as $i => $light) {
        $c = $cBase * ($light / $lBase);
        $fg = ($light < 60) ? '#fff' : '#000';
        $idx = $i + 1;
        echo "  --segment-{$seg['id']}-s{$idx}: oklch({$light}% {$c} {$h});\n";
        echo "  --segment-{$seg['id']}-s{$idx}-fg: {$fg};\n";
    }
}
echo "}\n";
