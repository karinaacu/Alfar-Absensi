<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    ChartNoAxesCombined,
    ShieldCheck,
    Target,
} from '@lucide/vue';
defineProps<{ status?: string; canResetPassword: boolean }>();
const form = useForm({ email: '', password: '', remember: false });
</script>
<template>
    <Head title="Masuk · Alfar People" />
    <main class="alfar-login">
        <section class="login-story">
            <div class="login-brand">
                a<span>•</span>
                <strong>alfar<small>PEOPLE & PERFORMANCE</small></strong>
            </div>
            <div class="login-story-content">
                <span class="login-tag">GROW TOGETHER</span>
                <h1>
                    Setiap kontribusi<br />membawa kita<br /><em
                        >lebih jauh.</em
                    >
                </h1>
                <p>
                    Ruang untuk menyatukan target, merayakan<br />pencapaian,
                    dan bertumbuh bersama tim.
                </p>
                <div class="login-illustration">
                    <div><Target :size="24" /><span>Arah yang jelas</span></div>
                    <div>
                        <ChartNoAxesCombined :size="24" /><span
                            >Kinerja yang terukur</span
                        >
                    </div>
                    <div>
                        <ShieldCheck :size="24" /><span
                            >Kepercayaan bersama</span
                        >
                    </div>
                </div>
            </div>
            <p class="login-copyright">ALFAR PEOPLE · TUMBUH BERSAMA</p>
        </section>
        <section class="login-form-section">
            <form
                @submit.prevent="
                    form.post('/login', {
                        onFinish: () => form.reset('password'),
                    })
                "
            >
                <span class="login-tag">SELAMAT DATANG KEMBALI</span>
                <h2>Mulai hari yang berarti.</h2>
                <p>
                    Masuk ke workspace Alfar menggunakan akun perusahaan Anda.
                </p>
                <div v-if="status" class="login-status">{{ status }}</div>
                <label
                    >Email perusahaan<input
                        v-model="form.email"
                        type="email"
                        required
                        autocomplete="username"
                        placeholder="nama@alfar.id"
                        autofocus /></label
                ><small v-if="form.errors.email" class="login-error">{{
                    form.errors.email
                }}</small
                ><label
                    >Password<input
                        v-model="form.password"
                        type="password"
                        required
                        autocomplete="current-password"
                        placeholder="Masukkan password Anda" /></label
                ><small v-if="form.errors.password" class="login-error">{{
                    form.errors.password
                }}</small>
                <div class="login-options">
                    <label
                        ><input v-model="form.remember" type="checkbox" />Ingat
                        saya</label
                    ><Link v-if="canResetPassword" href="/forgot-password"
                        >Lupa password?</Link
                    >
                </div>
                <button :disabled="form.processing" data-test="login-button">
                    {{ form.processing ? 'Memproses…' : 'Masuk ke workspace'
                    }}<ArrowRight :size="17" />
                </button>
                <div class="login-note">
                    <ShieldCheck :size="18" /><span
                        >Akun dibuat oleh administrator internal.<br />Hubungi
                        HR jika Anda belum memiliki akses.</span
                    >
                </div>
            </form>
            <footer>
                © {{ new Date().getFullYear() }} Alfar People. All rights
                reserved.
            </footer>
        </section>
    </main>
