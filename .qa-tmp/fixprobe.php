<?php
$f = 'tests/Feature/QA/AuthAndRolesProbe.php';
$s = file_get_contents($f);
$s = str_replace(
  "session('errors')?->getBag('default')->all()",
  "\$this->errs()",
  $s);
$s = str_replace(
  "session('errors')?->getBag('default')->keys()",
  "array_keys(\$this->errs())",
  $s);
$s = str_replace(
  "session('errors')?->getBag('default')->first('login')",
  "(\$this->errs()['login'][0] ?? null)",
  $s);
$s = str_replace(
  "\$partner = \\App\\Models\\ChannelPartner::create(['name' => 'Firm A', 'type' => 'firm', 'is_active' => true]);",
  "\$partner = \\App\\Models\\ChannelPartner::create(['name' => 'Firm A', 'type' => 'firm', 'phone' => '9800000000', 'is_active' => true]);",
  $s);
$helper = <<<'H'
    /** Errors from the session, whatever shape the bag is in. */
    protected function errs(): array
    {
        $e = session('errors');

        if ($e === null) return [];
        if (is_array($e)) return $e;

        return $e->getBag('default')->messages();
    }

    public function test_login_matrix(): void
H;
$s = str_replace("    public function test_login_matrix(): void", $helper, $s);
file_put_contents($f, $s);
echo "ok\n";
