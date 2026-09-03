<script setup>
import { ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import FormField from '@/Components/FormField.vue'

defineProps({ status: String })

const showPw = ref(false)

const form = useForm({
  login: '',
  password: '',
  remember: true,
})

const submit = () => form.post(route('login'), {
  onFinish: () => form.reset('password'),
})
</script>

<template>
  <Head title="Sign in" />

  <div class="flex min-h-screen">

    <!-- left: image panel, hidden on phones -->
    <div class="relative hidden w-[52%] flex-col justify-between overflow-hidden bg-slate-900 p-12 text-white md:flex">
      <div
        class="absolute inset-0 opacity-[0.06]"
        style="background-image:linear-gradient(#fff 1px,transparent 1px),linear-gradient(90deg,#fff 1px,transparent 1px);background-size:44px 44px"
      />
      <div class="absolute -bottom-40 -right-36 h-[520px] w-[520px] rounded-full
                  bg-[radial-gradient(circle_at_30%_30%,rgba(15,118,110,.55),transparent_62%)]" />

      <div class="relative flex items-center gap-2.5 font-bold">
        <span class="block h-6 w-6 rounded border-2 border-teal-500"></span> Leads CRM
      </div>

      <div class="relative">
        <h2 class="mb-4 max-w-[15ch] text-4xl font-bold leading-tight tracking-tight">
          Every enquiry, followed up on time.
        </h2>
        <p class="max-w-[44ch] text-slate-300">
          Track site visits across your projects, know which channel brought each buyer,
          and never lose a lead to a forgotten callback.
        </p>
      </div>

      <div class="relative flex gap-9 border-t border-white/15 pt-7">
        <div><div class="text-2xl font-bold">312</div><div class="text-xs text-slate-400">Leads this quarter</div></div>
        <div><div class="text-2xl font-bold">86</div><div class="text-xs text-slate-400">Site visits</div></div>
        <div><div class="text-2xl font-bold">19</div><div class="text-xs text-slate-400">Bookings</div></div>
      </div>
    </div>

    <!-- right: the form -->
    <div class="flex flex-1 items-center justify-center bg-white px-6 py-10">
      <div class="w-full max-w-sm">
        <h1 class="mb-1.5 text-2xl font-semibold tracking-tight">Sign in</h1>
        <p class="mb-7 text-sm text-slate-500">Use your email address or mobile number.</p>

        <div v-if="status" class="mb-4 text-sm font-medium text-teal-700">{{ status }}</div>

        <form class="space-y-4" @submit.prevent="submit">

          <FormField label="Email or mobile number" required :error="form.errors.login">
            <input v-model="form.login" type="text" autocomplete="username" autofocus />
          </FormField>

          <FormField label="Password" required :error="form.errors.password">
            <div class="relative">
              <input v-model="form.password" :type="showPw ? 'text' : 'password'" autocomplete="current-password" />
              <button
                type="button"
                class="absolute right-2 top-1/2 -translate-y-1/2 px-2 text-xs text-slate-400"
                @click="showPw = !showPw"
              >{{ showPw ? 'Hide' : 'Show' }}</button>
            </div>
          </FormField>

          <label class="flex items-center gap-2 text-sm text-slate-500">
            <input v-model="form.remember" type="checkbox" class="!min-h-0 !w-auto rounded" />
            Keep me signed in
          </label>

          <button type="submit" class="btn w-full py-2.5" :disabled="form.processing">
            {{ form.processing ? 'Signing in…' : 'Sign in' }}
          </button>
        </form>

        <div class="mt-6 rounded-lg bg-slate-50 p-3.5 text-xs text-slate-500">
          Demo accounts — <b class="text-slate-700">admin@crm.test</b>,
          <b class="text-slate-700">tele@crm.test</b>,
          <b class="text-slate-700">sales@crm.test</b>. Password: <b class="text-slate-700">password</b>
        </div>
      </div>
    </div>
  </div>
</template>
