<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A firm, or a broker — the same row shape either way.
 *
 * `type` is what separates them and `parent_id` is what joins them: a broker
 * with a parent works under that firm, a broker without one is an individual,
 * and a firm never has a parent. One level, always. ChannelPartnerRequest is
 * where that is enforced, because a database constraint cannot read the
 * parent's `type` column.
 *
 * SoftDeletes, never a hard delete: `leads.channel_partner_id` points here and
 * the leads report groups on it, so removing a row would blank the attribution
 * off every lead that came through it and quietly move those leads into the
 * "No channel partner" bucket.
 */
class ChannelPartner extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * "Ravi Kumar — Shreeji Realty".
     *
     * Appended, because this is the string the lead form's picker, the lead
     * view and the report rows all show, and three copies of the same
     * concatenation is three places for the em dash to become a hyphen.
     *
     * It reads the `parent` relation, so EVERY query that serialises partners
     * eager-loads it — see ChannelPartnerController::index(),
     * LeadController::options() and ReportController::leadGroups(). Lazily it
     * still gives the right answer; it just costs a query a row.
     */
    protected $appends = ['display_label'];

    /*
     | Words that say what kind of business a firm is in rather than which firm
     | it is. "Shreeji Realty", "Shreeji Estate" and plain "Shreeji" are one
     | broker typed three ways far more often than they are three brokers.
     |
     | These are stripped for the NEAR-MATCH WARNING only. They are not part of
     | `name_key` and so never part of the unique index: the database has no
     | business deciding that two differently-named rows are the same company,
     | because it cannot be overruled when it is wrong. The warning can.
     */
    private const NOISE_WORDS = [
        'realty', 'realtors', 'realtor', 'real', 'estate', 'estates',
        'properties', 'property', 'developers', 'developer', 'builders',
        'builder', 'construction', 'constructions', 'infra', 'infrastructure',
        'associates', 'associate', 'enterprise', 'enterprises', 'consultancy',
        'consultants', 'consultant', 'homes', 'home', 'land', 'lands',
        'group', 'co', 'company', 'ltd', 'limited', 'pvt', 'private', 'llp',
        'and', 'the',
    ];

    /**
     * The name as the unique index sees it: lower case, alphanumeric words,
     * single spaces.
     *
     * This is what makes "Shreeji Realty", "shreeji realty" and "Shreeji
     * Realty." one key on MySQL and on SQLite alike — see the name_key
     * migration for why the collation could not be trusted to do it.
     *
     * Mirrored in resources/js/lib/partnerName.js, which is what the form warns
     * from. The two must agree; when they do not, the form warns about
     * something the database then allows, or stays quiet about something it
     * then refuses.
     */
    public static function nameKey(?string $name): string
    {
        $key = strtolower(trim((string) $name));
        $key = preg_replace('/[^a-z0-9]+/', ' ', $key);

        return trim(preg_replace('/\s+/', ' ', $key));
    }

    /**
     * The name reduced to the part that identifies WHO, for the near-match
     * warning: the key above with the trade words taken out.
     *
     * "Shreeji Realty" and "Shreeji" both reduce to "shreeji", which is the
     * whole point — the second is what somebody types at speed while a client
     * is on the phone.
     *
     * A name made of nothing but noise words keeps its full key rather than
     * reducing to the empty string. A partner genuinely called "Properties"
     * would otherwise match every other partner whose name also emptied out,
     * and the warning would fire on everything and be ignored on everything.
     */
    public static function similarityKey(?string $name): string
    {
        $key   = static::nameKey($name);
        $words = array_values(array_filter(
            explode(' ', $key),
            fn (string $word) => $word !== '' && ! in_array($word, self::NOISE_WORDS, true),
        ));

        return $words ? implode(' ', $words) : $key;
    }

    /**
     * Keep `name_key` true to `name`, and absent while the row is deleted.
     *
     * The null-while-deleted half is what makes the unique index apply to live
     * rows only. It cannot be done with a `deleted_at` column in the index —
     * see the migration.
     */
    protected static function booted(): void
    {
        static::saving(function (self $partner) {
            $partner->name_key = $partner->trashed() ? null : static::nameKey($partner->name);
        });

        static::deleted(function (self $partner) {
            if ($partner->isForceDeleting()) {
                return;
            }

            /*
             | A soft delete is a query, not a save, so `saving` above never
             | ran. Written through the query builder for the same reason:
             | firing model events here would recurse straight back into this
             | listener.
             */
            static::withTrashed()->whereKey($partner->getKey())->update(['name_key' => null]);

            $partner->name_key = null;
        });

        static::restored(function (self $partner) {
            static::withTrashed()->whereKey($partner->getKey())
                ->update(['name_key' => static::nameKey($partner->name)]);

            $partner->name_key = static::nameKey($partner->name);
        });
    }

    /* ---------------- relationships ---------------- */

    /** The firm this broker works under, or null. */
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** The brokers working under this firm. */
    public function brokers()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    /* ---------------- accessors ---------------- */

    public function getDisplayLabelAttribute(): string
    {
        $firm = $this->parent?->name;

        return $firm ? "{$this->name} — {$firm}" : $this->name;
    }

    public function isFirm(): bool
    {
        return $this->type === 'firm';
    }

    /* ---------------- scopes ---------------- */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFirms($query)
    {
        return $query->where('type', 'firm');
    }

    public function scopeBrokers($query)
    {
        return $query->where('type', 'broker');
    }

    /**
     * The brokers under this firm that are still switched on.
     *
     * The one thing that blocks a delete: a firm removed while its brokers are
     * live leaves those brokers pointing at a soft-deleted parent, so their
     * "Ravi Kumar — Shreeji Realty" label loses the half that tells two Ravis
     * apart. The admin is asked to reassign or deactivate them first.
     */
    public function activeBrokers()
    {
        return $this->brokers()->active();
    }

    /* ---------------- duplicate defences ---------------- */

    /**
     * The live partner already holding this exact name for this type, or null.
     *
     * What the unique index would refuse, asked in PHP so the answer can be a
     * sentence instead of a SQLSTATE. Trashed rows are excluded because their
     * `name_key` is null, so this and the index are looking at the same rows by
     * construction rather than by two clauses that have to be kept in step.
     */
    public static function findClash(string $name, string $type, ?int $ignoreId = null): ?self
    {
        return static::query()
            ->where('name_key', static::nameKey($name))
            ->where('type', $type)
            ->when($ignoreId, fn ($q, $id) => $q->whereKeyNot($id))
            ->first();
    }

    /**
     * Partners that are probably this one typed differently — the near-match
     * behind the warning on the inline form.
     *
     * Looser than findClash() in two ways, both deliberate: the trade words are
     * stripped, so "Shreeji" finds "Shreeji Realty", and TYPE IS IGNORED,
     * because a firm entered once as a broker is one of the commonest ways this
     * list goes wrong and is exactly the case an admin would want to merge.
     *
     * Inactive partners are included. A name that already exists switched off
     * is the one case the user cannot fix from this form, and telling them so
     * is better than letting them submit into a unique-index failure they
     * cannot read.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function nearMatches(string $name, ?int $ignoreId = null)
    {
        $key = static::similarityKey($name);

        if ($key === '') {
            return static::query()->whereRaw('1 = 0')->get();
        }

        return static::query()
            ->when($ignoreId, fn ($q, $id) => $q->whereKeyNot($id))
            ->with('parent:id,name')
            ->orderBy('name')
            ->get()
            ->filter(fn (self $p) => static::similarityKey($p->name) === $key)
            ->values();
    }
}
