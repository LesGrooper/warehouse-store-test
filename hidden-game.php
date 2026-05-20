<?php
/**
 * Mensimulasikan 1 arah untuk mencari langkah
 */
// Initiate 2D Array => untuk mempermudah mapping data
$grid = [
    ['#','#','#','#','#','#','#','#'],
    ['#','.','.','.','.','.','.','#'],
    ['#','.','#','#','#','.','.','#'],
    ['#','.','.','.','#','.','#','#'],
    ['#','X','#','.','.','.','.','#'],
    ['#','#','#','#','#','#','#','#'],
];
// Mencari posisi dimana `X` berada, dan megninisiasi `$start` sebagai titik awal (case ini berada dititik [4,1])
$start = null;
foreach ($grid as $r => $row) {
    foreach ($row as $c => $cell) {
        if ($cell === 'X') {
            $start = [$r, $c];
            break 2;
        }
    }
}
// Deklarasi variable untuk langkah
$A = 5;
$B = 2;
$C = 3;

// hashmap array untuk arah
$steps = [
    ['dr' => -1, 'dc' => 0, 'count' => $A], // Utara / Naik
    ['dr' => 0,  'dc' => 1, 'count' => $B], // Timur / Kanan
    ['dr' => 1,  'dc' => 0, 'count' => $C], // Selatan / Turun
];
// Assignment `$current` dan deklarasi probabilitas langkah
$current = $start;
$probable = [];

// Loop untuk langkah
foreach ($steps as $segment) {
    for ($i = 0; $i < $segment['count']; $i++) {
        // Assign variable array
        $current = [$current[0] + $segment['dr'], $current[1] + $segment['dc']];
        // Cek apakah jalur yang existing adalah `#`
        if (!isset($grid[$current[0]][$current[1]]) || $grid[$current[0]][$current[1]] === '#') {
            // Terhalang, jalur tidak valid
            break 2;
        }

        // Buka jalur untuk `.`
        if ($grid[$current[0]][$current[1]] === '.') {
            $probable[] = $current;
        }
    }
}

// Tampilkan daftar koordinat
foreach ($probable as $coord) {
    echo "Baris " . ($coord[0] + 1) . ", Kolom " . ($coord[1] + 1) . "\n";
}

// Bonus: tandai $ di grid
foreach ($probable as [$r, $c]) {
    $grid[$r][$c] = '$';
}
?>