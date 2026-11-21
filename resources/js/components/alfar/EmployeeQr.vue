<script setup lang="ts">
import QRCode from 'qrcode';
import { toast } from 'vue-sonner';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
const props = defineProps<{ name: string; allowWfo: boolean }>();
const image = ref('');
const lastAttendance = ref<{
    work_date: string;
    clock_in: string | null;
    clock_out: string | null;
} | null>(null);
const error = ref('');
const seconds = ref(15);
const retrySeconds = ref(0);
const active = ref(false);
const loading = ref(false);
const duration = 15_000;
let disposed = false;
let expiresAt = 0;
const sessionExpired = ref(false);
let refreshTimer: ReturnType<typeof setTimeout> | undefined;
let countdownTimer: ReturnType<typeof setInterval> | undefined;
let request: AbortController | undefined;
function clearTimers() {
    if (refreshTimer) clearTimeout(refreshTimer);
    if (countdownTimer) clearInterval(countdownTimer);
    refreshTimer = undefined;
    countdownTimer = undefined;
}
function waitBeforeRetry(waitSeconds: number, automatic = false) {
    const deadline = performance.now() + waitSeconds * 1000;
    retrySeconds.value = waitSeconds;
    countdownTimer = setInterval(() => {
        retrySeconds.value = Math.max(
            0,
            Math.ceil((deadline - performance.now()) / 1000),
        );
        if (retrySeconds.value === 0) {
            clearTimers();
            if (automatic && !document.hidden) start();
        }
    }, 1000);
}
async function refresh() {
    if (disposed || !props.allowWfo || loading.value || !active.value) return;
    clearTimers();
    loading.value = true;
    image.value = '';
    request = new AbortController();
    const timeout = setTimeout(() => request?.abort(), 15000);
    let retry = false;
    try {
        const csrf = decodeURIComponent(
            document.cookie
                .split('; ')
                .find((c) => c.startsWith('XSRF-TOKEN='))
                ?.split('=')
                .slice(1)
                .join('=') || '',
        );
        const response = await fetch('/attendance/qr', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf },
            credentials: 'same-origin',
            signal: request.signal,
        });
        if (response.status === 429) {
            const value = Number(response.headers.get('Retry-After'));
            const wait =
                Number.isFinite(value) && value > 0
                    ? Math.min(3600, Math.ceil(value))
                    : 60;
            active.value = false;
            error.value =
                'Permintaan QR terlalu sering. Tunggu hitungan selesai sebelum menampilkan ulang QR.';
            toast.warning(error.value, { id: 'qr-error' });
            waitBeforeRetry(wait);
            return;
        }
        if ([401, 419].includes(response.status)) {
            sessionExpired.value = true;
            throw new Error(
                'Sesi masuk sudah berakhir. Muat ulang halaman lalu masuk kembali untuk menampilkan QR.',
            );
        }
        retry = response.status >= 500;
        const data = await response.json().catch(() => null);
        if (!data)
            throw new Error(
                'Server belum dapat membuat QR. Muat ulang halaman atau coba lagi sebentar.',
            );
        if (!response.ok)
            throw new Error(
                data.message || 'QR tidak dapat dibuat. Silakan coba lagi.',
            );
        const picture = await QRCode.toDataURL(data.url, {
            width: 300,
            margin: 2,
            color: { dark: '#242343', light: '#ffffff' },
        });
        if (disposed || !active.value) return;
        image.value = picture;
        lastAttendance.value = data.last_attendance;
        seconds.value = 15;
        error.value = '';
        // Monotonic time is unaffected by differences between the device and server clocks.
        // Only this single timeout requests the next QR; countdown updates never make requests.
        const deadline = performance.now() + duration;
        expiresAt = deadline;
        countdownTimer = setInterval(() => {
            seconds.value = Math.max(
                0,
                Math.ceil((deadline - performance.now()) / 1000),
            );
        }, 1000);
        refreshTimer = setTimeout(() => {
            void refresh();
        }, duration);
    } catch (e) {
        if (disposed || !props.allowWfo) return;
        error.value =
            e instanceof TypeError
                ? 'Koneksi terputus. Periksa jaringan lalu tampilkan ulang QR.'
                : e instanceof Error
                  ? e.name === 'AbortError'
                      ? 'Koneksi terlalu lama. QR akan dicoba kembali dalam 15 detik.'
                      : e.message
                  : 'QR tidak dapat dibuat.';
        active.value = false;
        toast.error(error.value, { id: 'qr-error' });
        if (
            retry ||
            e instanceof TypeError ||
            (e instanceof Error && e.name === 'AbortError')
        )
            waitBeforeRetry(15, true);
    } finally {
        clearTimeout(timeout);
        loading.value = false;
    }
}
function retryOrReload() {
    if (sessionExpired.value) window.location.reload();
    else start();
}
function start() {
    if (
        disposed ||
        !props.allowWfo ||
        loading.value ||
        active.value ||
        retrySeconds.value > 0
    )
        return;
    clearTimers();
    active.value = true;
    error.value = '';
    seconds.value = 15;
    void refresh();
}
function resume() {
    if (
        !document.hidden &&
        props.allowWfo &&
        !sessionExpired.value &&
        retrySeconds.value === 0 &&
        !loading.value &&
        (!active.value || performance.now() >= expiresAt)
    ) {
        if (active.value) void refresh();
        else start();
    }
}
watch(
    () => props.allowWfo,
    (allowed) => {
        if (allowed) start();
        else {
            active.value = false;
            image.value = '';
            clearTimers();
            request?.abort();
        }
    },
);
onMounted(() => {
    if (props.allowWfo) start();
    document.addEventListener('visibilitychange', resume);
});
onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', resume);
    disposed = true;
    active.value = false;
    clearTimers();
    request?.abort();
});
</script>
<template>
    <section class="panel personal-qr">
        <div class="panel-heading">
            <div>
                <h2>QR absensi saya</h2>
                <p>
                    Tunjukkan QR ini ke pemindai kantor untuk absen masuk atau
                    pulang.
                </p>
            </div>
            <span class="pill purple-light">WFO</span>
        </div>
        <div class="personal-qr-body">
            <h3>{{ name }}</h3>
            <p>QR absensi pribadi</p>
            <div v-if="lastAttendance" class="last-attendance" role="status">
                <strong
                    >Kehadiran terakhir · {{ lastAttendance.work_date }}</strong
                ><span
                    >Masuk:
                    {{
                        lastAttendance.clock_in?.slice(11, 16) ||
                        'Belum tercatat'
                    }}</span
                ><span
                    >Pulang:
                    {{
                        lastAttendance.clock_out?.slice(11, 16) ||
                        'Belum tercatat'
                    }}</span
                >
            </div>
            <template v-if="allowWfo"
                ><img
                    v-if="image && active"
                    :src="image"
                    alt="QR absensi pribadi karyawan"
                />
                <div v-else class="qr-placeholder">
                    {{ error ? 'QR belum tersedia' : 'Menyiapkan QR pribadi…' }}
                </div>
                <span v-if="image && active" class="pill green"
                    >Diperbarui dalam {{ seconds }} detik</span
                >
                <p v-if="error" class="qr-error" role="status">{{ error }}</p>
                <button
                    v-if="!active"
                    class="primary"
                    :disabled="loading || retrySeconds > 0"
                    @click="retryOrReload"
                >
                    {{
                        retrySeconds > 0
                            ? `Tunggu ${retrySeconds} detik`
                            : sessionExpired
                              ? 'Muat ulang halaman'
                              : 'Tampilkan ulang QR'
                    }}
                </button>
                <p class="qr-instruction">
                    QR hanya berlaku 15 detik dan hanya untuk akun Anda. Jam
                    kehadiran dicatat otomatis saat pemindai menerima QR.
                </p></template
            >
            <p v-else>
                Akun Anda belum memiliki akses WFO. Hubungi pengelola karyawan.
            </p>
        </div>
    </section>
</template>
<style scoped>
.personal-qr-body {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    padding: 12px 24px 28px;
    text-align: center;
}
.personal-qr-body h3 {
    color: #30304a;
    font-size: 20px;
}
.personal-qr-body > p {
    color: #64748b;
    font-size: 12px;
}
.personal-qr-body img {
    width: 280px;
    max-width: 100%;
    height: auto;
    border: 1px solid #efedf6;
    border-radius: 16px;
}
.qr-placeholder {
    display: grid;
    place-items: center;
    min-height: 240px;
    color: #64748b;
}
.personal-qr-body .qr-instruction {
    max-width: 380px;
    line-height: 1.8;
    margin-top: 10px;
}
.personal-qr-body .qr-error {
    color: #b43b4b;
    background: #fff0f2;
    border-radius: 10px;
    padding: 12px;
    max-width: 380px;
    line-height: 1.7;
}
.last-attendance {
    display: flex;
    flex-direction: column;
    gap: 6px;
    width: 100%;
    max-width: 330px;
    padding: 14px;
    border-radius: 12px;
    background: #f5f3ff;
    color: #62577f;
    font-size: 12px;
}
.last-attendance strong {
    font-size: 11px;
}
</style>
