<script setup lang="ts">
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import {
    Check,
    X,
    HeartPulse,
    FileCheck,
    Clock3,
    ChevronLeft,
    ChevronRight,
    ArrowLeft,
    CalendarDays,
} from '@lucide/vue';
import SearchInput from './SearchInput.vue';
interface Employee {
    id: number;
    name: string;
    position: string;
    department: string;
}
interface Schedule {
    id: number;
    work_date: string;
    scheduled_in: string;
    scheduled_out: string;
    shift: string;
}
interface Punch {
    work_date: string;
    status: string;
    clock_in: string | null;
    clock_out: string | null;
}
const props = defineProps<{ employees: Employee[]; canManage: boolean }>();
const search = ref(''),
    selected = ref<Employee | null>(null),
    view = ref('month'),
    cursor = ref(
        new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Jakarta' }),
    ),
    chosen = ref(''),
    schedules = ref<Schedule[]>([]),
    punches = ref<Punch[]>([]),
    serverNow = ref(''),
    busy = ref(false);
const filtered = computed(() =>
    props.employees.filter((e) =>
        `${e.name} ${e.position} ${e.department}`
            .toLocaleLowerCase('id')
            .includes(search.value.toLocaleLowerCase('id')),
    ),
);
const key = (d: Date) => d.toLocaleDateString('en-CA');
const bounds = computed(() => {
    const start = new Date(cursor.value + 'T12:00:00');
    let end: Date;
    if (view.value === 'month') {
        start.setDate(1);
        end = new Date(start.getFullYear(), start.getMonth() + 1, 0, 12);
    } else {
        start.setDate(start.getDate() - ((start.getDay() + 6) % 7));
        end = new Date(start);
        end.setDate(end.getDate() + 6);
    }
    return { start, end, from: key(start), to: key(end) };
});
const days = computed(() => {
    const result = [];
    const start = new Date(bounds.value.start);
    start.setDate(start.getDate() - ((start.getDay() + 6) % 7));
    const end = new Date(bounds.value.end);
    end.setDate(end.getDate() + ((7 - end.getDay()) % 7));
    for (const d = new Date(start); d <= end; d.setDate(d.getDate() + 1))
        result.push({
            date: key(d),
            day: d.getDate(),
            inside: d >= bounds.value.start && d <= bounds.value.end,
        });
    return result;
});
const heading = computed(() =>
    bounds.value.start.toLocaleDateString('id-ID', {
        month: 'long',
        year: 'numeric',
    }),
);
const labels: Record<string, string> = {
    present: 'Hadir',
    unexcused: 'Alfa',
    sick: 'Sakit',
    permission: 'Izin',
    leave: 'Cuti',
    scheduled: 'Terjadwal',
    off: 'Libur',
};
function status(date: string) {
    const punch = punches.value.find((p) => p.work_date === date);
    if (punch) return punch.status;
    const schedule = schedules.value.find((s) => s.work_date === date);
    if (!schedule) return 'off';
    return schedule.scheduled_out <
        serverNow.value.slice(0, 19).replace('T', ' ')
        ? 'unexcused'
        : 'scheduled';
}
function icon(state: string) {
    return state === 'present'
        ? Check
        : state === 'unexcused'
          ? X
          : state === 'sick'
            ? HeartPulse
            : ['permission', 'leave'].includes(state)
              ? FileCheck
              : Clock3;
}
let controller: AbortController | undefined;
let generation = 0;
async function load() {
    if (!selected.value) return;
    controller?.abort();
    controller = new AbortController();
    const current = ++generation;
    schedules.value = [];
    punches.value = [];
    busy.value = true;
    try {
        const response = await fetch(
            `/employees/${selected.value.id}/calendar?` +
                new URLSearchParams({
                    from: bounds.value.from,
                    to: bounds.value.to,
                }),
            {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        );
        const data = await response.json();
        if (current !== generation) return;
        if (!response.ok) throw new Error(data.message);
        schedules.value = data.schedules;
        punches.value = data.attendances;
        serverNow.value = data.now;
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
watch([selected, bounds], () => {
    chosen.value = '';
    void load();
});
onBeforeUnmount(() => controller?.abort());
function move(direction: number) {
    const date = new Date(cursor.value + 'T12:00:00');
    if (view.value === 'month') {
        date.setDate(1);
        date.setMonth(date.getMonth() + direction);
    } else date.setDate(date.getDate() + direction * 7);
    cursor.value = key(date);
}
function remove(from: string, to: string) {
    if (!selected.value) return;
    const id = selected.value.id;
    toast('Hapus jadwal yang dipilih?', {
        description: 'Jadwal dengan catatan absensi tidak akan dihapus.',
        action: {
            label: 'Hapus jadwal',
            onClick: () =>
                router.delete(`/employees/${id}/schedules`, {
                    data: { from, to },
                    preserveScroll: true,
                    onSuccess: () => {
                        void load();
                    },
                }),
        },
        cancel: { label: 'Batal', onClick: () => {} },
        duration: 12000,
    });
}
</script>
<template>
    <section class="employee-schedules">
        <template v-if="!selected"
            ><div class="toolbar">
                <SearchInput
                    v-model="search"
                    placeholder="Cari karyawan, jabatan, atau divisi…"
                /><span>{{ filtered.length }} karyawan</span>
            </div>
            <div class="employee-grid">
                <button
                    v-for="e in filtered"
                    :key="e.id"
                    class="schedule-person"
                    @click="selected = e"
                >
                    <span class="avatar">{{
                        e.name
                            .split(' ')
                            .map((s) => s[0])
                            .slice(0, 2)
                            .join('')
                    }}</span
                    ><strong>{{ e.name }}</strong
                    ><small>{{ e.position }} · {{ e.department }}</small
                    ><span class="open-calendar"
                        ><CalendarDays :size="16" /> Lihat jadwal &
                        kalender</span
                    >
                </button>
            </div>
            <p v-if="!filtered.length">
                Tidak ada karyawan yang sesuai.
            </p></template
        ><template v-else
            ><button class="text-button back" @click="selected = null">
                <ArrowLeft :size="16" /> Semua karyawan
            </button>
            <div class="panel">
                <div class="panel-heading">
                    <div>
                        <h2>{{ selected.name }}</h2>
                        <p>
                            {{ selected.position }} · {{ selected.department }}
                        </p>
                    </div>
                    <div class="view-toggle">
                        <button
                            :class="{ active: view === 'week' }"
                            @click="view = 'week'"
                        >
                            Minggu</button
                        ><button
                            :class="{ active: view === 'month' }"
                            @click="view = 'month'"
                        >
                            Bulan
                        </button>
                    </div>
                </div>
                <div class="calendar-nav">
                    <button aria-label="Periode sebelumnya" @click="move(-1)">
                        <ChevronLeft :size="18" /></button
                    ><strong>{{ heading }}</strong
                    ><button aria-label="Periode berikutnya" @click="move(1)">
                        <ChevronRight :size="18" /></button
                    ><button
                        class="text-button"
                        @click="
                            cursor = new Date().toLocaleDateString('en-CA', {
                                timeZone: 'Asia/Jakarta',
                            })
                        "
                    >
                        Hari ini
                    </button>
                </div>
                <div class="schedule-list">
                    <h3>Jadwal kerja · {{ bounds.from }} – {{ bounds.to }}</h3>
                    <p v-if="busy">Memuat jadwal…</p>
                    <p v-else-if="!schedules.length">
                        Belum ada jadwal pada periode ini.
                    </p>
                    <div
                        v-for="s in schedules"
                        :key="s.id"
                        class="schedule-row"
                    >
                        <span
                            ><strong>{{ s.work_date }}</strong
                            ><small>{{ s.shift }}</small></span
                        ><span
                            >{{ s.scheduled_in.slice(11, 16) }} –
                            {{ s.scheduled_out.slice(11, 16) }}</span
                        ><button
                            v-if="
                                canManage &&
                                !punches.some(
                                    (p) => p.work_date === s.work_date,
                                )
                            "
                            class="text-button"
                            @click="remove(s.work_date, s.work_date)"
                        >
                            Hapus
                        </button>
                    </div>
                </div>
                <div class="calendar">
                    <div class="legend">
                        <span
                            v-for="state in [
                                'present',
                                'unexcused',
                                'sick',
                                'permission',
                            ]"
                            :key="state"
                            :class="state"
                            ><component :is="icon(state)" :size="14" />{{
                                labels[state]
                            }}</span
                        >
                    </div>
                    <div class="calendar-grid">
                        <strong
                            v-for="day in [
                                'Sen',
                                'Sel',
                                'Rab',
                                'Kam',
                                'Jum',
                                'Sab',
                                'Min',
                            ]"
                            :key="day"
                            class="weekday"
                            >{{ day }}</strong
                        ><button
                            v-for="day in days"
                            :key="day.date"
                            :disabled="!day.inside || busy"
                            :class="[
                                'calendar-day',
                                day.inside ? status(day.date) : 'outside',
                                { selected: chosen === day.date },
                            ]"
                            :aria-label="`${day.date}: ${day.inside ? labels[status(day.date)] : 'Di luar periode'}`"
                            @click="chosen = day.date"
                        >
                            <span>{{ day.day }}</span
                            ><template v-if="day.inside"
                                ><component
                                    :is="icon(status(day.date))"
                                    :size="16"
                                /><small>{{
                                    labels[status(day.date)]
                                }}</small></template
                            >
                        </button>
                    </div>
                    <p v-if="chosen" class="day-detail">
                        <strong
                            >{{ chosen }} · {{ labels[status(chosen)] }}</strong
                        ><span
                            >Masuk
                            {{
                                punches
                                    .find((p) => p.work_date === chosen)
                                    ?.clock_in?.slice(11, 16) || '—'
                            }}
                            · Pulang
                            {{
                                punches
                                    .find((p) => p.work_date === chosen)
                                    ?.clock_out?.slice(11, 16) || '—'
                            }}</span
                        >
                    </p>
                    <button
                        v-if="canManage && schedules.length"
                        class="text-button"
                        @click="remove(bounds.from, bounds.to)"
                    >
                        Hapus jadwal pada periode ini
                    </button>
                </div>
            </div></template
        >
    </section>
