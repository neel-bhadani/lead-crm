<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tables = ['users','projects','leads','todos','channel_partners','integrations','integration_events',
           'automation_rules','message_templates','alerts','automation_logs','message_logs'];
foreach ($tables as $t) {
    printf("%-22s %d\n", $t, Illuminate\Support\Facades\DB::table($t)->count());
}
echo "\nINVARIANT open leads with no pending todo: " . App\Models\Lead::open()->doesntHave('pendingTodo')->count() . "\n";
