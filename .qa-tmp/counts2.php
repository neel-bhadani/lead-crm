<?php
$tables = ['users','projects','leads','todos','channel_partners','integrations','integration_events','automation_rules','message_templates','alerts','automation_logs','message_logs','lead_stage_history'];
foreach ($tables as $t) {
  try { $n = DB::table($t)->count(); } catch (\Throwable $e) { $n = 'MISSING'; }
  echo str_pad($t, 24) . $n . "\n";
}
