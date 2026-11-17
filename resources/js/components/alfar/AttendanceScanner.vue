<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { BrowserQRCodeReader, type IScannerControls } from '@zxing/browser';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
export interface ScanDevice {
    [key: string]: unknown;
    id: number;
    name: string;
    type: string;
    active: boolean | number;
}
const props = defineProps<{ devices: ScanDevice[] }>();
const method = ref(
    typeof navigator !== 'undefined' &&
        /Android|iPhone|iPad/i.test(navigator.userAgent)
        ? 'phone_camera'
        : 'laptop_camera',
);
const rawCode = ref('');
const input = ref<HTMLInputElement | null>(null);
const video = ref<HTMLVideoElement | null>(null);
const running = ref(false);
const busy = ref(false);
let controls: IScannerControls | undefined;
let lastCode = '';
let disposed = false;
const available = computed(() =>
    props.devices.filter(
        (d) => d.active && ['scanner', 'phone_camera'].includes(d.type),
    ),
);
function stop() {
    running.value = false;
    controls?.stop();
    controls = undefined;
}
async function consume(text: string, inputMethod = method.value) {
    if (busy.value || text === lastCode || disposed) return;
    lastCode = text;
    try {
        let token: string | null;
        try {
            const url = new URL(text);
            token = url.searchParams.get('employee_qr');
            if (
                url.origin !== location.origin ||
                !token ||
                !/^[A-Za-z0-9]{64}$/.test(token)
            )
                throw new Error();
        } catch {
            throw new Error('QR bukan QR karyawan dari aplikasi ini.');
        }
        busy.value = true;
        toast.loading('Mencatat kehadiran…', { id: 'scan-result' });
        const csrf = decodeURIComponent(
            document.cookie
                .split('; ')
                .find((c) => c.startsWith('XSRF-TOKEN='))
                ?.split('=')
                .slice(1)
                .join('=') || '',
        );
        const response = await fetch('/attendance/scan', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrf,
            },
            body: JSON.stringify({
                token,
                input_method: inputMethod,
            }),
        });
        const data = await response.json();
        if (!response.ok) {
            const messages = data.errors
                ? Object.values(data.errors).flat().join(' ')
                : data.message;
            throw new Error(messages || 'Absensi belum dapat dicatat.');
        }
        if (!disposed) {
            toast.success(data.message, {
                id: 'scan-result',
                description: `${data.employee_name} · ${data.recorded_at}`,
            });
            router.reload({ only: ['attendances'] });
        }
    } catch (e) {
        if (!disposed)
            toast.error(
                e instanceof TypeError
                    ? 'Koneksi terputus. Periksa jaringan lalu coba lagi.'
                    : e instanceof Error && e.message
                      ? e.message
                      : 'QR bukan QR karyawan dari aplikasi ini.',
                { id: 'scan-result' },
            );
    } finally {
        busy.value = false;
        rawCode.value = '';
    }
}
async function startCamera() {
    if (!available.value.length || running.value) return;
    running.value = true;
    lastCode = '';
    await nextTick();
    try {
        controls = await new BrowserQRCodeReader().decodeFromConstraints(
            {
                video: {
                    facingMode: {
                        ideal:
                            method.value === 'phone_camera'
                                ? 'environment'
                                : 'user',
                    },
                },
            },
            video.value!,
            (result) => {
                if (result && running.value) void consume(result.getText());
            },
        );
        if (!running.value) controls.stop();
    } catch {
        if (disposed) return;
        stop();
        toast.error(
            'Kamera belum dapat diakses. Gunakan HTTPS, izinkan kamera, lalu pastikan kamera tidak dipakai aplikasi lain.',
            { id: 'camera-error' },
        );
    }
}
async function focusScanner() {
    lastCode = '';
    await nextTick();
    input.value?.focus();
}
onBeforeUnmount(() => {
    disposed = true;
    toast.dismiss('scan-result');
    stop();
});
</script>
<template>
    <section class="panel scanner-station">
        <div class="panel-heading">
            <div>
                <h2>Pemindai absensi karyawan</h2>
                <p>
                    Karyawan menunjukkan QR pribadi. Hasil pindai langsung
                    mencatat kehadiran WFO.
                </p>
            </div>
            <span class="pill purple-light">MASUK / PULANG OTOMATIS</span>
        </div>
        <div class="scanner-body">
            <p v-if="!available.length" class="scanner-note">
                Belum ada perangkat aktif untuk pilihan ini. Pengelola perlu
                mendaftarkan perangkat pada menu Perangkat absensi.
            </p>
            <template
                ><div class="scanner-camera">
                    <video
                        ref="video"
                        v-show="running"
                        autoplay
                        muted
                        playsinline
                    />
                    <div v-if="!running" class="camera-placeholder">
                        Arahkan kamera ke QR yang tampil pada layar karyawan.
                    </div>
                </div>
                <button
                    type="button"
                    class="primary"
                    :disabled="!available.length || busy"
                    @click="running ? stop() : startCamera()"
                >
                    {{ running ? 'Hentikan kamera' : 'Aktifkan kamera' }}
                </button></template
            >
            <form
                class="usb-scanner-form"
                @submit.prevent="consume(rawCode, 'usb_scanner')"
            >
                <label
                    >Hasil scanner<input
                        ref="input"
                        v-model="rawCode"
                        :disabled="!available.length || busy"
                        autocomplete="off"
                        placeholder="Klik di sini, lalu pindai QR karyawan" /></label
                ><button type="button" class="secondary" @click="focusScanner">
                    Fokuskan scanner</button
                ><button
                    class="primary"
                    :disabled="
                        !available.some((d) => d.type === 'scanner') ||
                        busy ||
                        !rawCode
                    "
                >
                    Catat hasil pindai</button
                ><small
                    >Scanner yang berfungsi sebagai keyboard akan mengirim hasil
                    dan tombol Enter secara otomatis.</small
                >
            </form>
            <p class="scanner-note">
                Kamera dan scanner dapat digunakan bersamaan. Fingerprint aktif
                menerima data secara terpisah. Jenis absensi ditentukan otomatis
                dari jadwal dan catatan masuk karyawan.
            </p>
        </div>
    </section>
</template>
<style scoped>
.scanner-body {
    padding: 0 24px 24px;
    display: flex;
    flex-direction: column;
    gap: 18px;
}
.scanner-settings {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.scanner-settings label,
.usb-scanner-form label {
    display: flex;
    flex-direction: column;
    gap: 7px;
    color: #475569;
    font-size: 12px;
}
.scanner-settings small,
.scanner-note,
.usb-scanner-form > small {
    font-size: 11px;
    color: #64748b;
    line-height: 1.8;
}
.scanner-camera {
    border-radius: 16px;
    overflow: hidden;
    background: #f0eef9;
    max-width: 640px;
    width: 100%;
    align-self: center;
}
.scanner-camera video {
    display: block;
    width: 100%;
    max-height: 400px;
    object-fit: contain;
    background: #22213d;
}
.camera-placeholder {
    display: grid;
    place-items: center;
    min-height: 220px;
    text-align: center;
    padding: 25px;
    color: #77718f;
    font-size: 13px;
}
.scanner-body > .primary {
    align-self: center;
}
.usb-scanner-form {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
}
.usb-scanner-form label {
    flex: 1;
    min-width: 180px;
}
.usb-scanner-form > small {
    width: 100%;
}
@media (max-width: 600px) {
    .scanner-settings {
        grid-template-columns: 1fr;
    }
    .scanner-body {
        padding: 0 18px 18px;
    }
    .scanner-station .panel-heading {
        flex-wrap: wrap;
    }
}
</style>
