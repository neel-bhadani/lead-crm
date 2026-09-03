<script setup>
import { ref, computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'

defineProps({ title: String, subtitle: String })

const page = usePage()
const user = computed(() => page.props.auth.user)
const open = ref(false)

const nav = computed(() => [
  { name: 'Dashboard', href: route('dashboard'), active: route().current('dashboard') },
  { name: 'Leads',     href: route('leads.index'), active: route().current('leads.*') },
  { name: 'To-do',     href: route('todos.index'), active: route().current('todos.*') },
])

const logout = () => router.post(route('logout'))
</script>

<template>
  <div class="min-h-screen bg-slate-100">

    <!-- scrim behind the mobile drawer -->
    <div v-show="open" class="fixed inset-0 z-30 bg-slate-900/45 lg:hidden" @click="open = false" />

    <!-- sidebar -->
    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-60 flex-col bg-slate-900 py-5 text-white
             transition-transform duration-200 lg:translate-x-0"
      :class="open ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex items-center gap-2 border-b border-white/10 px-5 pb-5 font-bold">
        <span class="block h-6 w-6 rounded border-2 border-teal-500"></span>
        Leads CRM
        <button class="ml-auto text-xl text-slate-400 lg:hidden" @click="open = false">&times;</button>
      </div>

      <nav class="flex-1 p-3">
        <Link
          v-for="item in nav" :key="item.name" :href="item.href"
          class="mb-0.5 flex items-center gap-3 rounded-lg px-3 py-2.5 font-medium"
          :class="item.active ? 'bg-teal-700 text-white' : 'text-slate-400 hover:bg-white/5 hover:text-white'"
          @click="open = false"
        >{{ item.name }}</Link>
      </nav>

      <div class="mx-3 border-t border-white/10 px-3 py-4">
        <div class="text-sm font-semibold">{{ user.name }}</div>
        <div class="text-xs capitalize text-slate-400">{{ user.role }}</div>
        <button class="mt-2 text-xs text-slate-400 hover:text-white" @click="logout">Sign out</button>
      </div>
    </aside>

    <!-- main -->
    <div class="lg:ml-60">
      <header class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 py-4 sm:px-7">
        <div class="flex min-w-0 items-center gap-3">
          <button
            class="rounded-lg border border-slate-200 p-2 lg:hidden"
            aria-label="Open menu" @click="open = true"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M3 6h18M3 12h18M3 18h18" />
            </svg>
          </button>
          <div class="min-w-0">
            <h1 class="truncate text-lg font-semibold tracking-tight text-slate-900">{{ title }}</h1>
            <p v-if="subtitle" class="truncate text-sm text-slate-500">{{ subtitle }}</p>
          </div>
        </div>
        <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
          <slot name="actions" />
        </div>
      </header>

      <main class="mx-auto max-w-[1500px] px-4 pb-12 pt-5 sm:px-7">
        <slot />
      </main>
    </div>

  </div>
</template>
