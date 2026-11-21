<script setup lang="ts">
import { ref, watch, onBeforeUnmount } from 'vue';
import { toast } from 'vue-sonner';
import LatenessSummary from './LatenessSummary.vue';
import SearchInput from './SearchInput.vue';
import AppSelect from './AppSelect.vue';
const props = defineProps<{ initialSearch?: string; initialDate?: string }>();
interface Entry {
    work_date: string;
    name: string;
    position: string;
    department: string;
    status: string;
    clock_in: string | null;
    clock_out: string | null;
    late_minutes: number | null;
    early_minutes: number | null;
}
const today = new Date().toLocaleDateString('en-CA', {
    timeZone: 'Asia/Jakarta',
});
const from = ref(props.initialDate || today.slice(0, 8) + '01'),
    to = ref(props.initialDate || today),
    search = ref(props.initialSearch || ''),
    page = ref(1),
    last = ref(1),
    total = ref(0),
    rows = ref<Entry[]>([]),
    busy = ref(false),
    preset = ref('month');
const labels: Record<string, string> = {
    present: 'Hadir',
    sick: 'Sakit',
    permission: 'Izin',
    leave: 'Cuti',
    unexcused: 'Alfa',
    scheduled: 'Terjadwal',
};
let controller: AbortController | undefined;
let generation = 0;
let timer: ReturnType<typeof setTimeout>;
async function load() {
    controller?.abort();
    controller = new AbortController();
    const current = ++generation;
    rows.value = [];
    busy.value = true;
    try {
        const response = await fetch(
            '/attendance/report?' +
                new URLSearchParams({
                    from: from.value,
                    to: to.value,
                    search: search.value,
                    page: String(page.value),
                }),
            {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        );
        const data = await response.json();
        if (current !== generation) return;
        if (!response.ok)
            throw new Error(
                Object.values(data.errors || {})
                    .flat()
                    .join(' ') || data.message,
            );
        rows.value = data.data;
        last.value = data.last_page;
        total.value = data.total;
    } catch (e) {
        if (
            current === generation &&
            e instanceof Error &&
            e.name !== 'AbortError'
        )
            toast.error(e.message);
    } finally {
        if (current === generation) busy.value = false;
    }
}
watch([from, to, search], () => {
    page.value = 1;
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
watch(page, load);
watch(preset, (value) => {
    const end = new Date(today + 'T12:00:00');
    const start = new Date(end);
    if (value === 'week') start.setDate(start.getDate() - 6);
    else if (value === 'month') start.setDate(1);
    else return;
    from.value = start.toLocaleDateString('en-CA');
    to.value = today;
});
void load();
onBeforeUnmount(() => {
    clearTimeout(timer);
    controller?.abort();
});
</script>
<template>
    <section class="panel recap">
        <div class="panel-heading">
            <div>
                <h2>Rekap kehadiran</h2>
                <p>
                    Telusuri kehadiran berdasarkan tanggal kerja dan karyawan.
                </p>
            </div>
            <span>{{ total }} catatan</span>
        </div>
        <div class="filters">
            <SearchInput
                v-model="search"
                placeholder="Cari nama, jabatan, atau divisi…"
            /><AppSelect
                v-model="preset"
                :options="[
                    { value: 'week', label: '7 hari terakhir' },
                    { value: 'month', label: 'Bulan ini' },
                    { value: 'custom', label: 'Rentang khusus' },
                ]"
                aria-label="Rentang rekap"
            /><label
                >Dari tanggal<input
                    v-model="from"
                    type="date"
                    :max="to"
                    @input="preset = 'custom'" /></label
            ><label
                >Sampai tanggal<input
                    v-model="to"
                    type="date"
                    :min="from"
                    @input="preset = 'custom'"
            /></label>
        </div>
        <LatenessSummary :from="from" :to="to" :search="search" />
        <div class="table-wrap" :aria-busy="busy">
            <table>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Karyawan</th>
                        <th>Status</th>
                        <th>Masuk</th>
                        <th>Pulang</th>
                        <th>Terlambat</th>
                        <th>Pulang awal</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in rows" :key="i">
                        <td>{{ row.work_date }}</td>
                        <td>
                            <strong>{{ row.name }}</strong
                            ><small
                                >{{ row.position }} ·
                                {{ row.department }}</small
                            >
                        </td>
                        <td>
                            <span class="status" :class="row.status">{{
                                labels[row.status] || row.status
                            }}</span>
                        </td>
                        <td>{{ row.clock_in?.slice(11, 16) || '—' }}</td>
                        <td>{{ row.clock_out?.slice(11, 16) || '—' }}</td>
                        <td>{{ row.late_minutes || 0 }} menit</td>
                        <td>{{ row.early_minutes || 0 }} menit</td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td colspan="7">
                            {{
                                busy
                                    ? 'Memuat…'
                                    : 'Tidak ada catatan pada rentang ini.'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="pagination">
            <button
                class="secondary"
                :disabled="page <= 1 || busy"
                @click="page--"
            >
                Sebelumnya</button
            ><span>{{ page }} / {{ last }}</span
            ><button
                class="secondary"
                :disabled="page >= last || busy"
                @click="page++"
            >
                Berikutnya
            </button>
        </div>
    </section>
</template>
<style scoped>
.recap {
    grid-column: 1/-1;
}
.filters {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    padding: 0 24px 20px;
    align-items: end;
}
.filters label {
    font-size: 12px;
    display: grid;
    gap: 6px;
    color: #626983;
}
.filters input {
    padding: 10px;
    border: 1px solid #e6e7f2;
    border-radius: 10px;
    background: white;
    color: #252840;
}
.pagination {
    display: flex;
    gap: 16px;
    justify-content: flex-end;
    align-items: center;
    padding: 20px;
}
small {
    display: block;
    color: #9298ad;
    margin-top: 4px;
}
.status {
    padding: 5px 10px;
    border-radius: 8px;
    background: #f0f1f6;
    font-size: 12px;
}
.present {
    background: #e0f6eb;
    color: #15734d;
}
.unexcused {
    background: #fee7e7;
    color: #bd3434;
}
.sick {
    background: #fff3cd;
    color: #926900;
}
.permission,
.leave {
    background: #e5efff;
    color: #275ca6;
}
</style>
