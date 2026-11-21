<script setup lang="ts">
import {
    Maximize,
    Minimize,
    Volume2,
    VolumeX,
    Wifi,
    WifiOff,
    CheckCircle2,
    XCircle,
} from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import { toast, Toaster } from 'vue-sonner';
import { BrowserQRCodeReader, type IScannerControls } from '@zxing/browser';
import { nextTick, onMounted, onBeforeUnmount, ref } from 'vue';
export interface ScanDevice {
    [key: string]: unknown;
    id: number;
    name: string;
    type: string;
    active: boolean | number;
}
defineProps<{ devices: ScanDevice[] }>();
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
const opening = ref(false);
const station = ref<HTMLElement | null>(null);
const stationMode = ref(false);
const nativeFullscreen = ref(false);
const soundEnabled = ref(true);
const connected = ref(typeof navigator !== 'undefined' && navigator.onLine);
const checkingConnection = ref(false);
const lastResult = ref<{
    ok: boolean;
    message: string;
    name: string;
    time: string;
} | null>(null);
let audio: AudioContext | undefined;
let heartbeat: ReturnType<typeof setInterval> | undefined;
let connectionRequest: AbortController | undefined;
let scanRequest: AbortController | undefined;
function unlockSound() {
    if (!soundEnabled.value) return;
    try {
        audio ??= new AudioContext();
        void audio.resume().catch(() => {});
    } catch {
        /* Sound is optional when the browser blocks audio. */
    }
}
function playResult(ok: boolean) {
    if (!soundEnabled.value || !audio || audio.state !== 'running') return;
    try {
        const oscillator = audio.createOscillator();
        const gain = audio.createGain();
        oscillator.connect(gain);
        gain.connect(audio.destination);
        oscillator.frequency.value = ok ? 880 : 220;
        oscillator.type = 'sine';
        gain.gain.setValueAtTime(0.12, audio.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + 0.25);
        oscillator.start();
        oscillator.stop(audio.currentTime + 0.25);
        oscillator.onended = () => {
            oscillator.disconnect();
            gain.disconnect();
        };
    } catch {
        /* Attendance remains recorded if audio is unavailable. */
    }
}
async function toggleStation() {
    if (stationMode.value) {
        if (document.fullscreenElement === station.value)
            await document.exitFullscreen().catch(() => {});
        stationMode.value = false;
    } else {
        unlockSound();
        stationMode.value = true;
        await station.value?.requestFullscreen?.().catch(() => {});
    }
}
function fullscreenChanged() {
    nativeFullscreen.value = document.fullscreenElement === station.value;
    if (!document.fullscreenElement) stationMode.value = false;
}
function escapeStation(event: KeyboardEvent) {
    if (
        event.key === 'Escape' &&
        stationMode.value &&
        !document.fullscreenElement
    )
        stationMode.value = false;
}
async function checkConnection() {
    if (checkingConnection.value) return;
    if (!navigator.onLine) {
        connected.value = false;
        return;
    }
    checkingConnection.value = true;
    connectionRequest = new AbortController();
    const timeout = setTimeout(() => connectionRequest?.abort(), 6000);
    try {
        const response = await fetch('/up', {
            cache: 'no-store',
            signal: connectionRequest.signal,
        });
        if (!disposed) connected.value = response.ok;
    } catch {
        if (!disposed) connected.value = false;
    } finally {
        clearTimeout(timeout);
        checkingConnection.value = false;
    }
}
function offline() {
    connected.value = false;
}
onMounted(() => {
    void checkConnection();
    heartbeat = setInterval(() => {
        void checkConnection();
    }, 30000);
    window.addEventListener('online', checkConnection);
    window.addEventListener('offline', offline);
    document.addEventListener('fullscreenchange', fullscreenChanged);
    document.addEventListener('keydown', escapeStation);
});
function stop() {
    running.value = false;
    controls?.stop();
    controls = undefined;
}
async function consume(text: string, inputMethod = method.value) {
    if (busy.value || text === lastCode || disposed) return;
    lastCode = text;
    unlockSound();
    let scanTimeout: ReturnType<typeof setTimeout> | undefined;
    try {
        if (!navigator.onLine)
            throw new Error(
                'Perangkat sedang offline. Absensi belum dicatat. Sambungkan jaringan lalu pindai ulang.',
            );
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
        toast.loading('Mencatat kehadiran…', {
            id: 'scan-result',
            description: '',
        });
        scanRequest = new AbortController();
        scanTimeout = setTimeout(() => scanRequest?.abort(), 15000);
        const csrf = decodeURIComponent(
            document.cookie
                .split('; ')
                .find((c) => c.startsWith('XSRF-TOKEN='))
                ?.split('=')
                .slice(1)
                .join('=') || '',
        );
        const response = await fetch('/attendance/scan', {
            signal: scanRequest.signal,
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
        if ([401, 419].includes(response.status))
            throw new Error(
                'Sesi masuk berakhir. Muat ulang halaman lalu masuk kembali sebelum memindai.',
            );
        const data = await response.json().catch(() => ({
            message:
                'Server belum dapat mencatat absensi. Muat ulang halaman lalu coba lagi.',
        }));
        if (!response.ok || !data.event) {
            if (response.status >= 500) connected.value = false;
            const messages = data.errors
                ? Object.values(data.errors).flat().join(' ')
                : data.message;
            throw new Error(messages || 'Absensi belum dapat dicatat.');
        }
        if (!disposed) {
            connected.value = true;
            lastResult.value = {
                ok: true,
                message: data.message,
                name: data.employee_name,
                time: data.recorded_at,
            };
            playResult(true);
            toast.success(data.message, {
                id: 'scan-result',
                description: `${data.employee_name} · ${data.recorded_at}`,
            });
            router.reload({ only: ['attendances'] });
        }
    } catch (e) {
        if (!disposed) {
            const message =
                e instanceof TypeError ||
                (e instanceof Error && e.name === 'AbortError')
                    ? 'Koneksi terputus. Absensi belum dikonfirmasi. Sambungkan jaringan lalu pindai ulang.'
                    : e instanceof Error && e.message
                      ? e.message
                      : 'QR bukan QR karyawan dari aplikasi ini.';
            if (
                e instanceof TypeError ||
                (e instanceof Error && e.name === 'AbortError')
            )
                connected.value = false;
            lastResult.value = {
                ok: false,
                message,
                name:
                    e instanceof TypeError ||
                    (e instanceof Error && e.name === 'AbortError')
                        ? 'Hasil belum dikonfirmasi'
                        : 'Absensi belum berhasil',
                time: new Date().toLocaleTimeString('id-ID', {
                    timeZone: 'Asia/Jakarta',
                }),
            };
            playResult(false);
            toast.error(message, { id: 'scan-result', description: '' });
        }
    } finally {
        if (scanTimeout) clearTimeout(scanTimeout);
        busy.value = false;
        rawCode.value = '';
    }
}
async function startCamera() {
    if (opening.value || running.value) return;
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
        toast.error(
            'Kamera memerlukan HTTPS. Buka alamat aplikasi yang aman di browser dan izinkan akses kamera.',
        );
        return;
    }
    unlockSound();
    opening.value = true;
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
    } catch (error) {
        if (disposed) return;
        stop();
        const name = error instanceof Error ? error.name : '';
        const message = ['NotAllowedError', 'PermissionDeniedError'].includes(
            name,
        )
            ? 'Izin kamera ditolak. Izinkan kamera melalui ikon gembok di alamat browser, lalu coba lagi.'
            : ['NotFoundError', 'DevicesNotFoundError'].includes(name)
              ? 'Kamera tidak ditemukan. Sambungkan kamera laptop atau webcam, lalu coba lagi.'
              : name === 'NotReadableError'
                ? 'Kamera sedang dipakai aplikasi lain. Tutup aplikasi tersebut lalu coba lagi.'
                : 'Kamera belum dapat dibuka. Periksa izin kamera browser lalu coba lagi.';
        toast.error(message, { id: 'camera-error' });
    } finally {
        opening.value = false;
    }
}
async function focusScanner() {
    unlockSound();
    lastCode = '';
    await nextTick();
    input.value?.focus();
}
onBeforeUnmount(() => {
    disposed = true;
    if (heartbeat) clearInterval(heartbeat);
    connectionRequest?.abort();
    scanRequest?.abort();
    window.removeEventListener('online', checkConnection);
    window.removeEventListener('offline', offline);
    document.removeEventListener('fullscreenchange', fullscreenChanged);
    document.removeEventListener('keydown', escapeStation);
    void audio?.close().catch(() => {});
    if (document.fullscreenElement === station.value)
        void document.exitFullscreen().catch(() => {});
    toast.dismiss('scan-result');
    stop();
});
</script>
<template>
    <section
        ref="station"
        class="panel scanner-station"
        :class="{ 'station-full': stationMode }"
    >
        <Toaster
            v-if="nativeFullscreen"
            position="top-right"
            rich-colors
            close-button
        />
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
        <div class="station-toolbar">
            <span
                :class="['connection-status', { offline: !connected }]"
                role="status"
                ><Wifi v-if="connected" :size="15" /><WifiOff
                    v-else
                    :size="15"
                />{{
                    connected ? 'Server terhubung' : 'Koneksi terputus'
                }}</span
            ><button
                type="button"
                class="secondary"
                :aria-pressed="soundEnabled"
                @click="
                    soundEnabled = !soundEnabled;
                    unlockSound();
                "
            >
                <Volume2 v-if="soundEnabled" :size="16" /><VolumeX
                    v-else
                    :size="16"
                />{{ soundEnabled ? 'Suara aktif' : 'Suara mati' }}</button
            ><button type="button" class="secondary" @click="toggleStation">
                <Minimize v-if="stationMode" :size="16" /><Maximize
                    v-else
                    :size="16"
                />{{ stationMode ? 'Keluar layar penuh' : 'Layar penuh' }}
            </button>
        </div>
        <div class="scanner-body">
            <div
                v-if="lastResult"
                :class="[
                    'station-result',
                    lastResult.ok ? 'result-success' : 'result-error',
                ]"
                role="status"
                aria-live="polite"
            >
                <CheckCircle2 v-if="lastResult.ok" :size="30" /><XCircle
                    v-else
                    :size="30"
                />
                <div>
                    <strong>{{ lastResult.name }}</strong>
                    <p>{{ lastResult.message }}</p>
                    <small>{{ lastResult.time }}</small>
                </div>
            </div>
            <p v-if="!connected" class="offline-instruction">
                Absensi memerlukan koneksi server. Data tidak disimpan offline;
                sambungkan jaringan sebelum memindai ulang.
            </p>
            <p class="scanner-note">
                Gunakan kamera pada perangkat ini atau scanner USB. Setiap
                laptop dan HP dapat membuka pemindai sendiri secara bersamaan,
                tanpa memilih perangkat.
            </p>
            <div class="scanner-capture">
                <div class="scanner-camera">
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
                    :disabled="opening"
                    @click="running ? stop() : startCamera()"
                >
                    {{
                        opening
                            ? 'Membuka kamera…'
                            : running
                              ? 'Hentikan kamera'
                              : 'Aktifkan kamera'
                    }}
                </button>
            </div>
            <form
                class="usb-scanner-form"
                @submit.prevent="consume(rawCode, 'usb_scanner')"
            >
                <label
                    >Hasil scanner<input
                        ref="input"
                        v-model="rawCode"
                        :disabled="busy"
                        autocomplete="off"
                        placeholder="Klik di sini, lalu pindai QR karyawan" /></label
                ><button type="button" class="secondary" @click="focusScanner">
                    Fokuskan scanner</button
                ><button class="primary" :disabled="busy || !rawCode">
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
.scanner-capture {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
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
<style scoped>
.station-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    padding: 0 24px 20px;
}
.station-toolbar button {
    display: inline-flex;
    gap: 8px;
    align-items: center;
}
.connection-status {
    display: inline-flex;
    gap: 7px;
    align-items: center;
    font-size: 12px;
    color: #25835d;
    margin-right: auto;
}
.connection-status.offline {
    color: #b74350;
}
.station-result {
    display: flex;
    gap: 16px;
    align-items: center;
    padding: 20px;
    border-radius: 16px;
    border: 1px solid transparent;
}
.station-result strong {
    font-size: 22px;
}
.station-result p {
    margin: 5px 0;
    font-size: 14px;
}
.station-result small {
    font-size: 12px;
}
.result-success {
    background: #e8f8ef;
    color: #24744f;
    border-color: #bfe8d2;
}
.result-error {
    background: #fff0f2;
    color: #b33548;
    border-color: #ffd3dc;
}
.offline-instruction {
    padding: 12px;
    border-radius: 10px;
    background: #fff3e4;
    color: #9d6c29;
    font-size: 12px;
}
.station-full {
    position: fixed;
    inset: 0;
    z-index: 500;
    margin: 0 !important;
    border-radius: 0 !important;
    overflow: auto;
    background: #f8f9fd;
    padding: 24px max(16px, calc((100vw - 1100px) / 2));
    height: 100dvh;
    box-sizing: border-box;
    color: #292c44;
    font-family: 'Instrument Sans', sans-serif;
}
.station-full .panel-heading {
    padding: 16px 24px 24px;
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: center;
}
.station-full .panel-heading h2 {
    font-size: 23px;
}
.station-full .panel-heading p {
    font-size: 12px;
    color: #838ba1;
}
.station-full :deep(.primary) {
    padding: 12px 18px;
    border-radius: 10px;
    border: 0;
    background: #5946e8;
    color: white;
    cursor: pointer;
}
.station-full :deep(.secondary) {
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid #dedfec;
    background: white;
    color: #666581;
    cursor: pointer;
}
.station-full input {
    padding: 12px;
    border: 1px solid #dedfec;
    border-radius: 10px;
    background: white;
    color: #30354d;
}
.station-full .scanner-camera {
    max-width: 720px;
}
.station-full .scanner-camera video {
    max-height: 46dvh;
}
@media (max-width: 600px) {
    .station-toolbar {
        padding: 0 18px 18px;
    }
    .station-result strong {
        font-size: 18px;
    }
    .station-full {
        padding: 10px;
    }
    .station-full .panel-heading {
        padding: 16px;
        flex-wrap: wrap;
    }
}
</style>
