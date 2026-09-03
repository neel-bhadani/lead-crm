import { reactive } from 'vue'

/*
 | One shared toast queue for the whole app.
 |
 | The queue lives at module scope, not inside a component, so anything can push
 | to it — a page, a modal, or the global Inertia hooks in app.js — without the
 | message having to make a round trip through the server. Toaster.vue is the
 | only thing that renders it, so there is a single stack on screen.
 */

// older toasts drop off the top once this many are on screen
const MAX_VISIBLE = 4

// an error is worth reading twice, so it stays around longer
const DURATIONS = { success: 4000, warning: 4000, error: 6000 }

export const toasts = reactive([])

// id -> { handle, remaining, startedAt }; kept out of the reactive array
// because a running timer is not something the template should react to
const timers = new Map()

let nextId = 1

function clearTimer(id) {
    const timer = timers.get(id)

    if (timer) {
        clearTimeout(timer.handle)
        timers.delete(id)
    }
}

export function dismissToast(id) {
    clearTimer(id)

    const index = toasts.findIndex(t => t.id === id)

    if (index !== -1) {
        toasts.splice(index, 1)
    }
}

/**
 * Hovering (or focusing) a toast should hold it on screen, so the timer is
 * stopped and the time it had left is remembered rather than restarted.
 */
export function pauseToast(id) {
    const timer = timers.get(id)

    if (!timer || timer.handle === null) return

    clearTimeout(timer.handle)
    timer.remaining = Math.max(0, timer.remaining - (Date.now() - timer.startedAt))
    timer.handle = null
}

export function resumeToast(id) {
    const timer = timers.get(id)

    if (!timer || timer.handle !== null) return

    timer.startedAt = Date.now()
    timer.handle = setTimeout(() => dismissToast(id), timer.remaining)
}

function push(type, message) {
    const text = typeof message === 'string' ? message.trim() : String(message ?? '').trim()

    if (!text) return null

    const id = nextId++
    const duration = DURATIONS[type] ?? DURATIONS.success

    toasts.push({ id, type, message: text })

    while (toasts.length > MAX_VISIBLE) {
        dismissToast(toasts[0].id)
    }

    timers.set(id, {
        handle: setTimeout(() => dismissToast(id), duration),
        remaining: duration,
        startedAt: Date.now(),
    })

    return id
}

export const toast = {
    success: message => push('success', message),
    error: message => push('error', message),
    warning: message => push('warning', message),
    dismiss: dismissToast,
    pause: pauseToast,
    resume: resumeToast,
    clear: () => toasts.map(t => t.id).forEach(dismissToast),
}

/*
 | Server-sent messages arrive as the shared `flash` prop. Inertia hands back a
 | fresh object on every response, so comparing the object itself is enough to
 | tell a new response from a re-render — and it still lets the same message
 | show twice if the user really did the same thing twice.
 */
let lastFlash = null

export function flashToasts(flash) {
    if (!flash || flash === lastFlash) return

    lastFlash = flash

    if (flash.success) toast.success(flash.success)
    if (flash.warning) toast.warning(flash.warning)
    if (flash.error) toast.error(flash.error)
}

export function useToast() {
    return { toasts, toast, dismissToast, pauseToast, resumeToast, flashToasts }
}

export default toast
