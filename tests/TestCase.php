<?php

namespace Tests;

use App\Support\CrmTaxonomy;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /*
     | CrmTaxonomy memoises the stages in a static, which outlives the
     | RefreshDatabase rollback. A test that edits a stage would otherwise hand
     | its edit to every test after it in the same process.
     */
    protected function setUp(): void
    {
        parent::setUp();

        CrmTaxonomy::flush();
    }
}
