<script setup lang="ts">
import AppDateTimePicker from '@/components/alfar/AppDateTimePicker.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppSelect from '@/components/alfar/AppSelect.vue';
const props = defineProps<{
    employees: {
        id: number;
        name: string;
        position: string;
        department: string;
        active?: boolean | number;
    }[];
    shifts: { id: number; name: string }[];
    departments?: { id: number; name: string }[];
}>();
const emit = defineEmits<{ saved: [] }>();
const dateString = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const parse = (s: string) => {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, m - 1, d);
};
const today = dateString(new Date());
const preset = ref('week');
const department = ref('');
const form = useForm({
    employee_ids: [] as number[],
    shift_id: '' as string | number,
    starts_on: today,
    ends_on: today,
    weekdays: [1, 2, 3, 4, 5, 6, 7],
    existing: 'skip',
});
const available = computed(() =>
    props.employees.filter((e) => e.active !== false && e.active !== 0),
);
const departmentOptions = computed(() => [
    { value: '', label: 'Semua divisi' },
    ...[
        ...new Set(
            [
                ...(props.departments || []).map((d) => d.name),
                ...available.value.map((e) => e.department),
            ].filter(Boolean),
        ),
    ]
        .sort((a, b) => a.localeCompare(b, 'id'))
        .map((name) => ({ value: name, label: name })),
]);
const filteredEmployees = computed(() =>
    available.value.filter(
        (e) => !department.value || e.department === department.value,
    ),
);
function selectVisible() {
    form.employee_ids = [
        ...new Set([
            ...form.employee_ids,
            ...filteredEmployees.value.map((e) => e.id),
        ]),
    ];
}
const options = computed(() =>
    filteredEmployees.value.map((e) => ({
        value: e.id,
        label: e.name,
        description: `${e.position} · ${e.department}`,
    })),
);
const weekdays = [
    { id: 1, label: 'Senin' },
    { id: 2, label: 'Selasa' },
    { id: 3, label: 'Rabu' },
    { id: 4, label: 'Kamis' },
    { id: 5, label: 'Jumat' },
    { id: 6, label: 'Sabtu' },
    { id: 7, label: 'Minggu' },
];
function apply(value: string) {
    preset.value = value;
    if (value === 'custom' || !form.starts_on) return;
    const start = parse(form.starts_on);
    let end: Date;
    if (value === 'week') {
        end = new Date(start);
        end.setDate(end.getDate() + 6);
    } else {
        const months = value === 'month' ? 1 : 3;
        end = new Date(start.getFullYear(), start.getMonth() + months, 1);
        const last = new Date(
            end.getFullYear(),
            end.getMonth() + 1,
            0,
        ).getDate();
        end.setDate(Math.min(start.getDate(), last));
        end.setDate(end.getDate() - 1);
    }
    form.ends_on = dateString(end);
}
watch(
    () => form.starts_on,
    () => apply(preset.value),
);
apply('week');
const days = computed(() => {
    if (!form.starts_on || !form.ends_on) return 0;
    let count = 0;
    let day = parse(form.starts_on);
    const end = parse(form.ends_on);
    for (let i = 0; i < 367 && day <= end; i++, day.setDate(day.getDate() + 1))
        if (form.weekdays.includes(day.getDay() === 0 ? 7 : day.getDay()))
            count++;
    return count;
});
const total = computed(() => days.value * form.employee_ids.length);
</script>
<template>
    <form
        class="bulk-schedule-form"
        @submit.prevent="
            form.post('/schedules/bulk', {
                preserveScroll: true,
                onSuccess: () => emit('saved'),
            })
        "
    >
        <h2>Tetapkan jadwal massal</h2>
        <p>
            Pilih karyawan, shift, dan rentang tanggal dalam satu kali
            penyimpanan.
        </p>
        <label
            >Filter divisi<AppSelect
                v-model="department"
                :options="departmentOptions"
                aria-label="Filter divisi jadwal massal"
        /></label>
        <div class="bulk-section-heading">
            <label id="schedule-employees-label">Karyawan</label
            ><button
                type="button"
                class="text-button"
                :disabled="!filteredEmployees.length || form.processing"
                @click="selectVisible"
            >
                Pilih semua ({{ filteredEmployees.length }})</button
            ><button
                v-if="form.employee_ids.length"
                type="button"
                class="text-button"
                @click="form.employee_ids = []"
            >
                Kosongkan
            </button>
        </div>
        <AppSelect
            v-model="form.employee_ids"
            multiple
            :options="options"
            aria-labelledby="schedule-employees-label"
            placeholder="Pilih satu atau beberapa karyawan"
        /><small
            >{{ form.employee_ids.length }} karyawan dipilih. Cari berdasarkan
            nama, jabatan, atau divisi. Pilihan dari divisi lain tetap
            disimpan.</small
        ><label
            >Shift kerja<AppSelect
                v-model="form.shift_id"
                :options="shifts.map((s) => ({ value: s.id, label: s.name }))"
                aria-label="Shift kerja"
                placeholder="Pilih shift"
        /></label>
        <div class="bulk-presets">
            <button
                v-for="p in [
                    { value: 'week', label: '1 minggu ke depan' },
                    { value: 'month', label: '1 bulan ke depan' },
                    { value: 'quarter', label: '3 bulan ke depan' },
                    { value: 'custom', label: 'Rentang sendiri' },
                ]"
                :key="p.value"
                type="button"
                :class="{ selected: preset === p.value }"
                :aria-pressed="preset === p.value"
                @click="apply(p.value)"
            >
                {{ p.label }}
            </button>
        </div>
        <div class="bulk-date-range">
            <label
                >Tanggal mulai<AppDateTimePicker
                    v-model="form.starts_on"
                    label="Tanggal mulai"
                    mode="date"
                    required /></label
            ><label
                >Tanggal akhir<AppDateTimePicker
                    v-model="form.ends_on"
                    label="Tanggal akhir"
                    mode="date"
                    :min="form.starts_on"
                    required
                    @change="preset = 'custom'"
            /></label>
        </div>
        <small
            >Pilihan cepat dihitung dari tanggal mulai. Rentang mencakup tanggal
            mulai dan akhir.</small
        >
        <fieldset>
            <legend>Hari kerja</legend>
            <div class="bulk-weekdays">
                <label v-for="day in weekdays" :key="day.id"
                    ><input
                        v-model="form.weekdays"
                        type="checkbox"
                        :value="day.id"
                    />{{ day.label }}</label
                >
            </div>
            <small
                >Semua hari dipilih secara awal. Sesuaikan hari libur sesuai
                kebutuhan tim.</small
            >
        </fieldset>
        <label
            >Jika jadwal sudah ada<AppSelect
                v-model="form.existing"
                :options="[
                    {
                        value: 'skip',
                        label: 'Pertahankan jadwal yang sudah ada',
                    },
                    {
                        value: 'replace',
                        label: 'Perbarui jadwal yang belum memiliki absensi',
                    },
                ]"
                aria-label="Jika jadwal sudah ada"
        /></label>
        <div class="bulk-summary">
            <strong
                >{{ form.employee_ids.length }} karyawan × {{ days }} hari =
                {{ total.toLocaleString('id-ID') }} jadwal</strong
            >
            <p>
                Jadwal yang sudah memiliki catatan kehadiran selalu dilindungi.
                Maksimal 50.000 jadwal per penyimpanan.
            </p>
        </div>
        <button
            class="primary"
            :disabled="
                form.processing ||
                !form.employee_ids.length ||
                !form.shift_id ||
                !days ||
                total > 50000
            "
        >
            {{ form.processing ? 'Menyimpan jadwal…' : 'Simpan jadwal massal' }}
        </button>
    </form>
