/**
 * Partner name normalisation — the mirror of ChannelPartner::nameKey() and
 * ChannelPartner::similarityKey().
 *
 * The two implementations must agree. When they drift, the form warns about a
 * name the database then accepts, or stays quiet about one the unique index
 * then refuses with an error nobody can act on. The word list below is a copy
 * of the model's NOISE_WORDS for the same reason: this file is what makes the
 * warning instant, and an instant warning that is wrong is worse than a slow
 * one that is right.
 */

/*
 | Words that say what line of business a firm is in rather than which firm it
 | is. Stripped for the WARNING only — never for the unique key, where two
 | differently-named rows must be allowed to coexist because they may well be
 | two different companies.
 */
const NOISE_WORDS = new Set([
    'realty', 'realtors', 'realtor', 'real', 'estate', 'estates',
    'properties', 'property', 'developers', 'developer', 'builders',
    'builder', 'construction', 'constructions', 'infra', 'infrastructure',
    'associates', 'associate', 'enterprise', 'enterprises', 'consultancy',
    'consultants', 'consultant', 'homes', 'home', 'land', 'lands',
    'group', 'co', 'company', 'ltd', 'limited', 'pvt', 'private', 'llp',
    'and', 'the',
])

/** Lower case, alphanumeric words, single spaces. What the unique index compares. */
export function nameKey(name) {
    return String(name ?? '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim()
        .replace(/\s+/g, ' ')
}

/**
 * The part of the name that identifies WHO — the key above with the trade words
 * removed, so "Shreeji" and "Shreeji Realty" reduce to the same thing.
 *
 * A name made only of noise words keeps its full key rather than emptying out,
 * or a partner genuinely called "Properties" would match every other name that
 * also emptied and the warning would fire on everything.
 */
export function similarityKey(name) {
    const key = nameKey(name)
    const words = key.split(' ').filter(w => w && !NOISE_WORDS.has(w))

    return words.length ? words.join(' ') : key
}

/**
 * The partners a typed name is probably a second spelling of.
 *
 * Type is deliberately not compared: a firm entered once as a broker is one of
 * the commonest ways this list goes wrong, and it is exactly the pair an admin
 * would later want to merge. ChannelPartner::nearMatches() ignores type for the
 * same reason.
 *
 * @param  {string} name
 * @param  {{id: number, label: string}[]} partners
 * @return {{id: number, label: string}[]}
 */
export function nearMatches(name, partners) {
    const key = similarityKey(name)

    if (!key) return []

    return partners.filter(p => similarityKey(p.name ?? p.label) === key)
}