</template>
<style scoped>
.employee-schedules {
    grid-column: 1/-1;
    min-width: 0;
}
.schedule-person {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    padding: 28px 18px;
    border: 1px solid #e8eaf5;
    border-radius: 20px;
    background: white;
    color: #272b45;
    cursor: pointer;
    text-align: center;
}
.schedule-person:hover {
    border-color: #6754eb;
    box-shadow: 0 8px 22px #5143b512;
}
.avatar {
    display: grid;
    place-items: center;
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background: #f0edff;
    color: #5944d5;
    font-weight: 700;
}
.schedule-person small {
    color: #7b829b;
}
.open-calendar {
    display: flex;
    gap: 8px;
    color: #5d49df;
    font-size: 12px;
    margin-top: 8px;
}
.back {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
}
.view-toggle {
    display: flex;
    background: #f4f3fa;
    border-radius: 10px;
    padding: 4px;
}
.view-toggle button {
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #6c728b;
    padding: 8px 14px;
    cursor: pointer;
}
.view-toggle .active {
    background: #5945e9;
    color: white;
}
.calendar-nav {
    display: flex;
    gap: 14px;
    align-items: center;
    padding: 0 24px 20px;
    text-transform: capitalize;
}
.calendar-nav > button {
    border: 1px solid #e8eaf5;
    background: white;
    border-radius: 8px;
    padding: 7px;
    color: #554c83;
    cursor: pointer;
}
.schedule-list {
    padding: 0 24px 20px;
    max-height: 260px;
    overflow: auto;
}
.schedule-list h3 {
    font-size: 13px;
    color: #626983;
}
.schedule-row {
    display: flex;
    align-items: center;
    gap: 20px;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid #f0f0f7;
    font-size: 12px;
}
.schedule-row small {
    display: block;
    color: #8b91a5;
    margin-top: 4px;
}
.calendar {
    padding: 20px 24px;
    border-top: 1px solid #eeeef7;
}
.legend {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
}
.legend span {
    display: flex;
    align-items: center;
    gap: 6px;
    border-radius: 6px;
    padding: 5px 9px;
    font-size: 12px;
}
.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 7px;
}
.weekday {
    text-align: center;
    font-size: 12px;
    color: #8b91a5;
    padding: 8px;
}
.calendar-day {
    border: 1px solid transparent;
    border-radius: 12px;
    min-height: 92px;
    padding: 10px;
    display: flex;
    flex-direction: column;
    align-items: start;
    gap: 5px;
    text-align: left;
    cursor: pointer;
}
.calendar-day small {
    font-size: 10px;
}
.present {
    background: #e5f7ec;
    color: #19794d;
}
.unexcused {
    background: #ffe9e9;
    color: #bf3b43;
}
.sick {
    background: #fff5d7;
    color: #9c7515;
}
.permission,
.leave {
    background: #e8f0ff;
    color: #326cbb;
}
.scheduled {
    background: #f0edff;
    color: #7862bd;
}
.off {
    background: #f7f8fb;
    color: #a0a6b7;
}
.outside {
    background: transparent;
    color: #d0d3de;
}
.calendar-day.selected {
    border-color: #5945e9;
}
.day-detail {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    font-size: 12px;
    padding: 16px;
    background: #f7f6fe;
    border-radius: 10px;
}
@media (max-width: 640px) {
    .calendar {
        padding: 14px 10px;
    }
    .calendar-grid {
        gap: 3px;
    }
    .calendar-day {
        padding: 6px;
        min-height: 73px;
        border-radius: 7px;
    }
    .calendar-day small {
        display: none;
    }
    .calendar-nav {
        gap: 8px;
        padding: 0 14px 20px;
    }
    .panel-heading {
        flex-wrap: wrap;
        gap: 12px;
    }
}
</style>