</template>
<style scoped>
.bulk-schedule-form {
    display: flex;
    flex-direction: column;
    gap: 17px;
    color: #475569;
}
.bulk-schedule-form h2 {
    font-size: 22px;
    color: #30304a;
    margin-right: 25px;
}
.bulk-schedule-form > p,
.bulk-schedule-form small {
    font-size: 11px;
    color: #7c849c;
    line-height: 1.8;
}
.bulk-schedule-form > label,
.bulk-date-range label {
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 12px;
    font-weight: 500;
}
.bulk-section-heading {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
    font-size: 12px;
}
.bulk-section-heading > label {
    margin-right: auto;
}
.bulk-presets {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 5px;
}
.bulk-presets button {
    padding: 9px 12px;
    border: 1px solid #e6e2f2;
    background: #fff;
    border-radius: 9px;
    color: #7c748d;
    font-size: 11px;
}
.bulk-presets button.selected {
    background: #eeebff;
    border-color: #c9bfff;
    color: #5546e8;
}
.bulk-date-range {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}
.bulk-weekdays {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    margin: 12px 0;
}
.bulk-weekdays label {
    display: flex;
    gap: 6px;
    align-items: center;
    font-size: 12px;
}
.bulk-weekdays input {
    accent-color: #5546e8;
}
.bulk-schedule-form legend {
    font-size: 12px;
    font-weight: 500;
}
.bulk-summary {
    border-radius: 12px;
    padding: 16px;
    background: #f2efff;
    color: #68578e;
}
.bulk-summary strong {
    font-size: 13px;
}
.bulk-summary p {
    font-size: 11px;
    margin-top: 7px;
    line-height: 1.8;
}
.bulk-schedule-form .alfar-select {
    width: 100%;
}
.bulk-schedule-form > .primary {
    width: 100%;
}
@media (max-width: 500px) {
    .bulk-date-range {
        grid-template-columns: 1fr;
    }
}
</style>
