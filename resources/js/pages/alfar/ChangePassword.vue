<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});
</script>
<template>
    <Head title="Ganti password sementara" />
    <main
        class="min-h-screen bg-[#f0f1fb] p-6 text-[#30304a] [color-scheme:light] sm:p-8"
    >
        <form
            class="mx-auto mt-8 max-w-md space-y-5 rounded-3xl border border-[#e9e7f0] bg-white p-6 shadow-sm sm:mt-20 sm:p-10"
            @submit.prevent="form.post('/password/change')"
        >
            <p class="font-bold text-[#5546e8]">ALFAR PEOPLE</p>
            <h1 class="text-2xl font-bold text-[#30304a]">Amankan akun Anda</h1>
            <p class="text-[#64748b]">
                Ganti password sementara sebelum melanjutkan. Gunakan minimal 12
                karakter, huruf dan angka.
            </p>
            <label
                v-for="(label, key) in {
                    current_password: 'Password saat ini',
                    password: 'Password baru',
                    password_confirmation: 'Ulangi password baru',
                }"
                :key="key"
                class="block text-sm font-medium text-[#475569]"
                >{{ label
                }}<input
                    v-model="form[key]"
                    type="password"
                    required
                    class="mt-2 w-full rounded-xl border border-[#cbd5e1] bg-white p-3 text-[#30304a] shadow-sm outline-none focus:border-[#5546e8] focus:ring-2 focus:ring-[#5546e8]/20"
                    :autocomplete="
                        key === 'current_password'
                            ? 'current-password'
                            : 'new-password'
                    "
            /></label>
            <p
                v-for="error in form.errors"
                :key="error"
                class="text-sm text-red-600"
            >
                {{ error }}
            </p>
            <button
                class="w-full rounded-xl bg-[#5546e8] p-3 font-medium text-white transition-colors hover:bg-[#4636d3] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5546e8] disabled:cursor-wait disabled:opacity-60"
                :disabled="form.processing"
            >
                {{ form.processing ? 'Menyimpan…' : 'Simpan password' }}
            </button>
        </form>
    </main>
</template>
