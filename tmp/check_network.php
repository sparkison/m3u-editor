<?php

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$n = App\Models\Network::find(146);
$prog = $n->getCurrentProgramme();

echo "network: {$n->id} - {$n->name}\n";
if ($prog) {
    echo "programme: {$prog->id} - ".($prog->title ?? '(no title)')." ({$prog->start_at} -> {$prog->end_at})\n";
}
$seek = $n->getCurrentSeekPosition();
echo "seek_seconds: {$seek}\n";
echo 'seek_formatted: '.gmdate('H:i:s', $seek)."\n";
