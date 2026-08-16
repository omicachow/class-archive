<?php

declare(strict_types=1);

const OUTPUT_DIRECTORY = '/tmp/class-archive-test-images';
const DEFAULT_IMAGE_COUNT = 72;
const WIDTH = 960;
const HEIGHT = 640;

$count = isset($argv[1]) ? filter_var($argv[1], FILTER_VALIDATE_INT) : DEFAULT_IMAGE_COUNT;
if ($count === false || $count < 1 || $count > 100) {
    fwrite(STDERR, "Image count must be between 1 and 100.\n");
    exit(1);
}

if (!is_dir(OUTPUT_DIRECTORY) && !mkdir(OUTPUT_DIRECTORY, 0700, true)) {
    fwrite(STDERR, "Cannot create " . OUTPUT_DIRECTORY . "\n");
    exit(1);
}

$existingFixtures = glob(OUTPUT_DIRECTORY . '/class-archive-fixture-*.png');
if ($existingFixtures === false) {
    fwrite(STDERR, "Cannot enumerate old generated fixtures.\n");
    exit(1);
}
foreach ($existingFixtures as $existingFixture) {
    if (
        realpath(dirname($existingFixture)) !== realpath(OUTPUT_DIRECTORY)
        || preg_match('/^class-archive-fixture-[0-9]{3}\.png$/', basename($existingFixture)) !== 1
        || !unlink($existingFixture)
    ) {
        fwrite(STDERR, "Cannot safely remove old fixture {$existingFixture}.\n");
        exit(1);
    }
}

$manifest = [
    'kind' => 'deterministic synthetic Class Archive test fixtures',
    'containsRealPeopleOrPhotos' => false,
    'generatedAt' => gmdate(DATE_ATOM),
    'files' => [],
];

for ($index = 1; $index <= $count; $index++) {
    $image = imagecreatetruecolor(WIDTH, HEIGHT);
    if ($image === false) {
        fwrite(STDERR, "Cannot allocate image {$index}.\n");
        exit(1);
    }

    $red = 28 + (($index * 37) % 100);
    $green = 38 + (($index * 61) % 100);
    $blue = 52 + (($index * 83) % 100);
    $background = imagecolorallocate($image, $red, $green, $blue);
    imagefilledrectangle($image, 0, 0, WIDTH, HEIGHT, $background);

    for ($band = 0; $band < 12; $band++) {
        $bandColor = imagecolorallocatealpha(
            $image,
            ($red + 25 + ($band * 9)) % 256,
            ($green + 40 + ($band * 13)) % 256,
            ($blue + 55 + ($band * 17)) % 256,
            48,
        );
        $left = ($band * 97 + $index * 23) % WIDTH;
        $top = ($band * 53 + $index * 31) % HEIGHT;
        imagefilledellipse($image, $left, $top, 260 + ($band * 11), 180 + ($band * 7), $bandColor);
    }

    $panel = imagecolorallocatealpha($image, 12, 18, 28, 30);
    imagefilledrectangle($image, 60, 420, 900, 590, $panel);
    $white = imagecolorallocate($image, 245, 247, 250);
    $muted = imagecolorallocate($image, 205, 215, 225);
    imagestring($image, 5, 92, 460, 'CLASS ARCHIVE', $white);
    imagestring($image, 4, 92, 500, sprintf('SYNTHETIC FIXTURE %03d', $index), $muted);
    imagestring($image, 3, 92, 540, $index % 2 === 0 ? 'ERA: HERITAGE' : 'ERA: LIVING', $muted);

    $filename = sprintf('class-archive-fixture-%03d.png', $index);
    $path = OUTPUT_DIRECTORY . '/' . $filename;
    if (!imagepng($image, $path, 7)) {
        imagedestroy($image);
        fwrite(STDERR, "Cannot write {$path}.\n");
        exit(1);
    }
    imagedestroy($image);

    $manifest['files'][] = [
        'name' => $filename,
        'sha256' => hash_file('sha256', $path),
        'width' => WIDTH,
        'height' => HEIGHT,
    ];
}

$manifestResult = file_put_contents(
    OUTPUT_DIRECTORY . '/manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);
if ($manifestResult === false) {
    fwrite(STDERR, "Cannot write fixture manifest.\n");
    exit(1);
}

fwrite(STDOUT, sprintf("Generated %d synthetic PNG fixtures in %s\n", $count, OUTPUT_DIRECTORY));
