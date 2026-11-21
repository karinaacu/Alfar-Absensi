<script setup lang="ts">
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import { toast } from 'vue-sonner';
import { notificationVersion } from '@/composables/useNotifications';
const props = defineProps<{
    from?: string;
    to?: string;
    mine?: boolean;
    search?: string;
}>();
interface Row {
    id: number;
    name: string;
    minutes: number;
    zone: string;
}
const rows = ref<Row[]>([]),
    page = ref(1);
const visible = computed(() =>
    rows.value.slice((page.value - 1) * 10, page.value * 10),
);
const total = computed(() => rows.value.reduce((n, r) => n + r.minutes, 0));
const labels: Record<string, string> = {
    safe: 'Safe Zone',
    warning: 'Warning Zone',
    red: 'Red Zone',
    unconfigured: 'Zona belum diatur',
};
let request: AbortController | undefined;
let timer: ReturnType<typeof setTimeout>;
async function load() {
    request?.abort();
    request = new AbortController();
    const today = new Date().toLocaleDateString('en-CA', {
        timeZone: 'Asia/Jakarta',
    });
    const end = new Date(today + 'T12:00:00');
    end.setMonth(end.getMonth() + 1, 0);
    try {
        const response = await fetch(
            '/attendance/lateness?' +
                new URLSearchParams({
                    from: props.from || today.slice(0, 8) + '01',
                    to: props.to || end.toLocaleDateString('en-CA'),
                    mine: props.mine ? '1' : '0',
                    search: props.search || '',
                }),
            { headers: { Accept: 'application/json' }, signal: request.signal },
        );
        const data = await response.json();
        if (!response.ok) throw new Error(data.message);
        rows.value = data;
        page.value = 1;
    } catch (e) {
        if (e instanceof Error && e.name !== 'AbortError')
            toast.error(e.message);
    }
}
watch(
    () => [props.from, props.to, props.search, props.mine],
    () => {
        clearTimeout(timer);
        timer = setTimeout(load, 350);
    },
);
watch(notificationVersion, () => {
    if (props.mine) void load();
});
void load();
onBeforeUnmount(() => {
    clearTimeout(timer);
    request?.abort();
});
</script>
<template>
    <section class="lateness-summary">
        <div>
            <h3>Akumulasi keterlambatan {{ mine ? 'bulan ini' : '' }}</h3>
            <p>
                <strong>{{ total }} menit</strong> ·
                {{ Math.floor(total / 60) }} jam {{ total % 60 }} menit
            </p>
            <small
                >Sudah dikurangi toleransi shift. Batas zona mengikuti periode
                penggajian yang mencakup rentang ini.</small
            >
        </div>
        <div class="lateness-rows">
            <div v-for="row in visible" :key="row.id">
                <span>{{ row.name }}</span
                ><strong>{{ row.minutes }} menit</strong
                ><span :class="['zone', row.zone]">{{ labels[row.zone] }}</span>
            </div>
        </div>
        <div v-if="rows.length > 10" class="late-pages">
            <button :disabled="page === 1" @click="page--">Sebelumnya</button
            ><span>{{ page }} / {{ Math.ceil(rows.length / 10) }}</span
            ><button :disabled="page * 10 >= rows.length" @click="page++">
                Berikutnya
            </button>
        </div>
    </section>
</template>
<style scoped>
.lateness-summary {
    padding: 20px 24px;
    background: #faf9ff;
    border-top: 1px solid #eeedf7;
    border-bottom: 1px solid #eeedf7;
    margin-bottom: 20px;
}
.lateness-summary h3 {
    font-size: 14px;
}
.lateness-summary p {
    font-size: 13px;
    margin: 8px 0;
    color: #70698c;
}
.lateness-summary small {
    font-size: 11px;
    color: #969aae;
    line-height: 1.7;
}
.lateness-rows {
    display: grid;
    gap: 8px;
    margin-top: 14px;
}
.lateness-rows > div {
    display: flex;
    gap: 14px;
    align-items: center;
    flex-wrap: wrap;
    font-size: 12px;
}
.lateness-rows > div > span:first-child {
    flex: 1;
}
.zone {
    padding: 5px 9px;
    border-radius: 7px;
    font-size: 11px;
    background: #eeeef4;
    color: #8d90a3;
}
.safe {
    background: #e3f6eb;
    color: #24734d;
}
.warning {
    background: #fff2d7;
    color: #9a7320;
}
.red {
    background: #ffe6ec;
    color: #b6334b;
}
.late-pages {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    font-size: 12px;
    margin-top: 12px;
}
.late-pages button {
    border: 1px solid #e0dbf4;
    border-radius: 8px;
    padding: 5px 8px;
    color: #706296;
    background: white;
}
</style>
