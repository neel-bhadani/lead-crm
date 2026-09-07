import { ref, watch, onUnmounted } from 'vue'
import axios from 'axios'

/**
 * "Priya Shah already has a call with Meera Vaghela at 3:20 PM."
 *
 * The client half of the follow-up clash warning, wired the same way on all
 * four forms that book one: the add-lead modal, the log-call modal, the add
 * follow-up form and the reschedule form.
 *
 * It warns and it does nothing else. There is no rule behind it, the submit
 * button is never disabled by it, and a form saved with the warning on screen
 * saves exactly as it would have done — which is the point of showing it while
 * the user is still choosing rather than as a toast once the choice is made.
 *
 * Two things it deliberately keeps quiet about:
 *
 *   no owner    `user()` returning nothing means the receiving person is not
 *               known yet — the site-visit handover picks a salesperson by
 *               round robin on save, and a warning naming the telecaller who
 *               typed the date would name the wrong person. Silence is better
 *               than a confident wrong answer.
 *
 *   no answer   a request that fails says nothing. The check is advisory, so a
 *               network blip must not produce a scary box, and there is nothing
 *               the user could do about it if it did.
 *
 * @param {object}   sources
 * @param {Function} sources.user     getter: the user id the follow-up lands on
 * @param {Function} sources.at       getter: the datetime-local value being picked
 * @param {Function} [sources.exclude] getter: the follow-up being edited, so it
 *                                     cannot clash with itself
 * @param {Function} [sources.skip]    getter: true to stay silent entirely
 */
export function useFollowUpConflict({ user, at, exclude = () => null, skip = () => false }) {
    const conflict = ref(null)

    let timer

    /*
     | Which request is the current one.
     |
     | The field is a datetime-local that fires on every keystroke, so two
     | checks can easily be in flight at once — and the first one back is not
     | necessarily the first one sent. Without this a stale answer about a time
     | the user has already moved past can land on top of a fresh one and sit
     | there.
     */
    let latest = 0

    const clear = () => {
        clearTimeout(timer)
        latest++
        conflict.value = null
    }

    const check = () => {
        clear()

        const assignedTo = user()
        const when = at()

        if (skip() || !assignedTo || !when) return

        const mine = latest

        // 400ms, the same debounce the duplicate-number check on the lead form
        // uses: long enough that typing a date does not fire eight requests,
        // short enough to arrive while the user is still looking at the field
        timer = setTimeout(async () => {
            try {
                const { data } = await axios.post(route('follow-ups.check-conflict'), {
                    assigned_to: assignedTo,
                    scheduled_at: when,
                    exclude_todo_id: exclude() ?? null,
                })

                if (mine === latest) conflict.value = data.conflict?.message ?? null
            } catch {
                // advisory: a check that did not run has nothing to say
            }
        }, 400)
    }

    watch([user, at, exclude, skip], check)

    onUnmounted(() => clearTimeout(timer))

    return { conflict, check, clear }
}

export default useFollowUpConflict
