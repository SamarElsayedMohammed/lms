<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$c = \App\Models\Course\Course::where('slug', 'asasyat-altaaaml-almhtrf-oalraky')
    ->orWhere('id', 1149)
    ->first();

if (!$c) {
    echo "Course not found by slug or 1149, fetching first course:\n";
    $c = \App\Models\Course\Course::first();
}

if (!$c) {
    echo "No courses in database.\n";
    exit;
}

echo "COURSE: ID={$c->id} | SLUG={$c->slug} | TITLE={$c->title} | TYPE={$c->course_type} | PRICE={$c->price} | SEQUENTIAL=" . json_encode($c->sequential_access) . "\n";

foreach ($c->chapters as $ch) {
    echo "CHAPTER: ID={$ch->id} | TITLE={$ch->title} | ORDER={$ch->chapter_order}\n";
    foreach ($ch->lectures as $l) {
        echo "  LECTURE: ID={$l->id} | TITLE={$l->title} | ORDER={$l->chapter_order} | FREE_PREVIEW=" . json_encode($l->free_preview) . " | IS_FREE=" . json_encode($l->is_free) . " | DUR={$l->duration_seconds} (h={$l->hours}, m={$l->minutes}, s={$l->seconds})\n";
    }
}
