<?php

namespace App\Http\Requests\Concerns;

use App\Support\CrmTaxonomy;
use Illuminate\Validation\Rule;

/**
 * The write-side rule for a stage or a source key.
 *
 * ---------------------------------------------------------------------------
 * Active, plus whatever this record already holds
 * ---------------------------------------------------------------------------
 *
 * `Rule::exists('lead_stages', 'key')->where('is_active', true)` on its own is
 * the obvious rule and it is wrong, in a way that only shows up weeks after the
 * feature ships. The moment an admin switches a stage off, every lead standing
 * in it becomes unsaveable: the form loads with that stage selected, the user
 * corrects a misspelt surname, and the save comes back "The selected stage is
 * invalid" about a field they never touched. The lead is now uneditable until
 * somebody works out that a setting on another page is the reason.
 *
 * So the rule is "active, OR the value this record already has". New work goes
 * to live stages; old work stays editable exactly where it is. That is the same
 * shape LeadRequest already uses for a switched-off channel partner, and for
 * the same reason.
 *
 * ---------------------------------------------------------------------------
 * Reads are not restricted here
 * ---------------------------------------------------------------------------
 *
 * Only the two forms that WRITE `leads.stage` and `leads.source` use this. The
 * list filters, the dashboard cross-filter and the report groupings validate
 * against every key that has ever existed — narrowing a list to a retired
 * source is a perfectly reasonable thing to want, and refusing it would hide
 * data rather than protect it.
 */
trait ValidatesTaxonomy
{
    /**
     * @return \Illuminate\Validation\Rules\Exists|\Illuminate\Validation\Rules\In
     */
    protected function activeStageRule(?string $current = null)
    {
        return $this->taxonomyRule('lead_stages', $current, CrmTaxonomy::stageKeys());
    }

    /**
     * @return \Illuminate\Validation\Rules\Exists|\Illuminate\Validation\Rules\In
     */
    protected function activeSourceRule(?string $current = null)
    {
        return $this->taxonomyRule('lead_sources', $current, CrmTaxonomy::sourceKeys());
    }

    /**
     * @param  list<string>  $fallbackKeys
     * @return \Illuminate\Validation\Rules\Exists|\Illuminate\Validation\Rules\In
     */
    private function taxonomyRule(string $table, ?string $current, array $fallbackKeys)
    {
        /*
         | The tables are missing or empty and CrmTaxonomy is serving the config
         | fallback. An exists rule here would refuse every key there is, which
         | would take a half-installed application from "renders, on the old
         | vocabulary" to "no lead can be saved". Validate against the same list
         | the dropdown was built from instead.
         */
        if (! CrmTaxonomy::usingDatabase()) {
            return Rule::in($fallbackKeys);
        }

        return Rule::exists($table, 'key')->where(function ($query) use ($current) {
            // one grouped OR, not two top-level clauses — `is_active = 1 OR
            // key = x` written flat would let ANY active row satisfy a rule
            // that is already matching on `key` outside it
            $query->where(function ($group) use ($current) {
                $group->where('is_active', true);

                if ($current !== null && $current !== '') {
                    $group->orWhere('key', $current);
                }
            });
        });
    }
}
