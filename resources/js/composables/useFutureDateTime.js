import { ref, computed } from 'vue'
import { localNow, isPast } from '@/lib/localDateTime'

/**
 * The client half of "a follow-up may not be in the past", wired the same way
 * on all three forms that ask for one.
 *
 * There are two guards here and they do different jobs. `min` stops the picker
 * offering a date that is already gone — it is a convenience, and a fake one:
 * the attribute is trivially bypassed and some browsers ignore it outright.
 * `error` is the one the user actually reads, and it is what holds the submit
 * button shut. Neither is the guarantee. `after:now` in the Form Request is,
 * and it is checked against the clock at the moment of saving rather than the
 * clock at the moment the modal opened.
 *
 * `min` is a ref rather than a call in the template because it has to be
 * recomputed when the modal opens: a page loaded at 9 AM and a modal opened
 * from it at 6 PM would otherwise still be offering 9 AM that morning. The
 * host calls refresh() from the watcher it already has on `show`.
 *
 * @param  {() => string} read     getter for the form field being guarded
 * @param  {string}       message  shown under the field; match the server's
 */
export function useFutureDateTime(read, message = 'The follow-up must be in the future.') {
    const min = ref(localNow())

    const refresh = () => { min.value = localNow() }

    const past = computed(() => isPast(read()))

    return { min, refresh, past, error: computed(() => (past.value ? message : '')) }
}

export default useFutureDateTime
