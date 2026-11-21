<script setup lang="ts">
import AppDateTimePicker from '@/components/alfar/AppDateTimePicker.vue';
import { computed, ref, onBeforeUnmount, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { notificationVersion } from '@/composables/useNotifications';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';
import SearchInput from './SearchInput.vue';
import AppSelect from './AppSelect.vue';
import PayComponents, { type PayComponent } from './PayComponents.vue';
import PayrollPolicyFields, { type PayPolicy } from './PayrollPolicyFields.vue';
const props = defineProps<{
    mode: 'manage' | 'mine';
    canManage: boolean;
    canPublish: boolean;
}>();
interface Employee {
    id: number;
    name: string;
    position: string;
    department: string;
    base_salary: string | null;
    components: PayComponent[];
}
interface Run extends PayPolicy {
    id: number;
    name: string;
    starts_on: string;
    ends_on: string;
    pays_on: string;
    status: string;
}
interface Snapshot {
    name: string;
    position: string;
    department: string;
    base_salary: number;
    components: PayComponent[];
    late_minutes: number;
    charged_late_minutes: number;
    late_deduction: number;
    overtime_minutes: number;
    overtime_pay: number;
    pending_overtime: number;
    gross: number;
    deductions: number;
    net: number;
    zone: string;
    salary_configured: boolean;
    period: {
        name: string;
        starts_on: string;
        ends_on: string;
        pays_on: string;
    };
    policy: PayPolicy;
}
interface Item {
    id: number;
    employee_id: number;
    snapshot: Snapshot;
    adjustments: PayComponent[];
}
const employees = ref<Employee[]>([]),
    runs = ref<Run[]>([]),
    items = ref<Item[]>([]),
    slips = ref<Item[]>([]),
    run = ref<Run | null>(null),
    query = ref(''),
    section = ref('runs'),
    loading = ref(false);
const profile = ref<Employee | null>(null),
    creating = ref(false),
    editingPolicy = ref(false),
    adjusting = ref<Item | null>(null),
    publishing = ref(false),
    publishingBusy = ref(false),
    preview = ref<Item | null>(null);
const today = new Date().toLocaleDateString('en-CA', {
    timeZone: 'Asia/Jakarta',
});
const end = new Date(today + 'T12:00:00');
end.setMonth(end.getMonth() + 1, 0);
const blankPolicy = (): PayPolicy => ({
    safe_minutes: '',
    warning_minutes: '',
    free_late_minutes: 0,
    late_rate: '',
    late_cap: '',
    overtime_rate: '',
});
const runForm = useForm({
    name: '',
    starts_on: today.slice(0, 8) + '01',
    ends_on: end.toLocaleDateString('en-CA'),
    pays_on: end.toLocaleDateString('en-CA'),
    employee_ids: [] as number[],
    ...blankPolicy(),
});
const profileForm = useForm({
    base_salary: '' as string | number,
    components: [] as PayComponent[],
});
const policyForm = useForm<PayPolicy>(blankPolicy());
const adjustForm = useForm({ components: [] as PayComponent[], reason: '' });
const deletingDraft = ref(false),
    deleteBusy = ref(false);
function deleteDraft() {
    if (!run.value || deleteBusy.value) return;
    deleteBusy.value = true;
    router.delete(`/payroll/runs/${run.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            run.value = null;
            items.value = [];
            deletingDraft.value = false;
            void load();
        },
        onFinish: () => {
            deleteBusy.value = false;
        },
    });
}
const money = (amount: number | string | null) =>
    'Rp ' + Number(amount || 0).toLocaleString('id-ID');
const zoneLabels: Record<string, string> = {
    safe: 'Safe Zone',
    warning: 'Warning Zone',
    red: 'Red Zone',
    unconfigured: 'Zona belum diatur',
};
const filteredEmployees = computed(() =>
    employees.value.filter((e) =>
        `${e.name} ${e.position} ${e.department}`
            .toLowerCase()
            .includes(query.value.toLowerCase()),
    ),
);
const filteredItems = computed(() =>
    items.value.filter((e) =>
        `${e.snapshot.name} ${e.snapshot.department}`
            .toLowerCase()
            .includes(query.value.toLowerCase()),
    ),
);
let disposed = false;
let generation = 0;
async function request(url: string) {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
    });
    const data = await response.json();
    if (!response.ok)
        throw new Error(
            Object.values(data.errors || {})
                .flat()
                .join(' ') || data.message,
        );
    return data;
}
async function load() {
    loading.value = true;
    try {
        if (props.mode === 'mine') {
            const data = await request('/payroll/slips');
            if (!disposed) slips.value = data;
        } else {
            const data = await request('/payroll');
            if (disposed) return;
            employees.value = data.employees;
            runs.value = data.runs;
            if (run.value) await openRun(run.value.id);
        }
    } catch (e) {
        if (!disposed)
            toast.error(
                e instanceof Error
                    ? e.message
                    : 'Data penggajian belum dapat dimuat.',
            );
    } finally {
        loading.value = false;
    }
}
async function openRun(id: number) {
    const current = ++generation;
    try {
        const data = await request(`/payroll/runs/${id}`);
        if (disposed || current !== generation) return;
        run.value = data.run;
        items.value = data.items;
    } catch (e) {
        toast.error(
            e instanceof Error ? e.message : 'Periode belum dapat dibuka.',
        );
    }
}
function editProfile(e: Employee) {
    profileForm.reset();
    profileForm.clearErrors();
    profileForm.base_salary = e.base_salary ?? '';
    profileForm.components = e.components.map((c) => ({ ...c }));
    profile.value = e;
}
function saveProfile() {
    if (profile.value)
        profileForm.post(`/payroll/profiles/${profile.value.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                profile.value = null;
                void load();
            },
        });
}
function createRun() {
    const oldMax = Math.max(0, ...runs.value.map((r) => r.id));
    runForm.post('/payroll/runs', {
        preserveScroll: true,
        onSuccess: async () => {
            creating.value = false;
            await load();
            const created = runs.value.find((r) => r.id > oldMax);
            if (created) void openRun(created.id);
        },
    });
}
function editPolicy() {
    if (!run.value) return;
    for (const key of Object.keys(blankPolicy()) as (keyof PayPolicy)[])
        policyForm[key] = run.value[key] ?? '';
    policyForm.clearErrors();
    editingPolicy.value = true;
}
function savePolicy() {
    if (run.value)
        policyForm.post(`/payroll/runs/${run.value.id}/policy`, {
            preserveScroll: true,
            onSuccess: () => {
                editingPolicy.value = false;
                void load();
            },
        });
}
function editAdjust(item: Item) {
    adjustForm.reset();
    adjustForm.clearErrors();
    adjustForm.components = item.adjustments.map((c) => ({ ...c }));
    adjusting.value = item;
}
function saveAdjust() {
    if (adjusting.value)
        adjustForm.post(`/payroll/items/${adjusting.value.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                adjusting.value = null;
                void load();
            },
        });
}
function recalculate() {
    if (run.value)
        router.post(
            `/payroll/runs/${run.value.id}/recalculate`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    void load();
                },
            },
        );
}
function publish() {
    if (!run.value || publishingBusy.value) return;
    publishingBusy.value = true;
    router.post(
        `/payroll/runs/${run.value.id}/publish`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                publishing.value = false;
                void load();
            },
            onFinish: () => {
                publishingBusy.value = false;
            },
        },
    );
}
watch(
    () => props.mode,
    () => {
        run.value = null;
        items.value = [];
        query.value = '';
        void load();
    },
);
watch(notificationVersion, () => {
    if (props.mode === 'mine') void load();
});
void load();
onBeforeUnmount(() => {
    disposed = true;
});
</script>
<template>
    <div class="payroll-workspace">
        <template v-if="mode === 'manage'"
            ><div class="pay-tabs">
                <Button
                    :variant="section === 'runs' ? 'default' : 'outline'"
                    @click="
                        section = 'runs';
                        query = '';
                    "
                    >Periode penggajian</Button
                ><Button
                    :variant="section === 'profiles' ? 'default' : 'outline'"
                    @click="
                        section = 'profiles';
                        query = '';
                    "
                    >Komponen gaji karyawan</Button
                >
            </div>
            <section v-if="section === 'profiles'" class="panel">
                <div class="panel-heading">
                    <div>
                        <h2>Komponen gaji karyawan</h2>
                        <p>
                            Gaji pokok, tunjangan, bonus, iuran dan potongan
                            tetap. Nominal dalam rupiah.
                        </p>
                    </div>
                </div>
                <div class="pay-toolbar">
                    <SearchInput
                        v-model="query"
                        placeholder="Cari karyawan atau divisi…"
                    />
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Karyawan</th>
                                <th>Gaji pokok</th>
                                <th>Komponen tambahan</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in filteredEmployees" :key="e.id">
                                <td>
                                    <strong>{{ e.name }}</strong
                                    ><small
                                        >{{ e.position }} ·
                                        {{ e.department }}</small
                                    >
                                </td>
                                <td>
                                    {{
                                        e.base_salary === null
                                            ? 'Belum diisi'
                                            : money(e.base_salary)
                                    }}
                                </td>
                                <td>{{ e.components.length }} komponen</td>
                                <td>
                                    <Button
                                        v-if="canManage"
                                        variant="outline"
                                        @click="editProfile(e)"
                                        >Atur gaji</Button
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <template v-else
                ><section class="panel">
                    <div class="panel-heading">
                        <div>
                            <h2>Periode penggajian</h2>
                            <p>
                                Draf diperiksa sebelum slip diterbitkan ke akun
                                karyawan.
                            </p>
                        </div>
                        <Button v-if="canManage" @click="creating = true"
                            >Buat periode penggajian</Button
                        >
                    </div>
                    <div class="pay-toolbar">
                        <AppSelect
                            :model-value="run?.id || ''"
                            :options="
                                runs.map((r) => ({
                                    value: r.id,
                                    label: `${r.name} · ${r.status === 'published' ? 'Terbit' : 'Draf'}`,
                                }))
                            "
                            aria-label="Periode penggajian"
                            placeholder="Pilih periode penggajian"
                            @update:model-value="
                                (value) => openRun(Number(value))
                            "
                        />
                    </div>
                    <p v-if="!runs.length" class="pay-empty">
                        {{
                            loading
                                ? 'Memuat…'
                                : 'Belum ada periode penggajian.'
                        }}
                    </p>
                </section>
                <section v-if="run" class="panel">
                    <div class="panel-heading">
                        <div>
                            <h2>{{ run.name }}</h2>
                            <p>
                                {{ run.starts_on }} – {{ run.ends_on }} ·
                                Pembayaran {{ run.pays_on }}
                            </p>
                        </div>
                        <span
                            class="pill"
                            :class="
                                run.status === 'published' ? 'green' : 'neutral'
                            "
                            >{{
                                run.status === 'published'
                                    ? 'Sudah diterbitkan'
                                    : 'Draf'
                            }}</span
                        >
                    </div>
                    <div v-if="run.status === 'draft'" class="pay-toolbar">
                        <Button
                            v-if="canManage"
                            variant="destructive"
                            @click="deletingDraft = true"
                            >Hapus draf</Button
                        >
                        <Button
                            v-if="canManage"
                            variant="outline"
                            @click="editPolicy"
                            >Atur zona dan tarif</Button
                        ><Button
                            v-if="canManage"
                            variant="outline"
                            @click="recalculate"
                            >Hitung ulang</Button
                        ><Button v-if="canPublish" @click="publishing = true"
                            >Terbitkan slip gaji</Button
                        >
                    </div>
                    <p class="pay-note">
                        {{
                            run.status === 'draft'
                                ? 'Hitung ulang mengambil gaji, absensi dan lembur terbaru. Periksa nominal, penyesuaian, dan lembur tertunda sebelum terbit.'
                                : 'Slip dikunci sesuai perhitungan saat diterbitkan; perubahan data berikutnya tidak mengubah slip lama.'
                        }}
                    </p>
                    <div class="pay-toolbar">
                        <SearchInput
                            v-model="query"
                            placeholder="Cari karyawan atau divisi…"
                        />
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Karyawan</th>
                                    <th>Terlambat / zona</th>
                                    <th>Lembur</th>
                                    <th>Potongan</th>
                                    <th>Gaji bersih</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="item in filteredItems"
                                    :key="item.id"
                                >
                                    <td>
                                        <strong>{{ item.snapshot.name }}</strong
                                        ><small>{{
                                            item.snapshot.department
                                        }}</small
                                        ><small
                                            v-if="
                                                !item.snapshot.salary_configured
                                            "
                                            class="pay-error"
                                            >Gaji belum diisi</small
                                        >
                                    </td>
                                    <td>
                                        {{ item.snapshot.late_minutes }}
                                        menit<br /><span
                                            :class="[
                                                'pay-zone',
                                                item.snapshot.zone,
                                            ]"
                                            >{{
                                                zoneLabels[item.snapshot.zone]
                                            }}</span
                                        >
                                    </td>
                                    <td>
                                        {{ item.snapshot.overtime_minutes }}
                                        menit<small
                                            v-if="
                                                item.snapshot.pending_overtime
                                            "
                                            class="pay-error"
                                            >{{
                                                item.snapshot.pending_overtime
                                            }}
                                            menunggu keputusan</small
                                        >
                                    </td>
                                    <td>
                                        {{ money(item.snapshot.deductions) }}
                                    </td>
                                    <td>
                                        <strong
                                            :class="{
                                                'pay-error':
                                                    item.snapshot.net < 0,
                                            }"
                                            >{{
                                                money(item.snapshot.net)
                                            }}</strong
                                        >
                                    </td>
                                    <td>
                                        <div class="pay-actions">
                                            <Button
                                                variant="outline"
                                                @click="preview = item"
                                                >Rincian</Button
                                            ><Button
                                                v-if="
                                                    run.status === 'draft' &&
                                                    canManage
                                                "
                                                variant="outline"
                                                @click="editAdjust(item)"
                                                >Penyesuaian</Button
                                            >
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section></template
            ></template
        ><template v-else
            ><section class="panel">
                <div class="panel-heading">
                    <div>
                        <h2>Slip gaji</h2>
                        <p>
                            Slip yang sudah diterbitkan langsung tersedia di
                            sini sesuai akses Anda.
                        </p>
                    </div>
                </div>
                <p v-if="!slips.length" class="pay-empty">
                    {{
                        loading
                            ? 'Memuat…'
                            : 'Belum ada slip gaji yang diterbitkan.'
                    }}
                </p>
                <div class="payslip-grid">
                    <article
                        v-for="item in slips"
                        :key="item.id"
                        class="payslip-card"
                    >
                        <small>{{ item.snapshot.period.name }}</small>
                        <h3>{{ item.snapshot.name }}</h3>
                        <strong>{{ money(item.snapshot.net) }}</strong>
                        <p>
                            {{ item.snapshot.period.starts_on }} –
                            {{ item.snapshot.period.ends_on }}
                        </p>
                        <span :class="['pay-zone', item.snapshot.zone]">{{
                            zoneLabels[item.snapshot.zone]
                        }}</span
                        ><Button variant="outline" @click="preview = item"
                            >Lihat slip gaji</Button
                        >
                    </article>
                </div>
            </section></template
        >
    </div>
    <Dialog
        :open="!!profile"
        @update:open="
            (value) => {
                if (!value && !profileForm.processing) profile = null;
            }
        "
        ><DialogContent class="alfar-confirmation payroll-dialog"
            ><DialogHeader
                ><DialogTitle>Atur gaji · {{ profile?.name }}</DialogTitle
                ><DialogDescription
                    >Komponen ini digunakan saat membuat atau menghitung ulang
                    draf. Slip terbit tetap tersimpan.</DialogDescription
                ></DialogHeader
            >
            <form class="pay-form" @submit.prevent="saveProfile">
                <label
                    >Gaji pokok (Rp)<Input
                        v-model="profileForm.base_salary"
                        type="number"
                        min="0"
                        step="1"
                        required /></label
                ><PayComponents v-model="profileForm.components" /><DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        :disabled="profileForm.processing"
                        @click="profile = null"
                        >Batal</Button
                    ><Button :disabled="profileForm.processing"
                        >Simpan komponen gaji</Button
                    ></DialogFooter
                >
            </form></DialogContent
        ></Dialog
    >
    <Dialog v-model:open="creating"
        ><DialogContent class="alfar-confirmation payroll-dialog"
            ><DialogHeader
                ><DialogTitle>Buat periode penggajian</DialogTitle
                ><DialogDescription
                    >Pilih karyawan dan atur kebijakan perusahaan. Tarif kosong
                    belum berlaku; isi 0 jika memang tidak
                    diberlakukan.</DialogDescription
                ></DialogHeader
            >
            <form class="pay-form" @submit.prevent="createRun">
                <label
                    >Nama periode<Input
                        v-model="runForm.name"
                        required
                        maxlength="150"
                        placeholder="Gaji November 2026"
                /></label>
                <div class="pay-dates">
                    <label
                        >Dari tanggal<AppDateTimePicker
                            v-model="runForm.starts_on"
                            label="Dari tanggal"
                            mode="date"
                            required /></label
                    ><label
                        >Sampai tanggal<AppDateTimePicker
                            v-model="runForm.ends_on"
                            label="Sampai tanggal"
                            mode="date"
                            required /></label
                    ><label
                        >Tanggal pembayaran<AppDateTimePicker
                            v-model="runForm.pays_on"
                            label="Tanggal pembayaran"
                            mode="date"
                            required
                    /></label>
                </div>
                <label
                    >Karyawan<AppSelect
                        v-model="runForm.employee_ids"
                        multiple
                        :options="
                            employees.map((e) => ({
                                value: e.id,
                                label: e.name,
                                description: `${e.position} · ${e.department}`,
                            }))
                        "
                        aria-label="Karyawan penggajian" /></label
                ><Button
                    type="button"
                    variant="outline"
                    @click="runForm.employee_ids = employees.map((e) => e.id)"
                    >Pilih semua karyawan ({{ employees.length }})</Button
                ><PayrollPolicyFields :form="runForm" /><DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        @click="creating = false"
                        >Batal</Button
                    ><Button :disabled="runForm.processing"
                        >Buat draf penggajian</Button
                    ></DialogFooter
                >
            </form></DialogContent
        ></Dialog
    >
    <Dialog v-model:open="editingPolicy"
        ><DialogContent class="alfar-confirmation payroll-dialog"
            ><DialogHeader
                ><DialogTitle>Atur zona dan tarif</DialogTitle
                ><DialogDescription
                    >Zona menggunakan akumulasi menit setelah toleransi shift.
                    Tarif tidak ditentukan otomatis oleh
                    sistem.</DialogDescription
                ></DialogHeader
            >
            <form class="pay-form" @submit.prevent="savePolicy">
                <PayrollPolicyFields :form="policyForm" /><DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        @click="editingPolicy = false"
                        >Batal</Button
                    ><Button :disabled="policyForm.processing"
                        >Simpan dan hitung ulang</Button
                    ></DialogFooter
                >
            </form></DialogContent
        ></Dialog
    >
    <Dialog
        :open="!!adjusting"
        @update:open="
            (value) => {
                if (!value && !adjustForm.processing) adjusting = null;
            }
        "
        ><DialogContent class="alfar-confirmation payroll-dialog"
            ><DialogHeader
                ><DialogTitle
                    >Penyesuaian · {{ adjusting?.snapshot.name }}</DialogTitle
                ><DialogDescription
                    >Tambahan atau potongan khusus periode ini, terpisah dari
                    komponen gaji tetap.</DialogDescription
                ></DialogHeader
            >
            <form class="pay-form" @submit.prevent="saveAdjust">
                <PayComponents v-model="adjustForm.components" /><label
                    >Alasan penyesuaian<Input
                        v-model="adjustForm.reason"
                        required
                        maxlength="2000" /></label
                ><DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        @click="adjusting = null"
                        >Batal</Button
                    ><Button :disabled="adjustForm.processing"
                        >Simpan penyesuaian</Button
                    ></DialogFooter
                >
            </form></DialogContent
        ></Dialog
    >
    <Dialog
        :open="publishing"
        @update:open="
            (value) => {
                if (!publishingBusy) publishing = value;
            }
        "
        ><DialogContent
            class="alfar-confirmation"
            :show-close-button="!publishingBusy"
            @interact-outside="
                (event) => {
                    if (publishingBusy) event.preventDefault();
                }
            "
            ><DialogHeader
                ><DialogTitle>Terbitkan slip gaji?</DialogTitle
                ><DialogDescription
                    >Slip {{ run?.name }} akan dikunci dan langsung terlihat di
                    akun karyawan. Pastikan gaji, potongan, zona dan lembur
                    sudah diperiksa.</DialogDescription
                ></DialogHeader
            ><DialogFooter
                ><Button
                    variant="outline"
                    :disabled="publishingBusy"
                    @click="publishing = false"
                    >Batal</Button
                ><Button :disabled="publishingBusy" @click="publish">{{
                    publishingBusy ? 'Menerbitkan…' : 'Terbitkan slip'
                }}</Button></DialogFooter
            ></DialogContent
        ></Dialog
    >
    <Dialog
        :open="deletingDraft"
        @update:open="
            (value) => {
                if (!deleteBusy) deletingDraft = value;
            }
        "
        ><DialogContent
            class="alfar-confirmation"
            :show-close-button="!deleteBusy"
            ><DialogHeader
                ><DialogTitle>Hapus draf penggajian?</DialogTitle
                ><DialogDescription
                    >Draf {{ run?.name }} akan dihapus. Data gaji, absensi dan
                    lembur tidak ikut dihapus. Slip yang sudah terbit tidak
                    dapat dihapus.</DialogDescription
                ></DialogHeader
            ><DialogFooter
                ><Button
                    variant="outline"
                    :disabled="deleteBusy"
                    @click="deletingDraft = false"
                    >Batal</Button
                ><Button
                    variant="destructive"
                    :disabled="deleteBusy"
                    @click="deleteDraft"
                    >{{ deleteBusy ? 'Menghapus…' : 'Hapus draf' }}</Button
                ></DialogFooter
            ></DialogContent
        ></Dialog
    >
    <Dialog
        :open="!!preview"
        @update:open="
            (value) => {
                if (!value) preview = null;
            }
        "
        ><DialogContent class="alfar-confirmation payroll-dialog"
            ><DialogHeader
                ><DialogTitle
                    >Slip gaji ·
                    {{ preview?.snapshot.period.name }}</DialogTitle
                ><DialogDescription
                    >{{ preview?.snapshot.name }} ·
                    {{ preview?.snapshot.position }} ·
                    {{ preview?.snapshot.department }}</DialogDescription
                ></DialogHeader
            ><template v-if="preview"
                ><div class="slip-lines">
                    <p>
                        <span>Gaji pokok</span
                        ><strong>{{
                            money(preview.snapshot.base_salary)
                        }}</strong>
                    </p>
                    <p v-for="(c, i) in preview.snapshot.components" :key="i">
                        <span
                            >{{ c.name }}
                            {{
                                c.type === 'deduction' ? '(potongan)' : ''
                            }}</span
                        ><strong
                            >{{ c.type === 'deduction' ? '− ' : ''
                            }}{{ money(c.amount) }}</strong
                        >
                    </p>
                    <p>
                        <span
                            >Lembur
                            {{ preview.snapshot.overtime_minutes }} menit</span
                        ><strong>{{
                            money(preview.snapshot.overtime_pay)
                        }}</strong>
                    </p>
                    <p>
                        <span
                            >Terlambat
                            {{ preview.snapshot.late_minutes }} menit</span
                        ><strong
                            >−
                            {{ money(preview.snapshot.late_deduction) }}</strong
                        >
                    </p>
                    <p>
                        <span>Total pendapatan</span
                        ><strong>{{ money(preview.snapshot.gross) }}</strong>
                    </p>
                    <p>
                        <span>Total potongan</span
                        ><strong>{{
                            money(preview.snapshot.deductions)
                        }}</strong>
                    </p>
                    <p class="slip-net">
                        <span>Gaji bersih</span
                        ><strong>{{ money(preview.snapshot.net) }}</strong>
                    </p>
                </div>
                <p class="pay-note">
                    Pembayaran {{ preview.snapshot.period.pays_on }} ·
                    {{ zoneLabels[preview.snapshot.zone] }}. Slip bukan bukti
                    transfer bank.
                </p>
                <Button
                    v-if="mode === 'mine' || run?.status === 'published'"
                    as-child
                    variant="outline"
                    ><a
                        :href="`/payroll/slips/${preview.id}/print`"
                        target="_blank"
                        rel="noopener"
                        >Cetak / simpan PDF</a
                    ></Button
                >
                <p v-else class="pay-note">
                    Pratinjau draf. Belum terlihat oleh karyawan.
                </p></template
            ></DialogContent
        ></Dialog
    >
</template>
<style scoped>
.pay-tabs,
.pay-toolbar,
.pay-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
}
.pay-tabs {
    margin-bottom: 22px;
}
.pay-toolbar {
    padding: 0 24px 20px;
}
.pay-toolbar > * {
    min-width: 0;
}
.pay-note,
.pay-empty {
    padding: 0 24px 20px;
    font-size: 12px;
    color: #8a90a5;
    line-height: 1.8;
}
.pay-error {
    color: #bd354b !important;
}
td small {
    display: block;
    color: #8d93a8;
    margin-top: 4px;
}
.pay-zone {
    display: inline-block;
    border-radius: 7px;
    padding: 5px 9px;
    margin-top: 5px;
    font-size: 11px;
    background: #f1f1f6;
    color: #8b8c9f;
}
.pay-zone.safe {
    background: #e5f6ec;
    color: #287b52;
}
.pay-zone.warning {
    background: #fff3d8;
    color: #967222;
}
.pay-zone.red {
    background: #ffe7eb;
    color: #b3394f;
}
.payslip-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    padding: 0 24px 24px;
    gap: 18px;
}
.payslip-card {
    border: 1px solid #ebe8f6;
    border-radius: 16px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.payslip-card > strong {
    font-size: 23px;
    color: #5945c8;
}
.payslip-card p,
.payslip-card small {
    font-size: 12px;
    color: #8c91a7;
}
.pay-form {
    display: grid;
    gap: 18px;
}
.pay-form label {
    display: grid;
    gap: 7px;
    font-size: 12px;
}
.pay-dates {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}
.slip-lines p {
    display: flex;
    justify-content: space-between;
    gap: 18px;
    padding: 12px 0;
    border-bottom: 1px solid #eeedf6;
    font-size: 13px;
}
.slip-lines span {
    color: #81869d;
}
.slip-net {
    background: #f3efff;
    padding: 16px !important;
    border-radius: 12px;
}
.slip-net strong {
    color: #5945c8;
}
.payroll-dialog {
    max-width: 760px;
}
.payroll-dialog .pay-note {
    padding: 0;
}
@media (max-width: 600px) {
    .pay-dates {
        grid-template-columns: 1fr;
    }
    .pay-toolbar {
        padding: 0 16px 18px;
    }
    .payslip-grid {
        padding: 0 16px 18px;
        grid-template-columns: 1fr;
    }
    .slip-lines p {
        flex-wrap: wrap;
    }
}
</style>