</template>
<style>
.alfar-login {
    min-height: 100vh;
    display: grid;
    grid-template-columns: 1fr 1fr;
    font-family: 'Instrument Sans', sans-serif;
    color: #30304a;
    background: #fff;
    color-scheme: light;
}
.login-story {
    background: #efedfb;
    padding: 50px 65px;
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
}
.login-story:after {
    content: '';
    position: absolute;
    width: 400px;
    height: 400px;
    right: -250px;
    bottom: 100px;
    border-radius: 50%;
    border: 70px solid #e6e1f7;
    opacity: 0.6;
}
.login-brand {
    font-size: 52px;
    line-height: 1;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 4px;
    color: #5a47c7;
}
.login-brand > span {
    font-size: 24px;
    align-self: flex-end;
    margin-left: -5px;
    margin-right: 12px;
    margin-bottom: 7px;
    color: #b5ac69;
}
.login-brand strong {
    font-size: 30px;
    letter-spacing: -1.5px;
}
.login-brand small {
    display: block;
    font-size: 7px;
    letter-spacing: 1.4px;
    color: #a39ab8;
    margin-top: 8px;
}
.login-story-content {
    margin: auto 0;
    padding: 75px 0;
    position: relative;
    z-index: 1;
}
.login-tag {
    font-size: 9px;
    letter-spacing: 2px;
    color: #a198b9;
    font-weight: 600;
}
.login-story h1 {
    font-size: clamp(35px, 4vw, 62px);
    line-height: 1.17;
    letter-spacing: -2px;
    font-weight: 600;
    margin: 22px 0;
}
.login-story h1 em {
    font-style: normal;
    color: #7d69ca;
}
.login-story-content > p {
    font-size: 13px;
    color: #a49bb9;
    line-height: 1.9;
}
.login-illustration {
    display: flex;
    gap: 12px;
    margin-top: 42px;
}
.login-illustration > div {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: center;
    gap: 18px;
    background: #ffffffa8;
    border: 1px solid #fff;
    border-radius: 17px;
    padding: 22px 15px;
    width: 125px;
    color: #a291cf;
    font-size: 9px;
}
.login-copyright {
    font-size: 8px;
    letter-spacing: 1.8px;
    color: #afa6c6;
}
.login-form-section {
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 60px 15%;
    position: relative;
}
.login-form-section form {
    width: 100%;
    max-width: 370px;
    margin: auto;
}
.login-form-section h2 {
    font-size: 29px;
    font-weight: 650;
    letter-spacing: -1px;
    margin-top: 17px;
    margin-bottom: 10px;
}
.login-form-section form > p {
    font-size: 12px;
    color: #aaa9b9;
    line-height: 1.8;
    margin-bottom: 35px;
}
.login-form-section form > label {
    display: flex;
    flex-direction: column;
    gap: 10px;
    font-size: 11px;
    color: #828195;
    margin-top: 24px;
}
.login-form-section input:not([type='checkbox']) {
    border: 1px solid #e9e7f0;
    padding: 14px 15px;
    border-radius: 10px;
    font-size: 12px;
    width: 100%;
    color: #5e5a75;
    background: #fff;
}
.login-form-section input::placeholder {
    color: #c0bdce;
}
.login-form-section input:focus {
    outline: 2px solid #ccc4f3;
    outline-offset: 1px;
}
.login-options {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    align-items: center;
    font-size: 10px;
    margin: 23px 0;
    color: #a19bae;
}
.login-options label {
    display: flex;
    gap: 7px;
    align-items: center;
}
.login-options input {
    accent-color: #6551d2;
}
.login-options a {
    color: #8e80b6;
}
.login-form-section button {
    border: 0;
    border-radius: 10px;
    background: #5a47d2;
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 18px;
    width: 100%;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
}
.login-form-section button:disabled {
    opacity: 0.5;
}
.login-note {
    display: flex;
    gap: 10px;
    align-items: center;
    border-top: 1px solid #f1eff7;
    margin-top: 35px;
    padding-top: 23px;
    color: #b4b0c4;
    font-size: 9px;
    line-height: 1.8;
}
.login-note svg {
    color: #c0b7da;
    flex-shrink: 0;
}
.login-form-section footer {
    font-size: 8px;
    color: #c0bccd;
    text-align: center;
    margin-top: 50px;
}
.login-error {
    color: #c77b7b;
    font-size: 10px;
    display: block;
    margin-top: 6px;
}
.login-status {
    background: #eff9f3;
    color: #6b9c7d;
    padding: 12px;
    border-radius: 8px;
    font-size: 11px;
}
@media (max-width: 900px) {
    .login-story {
        padding: 35px;
    }
    .login-illustration {
        flex-wrap: wrap;
    }
    .login-illustration > div {
        width: 100px;
        padding: 18px 12px;
    }
    .login-form-section {
        padding: 45px 12%;
    }
    .login-form-section h2 {
        font-size: 25px;
    }
}
@media (max-width: 650px) {
    .alfar-login {
        grid-template-columns: 1fr;
    }
    .login-story {
        padding: 25px;
        min-height: 0;
    }
    .login-story-content,
    .login-copyright {
        display: none;
    }
    .login-brand {
        font-size: 40px;
    }
    .login-brand strong {
        font-size: 25px;
    }
    .login-form-section {
        padding: 45px 30px;
        min-height: calc(100vh - 100px);
    }
}
</style>
