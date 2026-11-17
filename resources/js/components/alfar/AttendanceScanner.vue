<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { BrowserQRCodeReader, type IScannerControls } from '@zxing/browser';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
export interface ScanDevice {
    [key: string]: unknown;
    id: number;
    name: string;
    type: string;
    active: boolean | number;
}
const props = defineProps<{ devices: ScanDevice[] }>();
const method = ref('laptop_camera');
const deviceId = ref('');
const event = ref('in');
const workDate = ref('');
const rawCode = ref('');
const input = ref<HTMLInputElement | null>(null);
const video = ref<HTMLVideoElement | null>(null);
const running = ref(false);
const busy = ref(false);
const error = ref('');
const result = ref<{
    employee_name: string;
    nik: string;
    message: string;
    recorded_at: string;
} | null>(null);
let controls: IScannerControls | undefined;
let lastCode = '';
let disposed = false;
const available = computed(() =>
    props.devices.filter(
        (d) =>
            d.active &&
            d.type ===
                (method.value === 'phone_camera' ? 'phone_camera' : 'scanner'),
    ),
);
watch(
    [method, () => props.devices],
    () => {
        stop();
        deviceId.value = String(available.value[0]?.id || '');
        lastCode = '';
    },
    { immediate: true },
);
watch([deviceId, event, workDate], () => {
    lastCode = '';
    result.value = null;
    error.value = '';
});
function stop() {
    running.value = false;
    controls?.stop();
    controls = undefined;
}
async function consume(text: string) {
    if (busy.value || text === lastCode || disposed) return;
    lastCode = text;
    error.value = '';
    result.value = null;
    try {
        const url = new URL(text);
        if (url.origin !== location.origin) throw new Error();
        const token = url.searchParams.get('employee_qr');
        if (!token || !/^[A-Za-z0-9]{64}$/.test(token)) throw new Error();
        busy.value = true;
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
                event: event.value,
                device_id: Number(deviceId.value),
                input_method: method.value,
                work_date: workDate.value || null,
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
            result.value = data;
            router.reload({ only: ['attendances'] });
        }
    } catch (e) {
        if (!disposed)
            error.value =
                e instanceof Error && e.message
                    ? e.message
                    : 'QR bukan QR karyawan dari aplikasi ini.';
    } finally {
        busy.value = false;
        rawCode.value = '';
    }
}
async function startCamera() {
    if (!deviceId.value || running.value) return;
    error.value = '';
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
        stop();
        error.value =
            'Kamera belum dapat diakses. Gunakan HTTPS, izinkan kamera, lalu pastikan kamera tidak dipakai aplikasi lain.';
    }
}
async function focusScanner() {
    lastCode = '';
    await nextTick();
    input.value?.focus();
}
onBeforeUnmount(() => {
    disposed = true;
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
            <span class="pill purple-light">{{
                event === 'in' ? 'ABSEN MASUK' : 'ABSEN PULANG'
            }}</span>
        </div>
        <div class="scanner-body">
            <div class="scanner-settings">
                <label
                    >Cara memindai<select v-model="method">
                        <option value="laptop_camera">Kamera laptop</option>
                        <option value="usb_scanner">Alat scanner QR</option>
                        <option value="phone_camera">Kamera HP</option>
                    </select></label
                ><label
                    >Perangkat kantor<select
                        v-model="deviceId"
                        :disabled="running || busy"
                    >
                        <option value="">Pilih perangkat…</option>
                        <option
                            v-for="d in available"
                            :key="d.id"
                            :value="String(d.id)"
                        >
                            {{ d.name }}
                        </option>
                    </select></label
                ><label
                    >Jenis absensi<select
                        v-model="event"
                        :disabled="running || busy"
                    >
                        <option value="in">Masuk</option>
                        <option value="out">Pulang</option>
                    </select></label
                ><label
                    >Tanggal kerja (opsional)<input
                        v-model="workDate"
                        type="date"
                        :disabled="running || busy"
                    /><small
                        >Kosongkan untuk mengikuti jadwal yang sedang
                        berlaku.</small
                    ></label
                >
            </div>
            <p v-if="!available.length" class="scanner-note">
                Belum ada perangkat aktif untuk pilihan ini. Pengelola perlu
                mendaftarkan perangkat pada menu Perangkat absensi.
            </p>
            <template v-if="method !== 'usb_scanner'"
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
                    :disabled="!deviceId || busy"
                    @click="running ? stop() : startCamera()"
                >
                    {{ running ? 'Hentikan kamera' : 'Aktifkan kamera' }}
                </button></template
            >
            <form
                v-else
                class="usb-scanner-form"
                @submit.prevent="consume(rawCode)"
            >
                <label
                    >Hasil scanner<input
                        ref="input"
                        v-model="rawCode"
                        :disabled="!deviceId || busy"
                        autocomplete="off"
                        placeholder="Klik di sini, lalu pindai QR karyawan" /></label
                ><button type="button" class="secondary" @click="focusScanner">
                    Fokuskan scanner</button
                ><button
                    class="primary"
                    :disabled="!deviceId || busy || !rawCode"
                >
                    Catat hasil pindai</button
                ><small
                    >Scanner yang berfungsi sebagai keyboard akan mengirim hasil
                    dan tombol Enter secara otomatis.</small
                >
            </form>
            <p v-if="busy" role="status">Mencatat kehadiran…</p>
            <div v-if="result" class="scan-success" role="status">
                <strong>{{ result.employee_name }} · {{ result.nik }}</strong>
                <p>{{ result.message }}</p>
                <small>{{ result.recorded_at }}</small>
            </div>
            <p v-if="error" role="alert" class="error">{{ error }}</p>
            <p class="scanner-note">
                Mode masuk dan pulang dipilih petugas. Memindai ulang pada mode
                yang sama tidak akan menggandakan absensi.
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
.scan-success {
    border: 1px solid #c3e8d5;
    border-radius: 12px;
    background: #edf9f2;
    padding: 18px;
    color: #26734e;
}
.scan-success strong {
    font-size: 16px;
}
.scan-success p {
    margin: 6px 0;
    font-size: 13px;
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
