<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Meta's `field_data` turned into the columns a lead has.
 *
 * This is the part of the integration that cannot be made tidy, because the
 * input is not tidy: the client builds the lead form in Ads Manager and names
 * the questions themselves. One form asks "full_name", the next asks
 * "first_name" and "last_name", a third asks "your_name" — and every one of
 * them is a form somebody has already spent money advertising, so the importer
 * has to cope rather than insist.
 *
 * The rule throughout is: understand what you can, log what you cannot, and
 * never lose a lead over a question nobody anticipated. A lead with an
 * unrecognised "preferred_bhk" answer is still a lead worth calling.
 */
class MetaLeadNormaliser
{
    /**
     * @param  list<array{name?: string, values?: list<string>}>  $fieldData
     * @return array{first_name: string, last_name: string, mobile_number: string, email: ?string, unrecognised: list<string>}
     *
     * @throws RuntimeException when there is no usable phone number
     */
    public function normalise(array $fieldData, string $leadgenId): array
    {
        [$values, $unrecognised] = $this->bucket($fieldData);

        if ($unrecognised !== []) {
            /*
             | Logged, not failed, and logged with the lead's id so it can be
             | traced back. This is also the breadcrumb that tells an admin
             | their form is asking a question the CRM has nowhere to put —
             | which is a config change, not a bug.
             */
            Log::info('[integration:facebook] unrecognised lead form fields', [
                'leadgen_id' => $leadgenId,
                'fields'     => $unrecognised,
            ]);
        }

        $phone = $this->phone($values['phone'] ?? null);

        if ($phone === null) {
            // the one field with no sensible default: a lead nobody can ring is
            // not a lead, and the mobile/project unique index needs ten digits
            throw new RuntimeException('The lead form returned no usable phone number.');
        }

        [$first, $last] = $this->names($values);

        return [
            'first_name'    => $first,
            'last_name'     => $last,
            'mobile_number' => $phone,
            'email'         => $values['email'] ?? null,
            'unrecognised'  => $unrecognised,
        ];
    }

    /**
     * Sort the answers into the buckets this application has columns for,
     * and collect the names of the ones it does not.
     *
     * @param  list<array{name?: string, values?: list<string>}>  $fieldData
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function bucket(array $fieldData): array
    {
        $aliases      = config('integrations.meta.field_aliases');
        $values       = [];
        $unrecognised = [];

        foreach ($fieldData as $field) {
            $name = strtolower(trim((string) ($field['name'] ?? '')));
            // Meta sends every answer as a list, even the single-answer ones
            $value = trim((string) (($field['values'] ?? [])[0] ?? ''));

            if ($name === '' || $value === '') {
                continue;
            }

            $bucket = $this->bucketFor($name, $aliases);

            if ($bucket === null) {
                $unrecognised[] = $name;
                continue;
            }

            // first answer wins: a form asking the same thing twice is a form
            // mistake, and the earlier answer is the one the person meant
            $values[$bucket] ??= $value;
        }

        return [$values, $unrecognised];
    }

    /**
     * Which bucket a question name belongs to.
     *
     * Exact match first, then a contains test, because Ads Manager prefixes
     * question names with the locale or the form on some accounts —
     * "phone_number" arrives as "phone_number_en_US" often enough to be worth
     * handling, and never ambiguously.
     *
     * @param  array<string, list<string>>  $aliases
     */
    private function bucketFor(string $name, array $aliases): ?string
    {
        foreach ($aliases as $bucket => $names) {
            if (in_array($name, $names, true)) {
                return $bucket;
            }
        }

        foreach ($aliases as $bucket => $names) {
            foreach ($names as $candidate) {
                if (str_contains($name, $candidate)) {
                    return $bucket;
                }
            }
        }

        return null;
    }

    /**
     * A first and last name out of whatever the form asked for.
     *
     * `first_name`/`last_name` win when the form asked separately; otherwise
     * `full_name` is split on the first space, everything after it being the
     * last name — "Bhavesh Kumar Bhatt" is a person whose last name is "Kumar
     * Bhatt" far more often than it is a middle name the CRM should guess at.
     *
     * An empty last name is allowed and is not a hole: `leads.last_name` is NOT
     * NULL but takes an empty string, and Lead::getFullNameAttribute() builds
     * the displayed name from the parts that are actually there, so a
     * one-word name renders as one word rather than with a trailing space.
     *
     * @param  array<string, string>  $values
     * @return array{0: string, 1: string}
     */
    private function names(array $values): array
    {
        $first = $values['first_name'] ?? null;
        $last  = $values['last_name'] ?? null;

        if ($first === null && isset($values['full_name'])) {
            $parts = preg_split('/\s+/', trim($values['full_name']), 2);
            $first = $parts[0] ?? null;
            $last ??= $parts[1] ?? '';
        }

        return [
            // a form that asked for no name at all still produces a callable
            // lead; "Facebook lead" is what the row says until somebody rings it
            $first !== null && $first !== '' ? $first : 'Facebook lead',
            (string) ($last ?? ''),
        ];
    }

    /**
     * The last ten digits, which is the only phone format this database has.
     *
     * Meta returns the number as the person's country dialled it —
     * "+919820012345", "0091 98200 12345", "98200-12345" — and `leads` carries
     * a unique index on (mobile_number, project_id) over bare ten-digit
     * strings. Storing "+919820012345" would not collide with the "9820012345"
     * already on that project, so the same customer would arrive twice and the
     * duplicate check that protects the telecaller from calling them twice
     * would never fire.
     *
     * Fewer than ten digits is not a number this application can dial, so it
     * is rejected rather than padded.
     */
    private function phone(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw);

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }
}
