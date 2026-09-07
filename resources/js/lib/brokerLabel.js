/**
 * Who brought this lead — the partner row if it has one, the old free text if
 * it does not.
 *
 * The mirror of Lead::getBrokerLabelAttribute(), and the reason both exist is
 * the same: `leads.broker_name` was kept when channel partners were added, so a
 * lead answers this question through whichever of the two columns it actually
 * has. New leads point at a row. Every lead created before the feature carries
 * a string somebody typed, and nothing matched those strings to rows — see the
 * migration for why guessing would be worse than not knowing.
 *
 * The joined "Broker — Firm" label is the server's (ChannelPartner appends
 * display_label), never rebuilt here, so the em dash cannot become a hyphen on
 * one page and not another.
 *
 * Null unless the lead actually came through a broker: attribution on a
 * walk-in is stale data, not information.
 *
 * @param  {{source: string, channel_partner?: ?{display_label: string}, broker_name?: ?string}} lead
 * @return {?string}
 */
export function brokerLabel(lead) {
    if (!lead || lead.source !== 'broker') return null

    return lead.channel_partner?.display_label || lead.broker_name || null
}
