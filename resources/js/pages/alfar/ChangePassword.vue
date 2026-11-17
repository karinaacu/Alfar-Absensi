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
    <main class="min-h-screen bg-indigo-50 p-8">
        <form
            class="mx-auto mt-20 max-w-md space-y-5 rounded-3xl bg-white p-10 shadow-sm"
            @submit.prevent="form.post('/password/change')"
        >
            <p class="font-bold text-indigo-600">ALFAR PEOPLE</p>
            <h1 class="text-2xl font-bold">Amankan akun Anda</h1>
            <p class="text-slate-500">
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
                class="block"
                >{{ label
                }}<input
                    v-model="form[key]"
                    type="password"
                    required
                    class="mt-2 w-full rounded-xl border p-3"
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
                class="w-full rounded-xl bg-indigo-600 p-3 text-white"
                :disabled="form.processing"
            >
                Simpan password
            </button>
        </form>
    </main>
</template>
