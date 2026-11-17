<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { BrowserQRCodeReader, type IScannerControls } from '@zxing/browser';
import {
    Activity,
    ArrowDownToLine,
    ArrowRight,
    Bell,
    Building2,
    CalendarDays,
    ChartNoAxesCombined,
    Check,
    ChevronDown,
    ClipboardCheck,
    Clock3,
    Fingerprint,
    LayoutDashboard,
    LogOut,
    Plus,
    QrCode,
    Search,
    Settings2,
    ShieldCheck,
    Target,
    TrendingUp,
    Users,
    X,
} from '@lucide/vue';
import QRCode from 'qrcode';
import { computed, onBeforeUnmount, ref } from 'vue';
import DataForm, { type Field } from '@/components/alfar/DataForm.vue';

interface Row {
    id: number;
    name: string;
    [key: string]: unknown;
}
interface Employee {
    id: number;
    user_id: number;
    name: string;
    nik: string;
    department: string;
    department_id: number;
    position: string;
    position_id: number;
    supervisor_id: number | null;
    allow_wfh: boolean;
    allow_wfo: boolean;
}
interface Period {
    id: number;
    name: string;
    starts_on: string;
    ends_on: string;
    status: string;
}
interface Submission {
    id: number;
    actual: number;
    note: string;
    status: string;
    evidence_path: string | null;
}
interface Kpi {
    id: number;
    employee_id: number | null;
    department_id: number | null;
    period_id: number;
    metric_id: number;
    weight: number;
    target: number;
    definition: {
        name: string;
        unit: string;
        level: string;
        source: string;
        direction: string;
    };
    submission: Submission | null;
    score?: number | null;
    complete?: boolean;
}
interface Review {
    id: number;
    analysis: string;
    follow_up: string;
    due_on: string;
    status: string;
    revision: number;
    response: string | null;
}
interface Report {
    employee: Employee;
    data: {
        targets: Kpi[];
        score: number | null;
        complete: boolean;
        weight: number;
        attendance: Record<string, number>;
        shift_ids?: number[];
    };
    review: Review | null;
    previous_score: number | null;
    can_review: boolean;
}
interface Punch {
    id: number;
    employee_id: number;
    work_date: string;
    clock_in: string | null;
    clock_out: string | null;
    mode: string;
    source: string;
    status: string;
    late_minutes: number;
    early_minutes: number;
}
interface ChangeRequest {
    id: number;
    employee_id: number;
    work_date: string;
    type: string;
    reason: string;
    status: string;
    decision_note: string | null;
}
interface Grant {
    permission: string;
    scope: string;
}
interface Role {
    id: number;
    name: string;
    grants: Grant[];
}
const props = defineProps<{
    permissions: string[];
    me: Employee | null;
    employees: Employee[];
    periods: Period[];
    periodId: number;
    reports: Report[];
    targets: Kpi[];
    departments: Row[];
    positions: Row[];
    shifts: Row[];
    metrics: Row[];
    accounts: Row[];
    roles: Role[];
    roleAssignments: { user_id: number; role_id: number }[];
    permissionCatalog: string[];
    scopeCatalog: string[];
    attendances: Punch[];
    schedules: Row[];
    requests: ChangeRequest[];
    devices: Row[];
    deviceMappings: Row[];
    audits: Row[];
    placements: Row[];
    success?: string;
}>();
const page = usePage();
const tab = ref('overview');
const query = ref('');
const department = ref('');
const shiftFilter = ref('');
const filter = ref('all');
const selectedReport = ref<number | null>(null);
const modal = ref<{
    title: string;
    url: string;
    fields: Field[];
    submit?: string;
} | null>(null);
const has = (permission: string) => props.permissions.includes(permission);
const tabs = computed(() =>
    [
        {
            id: 'overview',
            label: 'Ringkasan',
            icon: LayoutDashboard,
            show: true,
        },
        {
            id: 'employees',
            label: 'Karyawan',
            icon: Users,
            show: props.employees.length > 0 || has('organization.manage'),
        },
        {
            id: 'attendance',
            label: 'Absensi & shift',
            icon: CalendarDays,
            show: true,
        },
        {
            id: 'kpi',
            label: 'Target & realisasi',
            icon: Target,
            show: props.targets.length > 0 || has('kpi.manage'),
        },
        {
            id: 'reviews',
            label: 'Review kinerja',
            icon: ClipboardCheck,
            show: props.reports.length > 0,
        },
        {
            id: 'reports',
            label: 'Laporan & analisis',
            icon: ChartNoAxesCombined,
            show: has('reports.read'),
        },
        {
            id: 'organization',
            label: 'Organisasi',
            icon: Building2,
            show: has('organization.manage') || has('kpi.manage'),
        },
        {
            id: 'access',
            label: 'Akun & hak akses',
            icon: ShieldCheck,
            show: has('accounts.manage') || has('roles.manage'),
        },
        {
            id: 'devices',
            label: 'Perangkat',
            icon: Fingerprint,
            show: has('devices.manage'),
        },
        {
            id: 'audit',
            label: 'Log audit',
            icon: Activity,
            show: props.audits.length > 0,
        },
    ].filter((t) => t.show),
);
const period = computed(() =>
    props.periods.find((p) => p.id === props.periodId),
);
const title = computed(
    () => tabs.value.find((t) => t.id === tab.value)?.label || 'Ringkasan',
);
const employeeName = (id: number) =>
    props.employees.find((e) => e.id === id)?.name || `Karyawan #${id}`;
const names = (rows: { id: number; name: string }[]) =>
    rows.map((r) => ({ value: r.id, label: r.name }));
const employeeOptions = computed(() => names(props.employees));
const filteredEmployees = computed(() =>
    props.employees.filter(
        (e) =>
            `${e.name} ${e.nik} ${e.position}`
                .toLowerCase()
                .includes(query.value.toLowerCase()) &&
            (!department.value || e.department === department.value),
    ),
);
const filteredReports = computed(() =>
    props.reports.filter(
        (r) =>
            `${r.employee.name} ${r.employee.nik}`
                .toLowerCase()
                .includes(query.value.toLowerCase()) &&
            (!department.value || r.employee.department === department.value) &&
            (!shiftFilter.value ||
                (r.data.shift_ids || []).includes(Number(shiftFilter.value))) &&
            (filter.value === 'all' ||
                (filter.value === 'incomplete'
                    ? !r.data.complete
                    : filter.value === 'missed'
                      ? r.data.targets.some(
                            (t) =>
                                t.score !== null &&
                                t.score !== undefined &&
                                t.score < 100,
                        )
                      : r.review?.status !== 'published')),
    ),
);
const reportSummary = computed(() => {
    const scored = filteredReports.value.filter((r) => r.data.score !== null);
    return {
        count: filteredReports.value.length,
        average: scored.length
            ? scored.reduce((sum, r) => sum + Number(r.data.score), 0) /
              scored.length
            : null,
        present: filteredReports.value.reduce(
            (sum, r) => sum + r.data.attendance.present,
            0,
        ),
        scheduled: filteredReports.value.reduce(
            (sum, r) => sum + r.data.attendance.scheduled,
            0,
        ),
        published: filteredReports.value.filter(
            (r) => r.review?.status === 'published',
        ).length,
    };
});
const currentReport = computed(() =>
    props.reports.find((r) => r.employee.id === selectedReport.value),
);
const pending = computed(
    () => props.reports.filter((r) => r.review?.status !== 'published').length,
);
const complete = computed(
    () => props.reports.filter((r) => r.review?.status === 'published').length,
);
const average = computed(() => {
    const scores = props.reports.filter((r) => r.data.score !== null);
    return scores.length
        ? (
              scores.reduce((sum, r) => sum + Number(r.data.score), 0) /
              scores.length
          ).toFixed(1)
        : '—';
});
const initials = (name: string) =>
    name
        .split(' ')
        .slice(0, 2)
        .map((s) => s[0])
        .join('')
        .toUpperCase();
const date = (value: string | null | undefined) =>
    value
        ? new Intl.DateTimeFormat('id-ID', {
              day: 'numeric',
              month: 'short',
          }).format(new Date(value))
        : '—';
const time = (value: string | null) => (value ? value.slice(11, 16) : '—');
const number = (value: number | null | undefined) =>
    value === null || value === undefined
        ? '—'
        : Number(value).toLocaleString('id-ID', { maximumFractionDigits: 2 });
const statusText = (s: string) =>
    ({
        planning: 'Perencanaan',
        active: 'Berjalan',
        review: 'Review',
        closed: 'Ditutup',
        pending: 'Menunggu',
        validated: 'Tervalidasi',
        rejected: 'Ditolak',
        approved: 'Disetujui',
        published: 'Diterbitkan',
        draft: 'Draft',
        present: 'Hadir',
        leave: 'Cuti',
        sick: 'Sakit',
        permission: 'Izin',
    })[s] || s;
function openForm(
    title: string,
    entity: string,
    fields: Field[],
    submit?: string,
) {
    modal.value = { title, url: `/manage/${entity}`, fields, submit };
}
function changePeriod(e: Event) {
    router.get(
        '/dashboard',
        { period: (e.target as HTMLSelectElement).value },
        { preserveState: true },
    );
}
function field(
    key: string,
    label: string,
    type = 'text',
    required = true,
    options?: Field['options'],
    value?: Field['value'],
): Field {
    return { key, label, type, required, options, value };
}
const periodField = () =>
    field(
        'period_id',
        'Periode',
        'select',
        true,
        names(props.periods),
        props.periodId || '',
    );
function editEmployee(e?: Employee) {
    openForm(e ? 'Ubah penempatan & akses' : 'Tambah karyawan', 'employees', [
        ...(e
            ? [field('id', 'ID karyawan', 'number', true, undefined, e.id)]
            : []),
        field(
            'user_id',
            'Akun',
            'select',
            true,
            names(props.accounts),
            e?.user_id,
        ),
        field('nik', 'NIK', 'text', true, undefined, e?.nik),
        field(
            'position_id',
            'Jabatan',
            'select',
            true,
            names(props.positions),
            e?.position_id,
        ),
        field(
            'supervisor_id',
            'Supervisor',
            'select',
            false,
            employeeOptions.value,
            e?.supervisor_id,
        ),
        field('starts_on', 'Mulai penempatan', 'date'),
        field(
            'allow_wfo',
            'Boleh WFO',
            'checkbox',
            false,
            undefined,
            e?.allow_wfo ?? true,
        ),
        field(
            'allow_wfh',
            'Boleh WFH tanpa pengajuan',
            'checkbox',
            false,
            undefined,
            e?.allow_wfh ?? false,
        ),
    ]);
}
function schedule() {
    openForm('Tetapkan jadwal kerja', 'schedules', [
        field('employee_id', 'Karyawan', 'select', true, employeeOptions.value),
        field('shift_id', 'Shift', 'select', true, names(props.shifts)),
        field('work_date', 'Tanggal kerja', 'date'),
    ]);
}
function setTarget() {
    openForm('Tetapkan target', 'targets', [
        periodField(),
        field(
            'metric_id',
            'Template KPI',
            'select',
            true,
            names(props.metrics),
        ),
        field(
            'employee_id',
            'Karyawan — KPI individu',
            'select',
            false,
            employeeOptions.value,
        ),
        field(
            'department_id',
            'Divisi — KPI tim',
            'select',
            false,
            names(props.departments),
        ),
        field(
            'shift_id',
            'Shift — opsional untuk tim',
            'select',
            false,
            names(props.shifts),
        ),
        field('target', 'Target (> 0)', 'number'),
        field('weight', 'Bobot (%)', 'number'),
    ]);
}
function submitKpi(k: Kpi) {
    modal.value = {
        title: `Realisasi · ${k.definition.name}`,
        url: `/targets/${k.id}/submit`,
        fields: [
            field('actual', `Realisasi (${k.definition.unit})`, 'number'),
            field('note', 'Catatan / penyebab selisih', 'textarea'),
            field('evidence', 'Bukti realisasi', 'file'),
        ],
    };
}
function validateKpi(k: Kpi) {
    modal.value = {
        title: 'Validasi realisasi',
        url: `/targets/${k.id}/validate`,
        fields: [
            field('status', 'Keputusan', 'select', true, [
                { value: 'validated', label: 'Validasi' },
                { value: 'rejected', label: 'Kembalikan untuk perbaikan' },
            ]),
            field('reason', 'Catatan validasi', 'textarea'),
        ],
    };
}
function correctKpi(k: Kpi) {
    modal.value = {
        title: 'Koreksi realisasi tervalidasi',
        url: `/targets/${k.id}/correct`,
        fields: [
            field('actual', 'Realisasi yang benar', 'number'),
            field('note', 'Catatan realisasi', 'textarea'),
            field(
                'reason',
                'Alasan koreksi — tersimpan dalam audit',
                'textarea',
            ),
            field('evidence', 'Bukti koreksi', 'file'),
        ],
    };
}
function reviewForm(r: Report) {
    modal.value = {
        title:
            r.review?.status === 'published'
                ? 'Revisi laporan terbit'
                : 'Review kinerja',
        url: '/reviews',
        fields: [
            field(
                'employee_id',
                'Karyawan',
                'select',
                true,
                employeeOptions.value,
                r.employee.id,
            ),
            periodField(),
            field(
                'analysis',
                'Analisis & penyebab',
                'textarea',
                true,
                undefined,
                r.review?.analysis,
            ),
            field(
                'follow_up',
                'Tindak lanjut yang disepakati',
                'textarea',
                true,
                undefined,
                r.review?.follow_up,
            ),
            field(
                'due_on',
                'Tinjau tindak lanjut pada',
                'date',
                true,
                undefined,
                r.review?.due_on,
            ),
            field(
                'publish',
                'Terbitkan laporan',
                'checkbox',
                false,
                undefined,
                r.review?.status === 'published',
            ),
            field(
                'reason',
                'Alasan revisi — wajib untuk laporan terbit',
                'textarea',
                false,
            ),
        ],
    };
}
function requestChange() {
    modal.value = {
        title: 'Ajukan WFH / koreksi / izin',
        url: '/attendance/requests',
        fields: [
            field('type', 'Jenis pengajuan', 'select', true, [
                { value: 'wfh', label: 'WFH' },
                { value: 'correction', label: 'Koreksi absensi' },
                { value: 'leave', label: 'Cuti' },
                { value: 'sick', label: 'Sakit' },
                { value: 'permission', label: 'Izin' },
            ]),
            field('work_date', 'Tanggal kerja', 'date'),
            field('reason', 'Alasan', 'textarea'),
            {
                ...field('clock_in', 'Koreksi waktu masuk', 'text', false),
                hint: 'YYYY-MM-DD HH:mm:ss; hanya untuk koreksi',
            },
            {
                ...field('clock_out', 'Koreksi waktu keluar', 'text', false),
                hint: 'YYYY-MM-DD HH:mm:ss; hanya untuk koreksi',
            },
        ],
    };
}
function decide(r: ChangeRequest) {
    modal.value = {
        title: 'Putuskan pengajuan',
        url: `/attendance/requests/${r.id}/decide`,
        fields: [
            field('status', 'Keputusan', 'select', true, [
                { value: 'approved', label: 'Setujui' },
                { value: 'rejected', label: 'Tolak' },
            ]),
            field('decision_note', 'Catatan keputusan', 'textarea'),
        ],
    };
}
function importExcel() {
    modal.value = {
        title: 'Impor absensi Excel (.xlsx)',
        url: '/attendance/import',
        fields: [
            {
                ...field('file', 'Berkas Excel', 'file'),
                hint: 'Header: nik, work_date, clock_in, clock_out, mode. Tanggal YYYY-MM-DD; waktu YYYY-MM-DD HH:mm:ss. Maksimal 2000 baris. Semua sel berupa teks; mode wfo/wfh.',
            },
        ],
    };
}
function changeStatus() {
    openForm('Lanjutkan tahap periode', 'period-status', [
        periodField(),
        field('status', 'Tahap berikutnya', 'select', true, [
            { value: 'active', label: 'Berjalan — kunci target' },
            { value: 'review', label: 'Review' },
            { value: 'closed', label: 'Tutup periode' },
        ]),
    ]);
}
function editMaster(entity: string, row: Row) {
    const specs: Record<string, string[][]> = {
        departments: [['name', 'Nama divisi']],
        positions: [
            ['name', 'Nama jabatan'],
            ['department_id', 'Divisi', 'select'],
        ],
        shifts: [
            ['name', 'Nama shift'],
            ['starts_at', 'Jam masuk', 'time'],
            ['ends_at', 'Jam keluar', 'time'],
            ['tolerance_minutes', 'Toleransi (menit)', 'number'],
        ],
        periods: [
            ['name', 'Nama periode'],
            ['starts_on', 'Tanggal mulai', 'date'],
            ['ends_on', 'Tanggal akhir', 'date'],
        ],
        metrics: [
            ['position_id', 'Jabatan', 'select'],
            ['name', 'Nama indikator'],
            ['unit', 'Satuan'],
            ['direction', 'Arah penilaian', 'select'],
            ['level', 'Tingkat', 'select'],
            ['source', 'Sumber data'],
            ['score_cap', 'Batas nilai (%)', 'number'],
        ],
        accounts: [
            ['name', 'Nama lengkap'],
            ['email', 'Email @alfar.id', 'email'],
            ['active', 'Akun aktif', 'checkbox'],
            ['password', 'Reset password sementara (opsional)', 'password'],
            [
                'password_confirmation',
                'Ulangi password (jika direset)',
                'password',
            ],
        ],
        devices: [
            ['name', 'Nama perangkat'],
            ['active', 'Perangkat aktif', 'checkbox'],
            ['token', 'Ganti token (opsional)', 'password'],
        ],
    };
    const options: Record<string, Field['options']> = {
        department_id: names(props.departments),
        position_id: names(props.positions),
        direction: [
            { value: 'higher', label: 'Semakin tinggi semakin baik' },
            { value: 'lower', label: 'Semakin rendah semakin baik' },
        ],
        level: [
            { value: 'individual', label: 'Individu' },
            { value: 'team', label: 'Tim' },
        ],
    };
    const fields = specs[entity].map(([key, label, type]) => {
        const raw = row[key];
        let value: Field['value'] =
            typeof raw === 'boolean' ||
            typeof raw === 'string' ||
            typeof raw === 'number'
                ? raw
                : '';
        if (type === 'time') value = String(value).slice(0, 5);
        if (type === 'checkbox') value = Boolean(raw);
        return field(
            key,
            label,
            type || 'text',
            !['password', 'password_confirmation', 'token', 'active'].includes(
                key,
            ),
            options[key],
            value,
        );
    });
    openForm(`Ubah ${row.name}`, entity, [
        field('id', 'ID', 'number', true, undefined, row.id),
        ...fields,
    ]);
}
function editTarget(k: Kpi) {
    setTarget();
    if (modal.value)
        modal.value = {
            ...modal.value,
            title: `Ubah target · ${k.definition.name}`,
            fields: [
                field('id', 'ID target', 'number', true, undefined, k.id),
                ...modal.value.fields.map((f) => ({
                    ...f,
                    value: (
                        {
                            period_id: k.period_id,
                            metric_id: k.metric_id,
                            employee_id: k.employee_id,
                            department_id: k.department_id,
                            shift_id:
                                (k as Kpi & { shift_id?: number | null })
                                    .shift_id ?? null,
                            target: k.target,
                            weight: k.weight,
                        } as Record<string, Field['value']>
                    )[f.key],
                })),
            ],
        };
}
const roleForm = useForm<{ id: number | null; name: string; grants: Grant[] }>({
    id: null,
    name: '',
    grants: [],
});
const roleEditor = ref(false);
function editRole(role?: Role) {
    roleForm.id = role?.id ?? null;
    roleForm.name = role?.name ?? '';
    roleForm.grants = role?.grants.map((g) => ({ ...g })) ?? [];
    roleEditor.value = true;
}
function toggleGrant(permission: string, checked: boolean) {
    roleForm.grants = roleForm.grants.filter(
        (g) => g.permission !== permission,
    );
    if (checked) roleForm.grants.push({ permission, scope: 'self' });
}
const assignUser = ref('');
const assignedRoles = ref<number[]>([]);
function loadRoles() {
    assignedRoles.value = props.roleAssignments
        .filter((a) => a.user_id === Number(assignUser.value))
        .map((a) => a.role_id);
}
function assignRoles() {
    router.post(
        '/manage/role-users',
        { user_id: Number(assignUser.value), role_ids: assignedRoles.value },
        { preserveScroll: true },
    );
}
const clock = useForm({
    event: 'in',
    mode: 'wfo',
    token: new URLSearchParams(window.location.search).get('scan') || '',
    work_date: '',
});
if (clock.token) tab.value = 'attendance';
const qrImage = ref('');
const qrError = ref('');
const qrRunning = ref(false);
const countdown = ref(15);
let qrTimer: ReturnType<typeof setInterval> | undefined;
let expiry = 0;
async function refreshQr() {
    const csrf = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.split('=')
            .slice(1)
            .join('=') || '',
    );
    try {
        const response = await fetch('/attendance/qr', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf },
            credentials: 'same-origin',
        });
        if (!response.ok)
            throw new Error(
                'QR tidak dapat diterbitkan. Periksa akses atau muat ulang halaman.',
            );
        const data = await response.json();
        if (!qrRunning.value) return;
        qrImage.value = await QRCode.toDataURL(data.url, {
            width: 280,
            margin: 2,
            color: { dark: '#242343', light: '#ffffff' },
        });
        expiry = new Date(data.expires_at).getTime();
        qrError.value = '';
    } catch (e) {
        qrError.value = e instanceof Error ? e.message : 'Terjadi kesalahan.';
        stopQr();
    }
}
function startQr() {
    qrRunning.value = true;
    void refreshQr();
    qrTimer = setInterval(() => {
        countdown.value = Math.max(0, Math.ceil((expiry - Date.now()) / 1000));
        if (expiry && countdown.value === 0) {
            expiry = 0;
            void refreshQr();
        }
    }, 250);
}
function stopQr() {
    qrRunning.value = false;
    qrImage.value = '';
    if (qrTimer) clearInterval(qrTimer);
}
const video = ref<HTMLVideoElement | null>(null);
const cameraError = ref('');
const cameraRunning = ref(false);
let cameraControls: IScannerControls | undefined;
async function scanQr() {
    cameraError.value = '';
    cameraRunning.value = true;
    try {
        cameraControls = await new BrowserQRCodeReader().decodeFromVideoDevice(
            undefined,
            video.value!,
            (result) => {
                if (result) {
                    try {
                        const u = new URL(result.getText());
                        if (u.origin !== window.location.origin)
                            throw new Error();
                        const token = u.searchParams.get('scan');
                        if (!token || token.length !== 64) throw new Error();
                        clock.token = token;
                        clock.mode = 'wfo';
                        cameraControls?.stop();
                        cameraRunning.value = false;
                    } catch {
                        cameraError.value = 'QR bukan milik aplikasi ini.';
                    }
                }
            },
        );
        if (!cameraRunning.value) cameraControls.stop();
    } catch {
        cameraError.value =
            'Kamera tidak tersedia. Gunakan HTTPS dan izinkan kamera, atau buka QR melalui kamera ponsel.';
        cameraRunning.value = false;
    }
}
onBeforeUnmount(() => {
    stopQr();
    cameraControls?.stop();
});
</script>

<template>
    <Head :title="title" />
    <div class="alfar-app">
        <aside class="alfar-sidebar">
            <Link href="/dashboard" class="alfar-brand"
                ><span class="brand-icon">a<span>•</span></span
                ><span
                    >alfar<span class="brand-sub"
                        >PEOPLE & PERFORMANCE</span
                    ></span
                ></Link
            >
            <div class="workspace-label">WORKSPACE <span>ALFAR</span></div>
            <p class="nav-caption">MENU UTAMA</p>
            <nav>
                <button
                    v-for="item in tabs"
                    :key="item.id"
                    :class="{ active: tab === item.id }"
                    @click="
                        tab = item.id;
                        query = '';
                        selectedReport = null;
                    "
                >
                    <component :is="item.icon" :size="19" />{{ item.label
                    }}<span
                        v-if="item.id === 'reviews' && pending"
                        class="nav-count"
                        >{{ pending }}</span
                    >
                </button>
            </nav>
            <div class="sidebar-bottom">
                <div class="help-card">
                    <span class="mini-logo">✦</span
                    ><strong>Tumbuh bersama Alfar</strong>
                    <p>Target yang jelas.<br />Kontribusi yang berarti.</p>
                </div>
                <Link href="/password/change"
                    ><Settings2 :size="17" /> Keamanan akun</Link
                ><Link href="/logout" method="post" as="button"
                    ><LogOut :size="17" /> Keluar</Link
                >
            </div>
        </aside>
        <div class="alfar-main">
            <header class="alfar-header">
                <div class="breadcrumb">
                    Workspace <span>/</span> <strong>{{ title }}</strong>
                </div>
                <div class="header-right">
                    <span class="live-dot"></span
                    ><span class="header-date">{{
                        new Intl.DateTimeFormat('id-ID', {
                            day: 'numeric',
                            month: 'long',
                            year: 'numeric',
                        }).format(new Date())
                    }}</span
                    ><button
                        class="icon-button"
                        aria-label="Lihat pengajuan"
                        @click="tab = 'attendance'"
                    >
                        <Bell :size="19" /><i
                            v-if="requests.some((r) => r.status === 'pending')"
                        />
                    </button>
                    <div class="user-avatar">
                        {{
                            initials(
                                String(page.props.auth?.user?.name || 'Alfar'),
                            )
                        }}
                    </div>
                    <div class="header-user">
                        <strong>{{ page.props.auth?.user?.name }}</strong
                        ><small>{{ me?.position || 'Administrator' }}</small>
                    </div>
                </div>
            </header>
            <main class="alfar-content">
                <div v-if="success" class="success-banner">
                    <Check :size="18" />{{ success }}
                </div>
                <div class="page-heading">
                    <div>
                        <div class="eyebrow">
                            ALFAR PEOPLE / {{ title.toUpperCase() }}
                        </div>
                        <h1>
                            {{
                                tab === 'overview'
                                    ? 'Setiap kontribusi, berarti.'
                                    : title
                            }}<span
                                v-if="tab === 'overview'"
                                class="greeting-dot"
                                >✦</span
                            >
                        </h1>
                        <p>
                            {{
                                tab === 'overview'
                                    ? 'Lihat perkembangan tim, temukan fokus, dan ambil langkah berikutnya.'
                                    : 'Kelola data yang terhubung, dengan akses sesuai tanggung jawab.'
                            }}
                        </p>
                    </div>
                    <div class="heading-actions">
                        <select
                            :value="periodId"
                            aria-label="Periode laporan"
                            @change="changePeriod"
                        >
                            <option v-if="!periods.length" value="0">
                                Belum ada periode
                            </option>
                            <option
                                v-for="p in periods"
                                :key="p.id"
                                :value="p.id"
                            >
                                {{ p.name }}
                            </option></select
                        ><button
                            v-if="
                                has('organization.manage') &&
                                tab === 'employees'
                            "
                            class="primary"
                            @click="editEmployee()"
                        >
                            <Plus :size="17" />Tambah karyawan</button
                        ><button
                            v-if="
                                has('attendance.manage') && tab === 'attendance'
                            "
                            class="primary"
                            @click="importExcel"
                        >
                            <ArrowDownToLine :size="17" />Impor Excel</button
                        ><button
                            v-if="has('kpi.manage') && tab === 'kpi'"
                            class="primary"
                            @click="setTarget"
                        >
                            <Plus :size="17" />Tetapkan target
                        </button>
                    </div>
                </div>

                <template v-if="tab === 'overview'">
                    <section class="overview-top">
                        <div class="welcome-card">
                            <span class="pill purple-light"
                                >PERFORMANCE WORKSPACE</span
                            >
                            <h2>
                                Tim yang hebat dimulai<br />dari arah yang
                                jelas.
                            </h2>
                            <p>
                                Satu tempat untuk kinerja, kehadiran,<br />dan
                                percakapan yang membawa perubahan.
                            </p>
                            <button @click="tab = 'reviews'">
                                Tinjau kinerja tim <ArrowRight :size="17" />
                            </button>
                            <div class="hero-art" aria-hidden="true">
                                <div class="art-orbit"></div>
                                <div class="art-chart">
                                    <span></span><span></span><span></span
                                    ><span></span><span></span>
                                </div>
                                <span class="art-star">✦</span>
                                <div class="art-badge">
                                    <TrendingUp :size="20" /> GROW TOGETHER
                                </div>
                            </div>
                        </div>
                        <div class="period-card">
                            <div class="section-label">
                                <CalendarDays :size="18" />PERIODE AKTIF
                            </div>
                            <h3>
                                {{ period?.name || 'Mulai periode pertama' }}
                            </h3>
                            <p>
                                {{
                                    period
                                        ? `${date(period.starts_on)} — ${date(period.ends_on)}`
                                        : 'Tetapkan periode untuk mulai memantau kinerja.'
                                }}
                            </p>
                            <span
                                class="pill"
                                :class="
                                    period?.status === 'active'
                                        ? 'green'
                                        : 'neutral'
                                "
                                >{{
                                    period
                                        ? statusText(period.status)
                                        : 'Belum ditetapkan'
                                }}</span
                            >
                            <div class="period-progress">
                                <span>Review diterbitkan</span
                                ><strong
                                    >{{ complete }} /
                                    {{ reports.length }}</strong
                                >
                            </div>
                            <div class="progress-track">
                                <span
                                    :style="{
                                        width: `${reports.length ? (complete / reports.length) * 100 : 0}%`,
                                    }"
                                ></span>
                            </div>
                            <small
                                >Bangun kebiasaan evaluasi setiap bulan.</small
                            >
                        </div>
                    </section>
                    <section class="stats-grid">
                        <article>
                            <span class="stat-icon lavender"
                                ><Users :size="21"
                            /></span>
                            <p>Karyawan dalam cakupan</p>
                            <strong
                                >{{ employees.length
                                }}<small>orang</small></strong
                            ><span class="stat-foot"
                                >Sesuai hak akses Anda</span
                            >
                        </article>
                        <article>
                            <span class="stat-icon blue"
                                ><ChartNoAxesCombined :size="21"
                            /></span>
                            <p>Rata-rata kinerja individu</p>
                            <strong
                                >{{ average
                                }}<small v-if="average !== '—'"
                                    >%</small
                                ></strong
                            ><span class="stat-foot"
                                >Dari KPI yang lengkap & tervalidasi</span
                            >
                        </article>
                        <article>
                            <span class="stat-icon amber"
                                ><Clock3 :size="21"
                            /></span>
                            <p>Perlu ditinjau</p>
                            <strong>{{ pending }}<small>laporan</small></strong
                            ><span class="stat-foot"
                                >Review belum diterbitkan</span
                            >
                        </article>
                        <article>
                            <span class="stat-icon mint"
                                ><ClipboardCheck :size="21"
                            /></span>
                            <p>Review diterbitkan</p>
                            <strong>{{ complete }}<small>laporan</small></strong
                            ><span class="stat-foot">Siap dibaca karyawan</span>
                        </article>
                    </section>
                    <div class="dashboard-columns">
                        <section class="panel">
                            <div class="panel-heading">
                                <div>
                                    <h2>Fokus perhatian</h2>
                                    <p>
                                        Temukan hal yang perlu ditindaklanjuti.
                                    </p>
                                </div>
                                <button
                                    class="text-button"
                                    @click="tab = 'reports'"
                                >
                                    Lihat laporan <ArrowRight :size="15" />
                                </button>
                            </div>
                            <div v-if="!reports.length" class="empty-state">
                                <Target :size="34" />
                                <h3>Siap untuk langkah pertama</h3>
                                <p>
                                    Lengkapi organisasi, jadwal, dan target
                                    KPI.<br />Ringkasan akan muncul dari data
                                    yang tercatat.
                                </p>
                                <button
                                    v-if="has('organization.manage')"
                                    class="primary"
                                    @click="tab = 'organization'"
                                >
                                    Atur organisasi <ArrowRight :size="16" />
                                </button>
                            </div>
                            <div
                                v-for="r in reports
                                    .filter(
                                        (r) =>
                                            !r.data.complete ||
                                            r.review?.status !== 'published',
                                    )
                                    .slice(0, 4)"
                                :key="r.employee.id"
                                class="focus-row"
                            >
                                <div class="person-avatar">
                                    {{ initials(r.employee.name) }}
                                </div>
                                <div>
                                    <strong>{{ r.employee.name }}</strong
                                    ><small
                                        >{{ r.employee.department }} ·
                                        {{
                                            !r.data.complete
                                                ? 'Data KPI belum lengkap'
                                                : 'Menunggu review'
                                        }}</small
                                    >
                                </div>
                                <button
                                    class="round-button"
                                    aria-label="Buka laporan"
                                    @click="
                                        selectedReport = r.employee.id;
                                        tab = 'reports';
                                    "
                                >
                                    <ArrowRight :size="17" />
                                </button>
                            </div>
                        </section>
                        <section class="panel workflow-panel">
                            <div class="panel-heading">
                                <div>
                                    <h2>Siklus kinerja</h2>
                                    <p>Dari rencana sampai tindak lanjut.</p>
                                </div>
                                <span class="pill purple-light">BULANAN</span>
                            </div>
                            <div
                                v-for="(step, i) in [
                                    {
                                        title: 'Tetapkan arah',
                                        desc: 'Target, bobot, dan sumber data disepakati.',
                                    },
                                    {
                                        title: 'Catat kontribusi',
                                        desc: 'Realisasi dan kehadiran tercatat dengan bukti.',
                                    },
                                    {
                                        title: 'Review bersama',
                                        desc: 'Validasi hasil, pahami penyebab selisih.',
                                    },
                                    {
                                        title: 'Lanjutkan perbaikan',
                                        desc: 'Terbitkan laporan dan sepakati aksi berikutnya.',
                                    },
                                ]"
                                :key="step.title"
                                class="workflow-step"
                            >
                                <span>{{
                                    String(i + 1).padStart(2, '0')
                                }}</span>
                                <div>
                                    <strong>{{ step.title }}</strong>
                                    <p>{{ step.desc }}</p>
                                </div>
                            </div>
                        </section>
                    </div>
                </template>

                <template v-if="tab === 'employees'">
                    <div class="toolbar">
                        <div class="search-input">
                            <Search :size="17" /><input
                                v-model="query"
                                placeholder="Cari nama, NIK, atau jabatan…"
                            />
                        </div>
                        <select v-model="department">
                            <option value="">Semua divisi</option>
                            <option
                                v-for="d in [
                                    ...new Set(
                                        employees.map((e) => e.department),
                                    ),
                                ]"
                                :key="d"
                            >
                                {{ d }}
                            </option></select
                        ><span class="muted"
                            >{{ filteredEmployees.length }} karyawan</span
                        >
                    </div>
                    <div class="employee-grid">
                        <article
                            v-for="(e, i) in filteredEmployees"
                            :key="e.id"
                            class="employee-card"
                        >
                            <div class="employee-card-top">
                                <span class="pill neutral">{{ e.nik }}</span
                                ><span class="employee-dot" />
                            </div>
                            <div
                                class="large-avatar"
                                :class="['avatar-' + (i % 4)]"
                            >
                                {{ initials(e.name) }}
                            </div>
                            <h3>{{ e.name }}</h3>
                            <p>{{ e.position }}</p>
                            <span class="department-tag">{{
                                e.department
                            }}</span>
                            <div class="employee-meta">
                                <span
                                    ><span class="live-dot" />{{
                                        e.allow_wfo ? 'WFO' : ''
                                    }}{{
                                        e.allow_wfo && e.allow_wfh ? ' + ' : ''
                                    }}{{ e.allow_wfh ? 'WFH' : '' }}</span
                                ><small>{{
                                    e.supervisor_id
                                        ? employeeName(e.supervisor_id)
                                        : 'Supervisor belum ditetapkan'
                                }}</small>
                            </div>
                            <button
                                v-if="has('organization.manage')"
                                class="card-action"
                                @click="editEmployee(e)"
                            >
                                Ubah penempatan
                                <ArrowRight :size="15" /></button
                            ><button
                                v-else-if="
                                    reports.some((r) => r.employee.id === e.id)
                                "
                                class="card-action"
                                @click="
                                    selectedReport = e.id;
                                    tab = 'reports';
                                "
                            >
                                Lihat kinerja <ArrowRight :size="15" />
                            </button>
                        </article>
                    </div>
                    <div
                        v-if="!filteredEmployees.length"
                        class="empty-state panel"
                    >
                        <Users :size="34" />
                        <h3>Belum ada karyawan</h3>
                        <p>
                            Buat akun internal, lalu hubungkan ke divisi dan
                            jabatan.
                        </p>
                    </div>
                </template>

                <template v-if="tab === 'attendance'">
                    <section class="panel attendance-actions">
                        <div>
                            <h2>Hari kerja Anda</h2>
                            <p>
                                WFO melalui QR kantor. WFH sesuai akses atau
                                persetujuan.
                            </p>
                        </div>
                        <button
                            v-if="me"
                            class="secondary"
                            @click="requestChange"
                        >
                            <Plus :size="16" />Ajukan WFH / koreksi / izin</button
                        ><button
                            v-if="has('organization.manage')"
                            class="secondary"
                            @click="schedule"
                        >
                            Atur jadwal</button
                        ><button
                            v-if="has('qr.display')"
                            class="primary"
                            @click="qrRunning ? stopQr() : startQr()"
                        >
                            <QrCode :size="17" />{{
                                qrRunning ? 'Tutup QR' : 'Tampilkan QR kantor'
                            }}
                        </button>
                    </section>
                    <div v-if="qrRunning || qrError" class="panel qr-panel">
                        <h2>QR absensi WFO</h2>
                        <img
                            v-if="qrImage"
                            :src="qrImage"
                            alt="QR absensi kantor yang berlaku 15 detik"
                        />
                        <p>
                            {{
                                qrError ||
                                `QR diperbarui dalam ${countdown} detik. Pindai dari akun karyawan.`
                            }}
                        </p>
                    </div>
                    <form
                        v-if="me"
                        class="panel clock-form"
                        @submit.prevent="
                            clock.post('/attendance/clock', {
                                preserveScroll: true,
                            })
                        "
                    >
                        <h3>Catat kehadiran</h3>
                        <select v-model="clock.mode" aria-label="Mode kerja">
                            <option value="wfo">WFO — QR kantor</option>
                            <option value="wfh">Saya WFH</option></select
                        ><select
                            v-model="clock.event"
                            aria-label="Jenis absensi"
                        >
                            <option value="in">Absen masuk</option>
                            <option value="out">Absen keluar</option></select
                        ><label
                            >Tanggal kerja
                            <input
                                v-model="clock.work_date"
                                type="date"
                                required /></label
                        ><template v-if="clock.mode === 'wfo'"
                            ><button
                                type="button"
                                class="secondary"
                                @click="scanQr"
                            >
                                <QrCode :size="17" />Pindai QR</button
                            ><span v-if="clock.token" class="pill green"
                                >QR terbaca</span
                            ><video
                                ref="video"
                                :class="{ 'camera-hidden': !cameraRunning }"
                                class="camera-video"
                            />
                            <p v-if="cameraError" class="error">
                                {{ cameraError }}
                            </p></template
                        ><button
                            class="primary"
                            :disabled="
                                clock.processing ||
                                (clock.mode === 'wfo' && !clock.token)
                            "
                        >
                            Catat
                            {{ clock.event === 'in' ? 'masuk' : 'keluar' }}
                        </button>
                        <p
                            v-for="error in clock.errors"
                            :key="error"
                            class="error"
                        >
                            {{ error }}
                        </p>
                    </form>
                    <section class="panel">
                        <div class="panel-heading">
                            <div>
                                <h2>Catatan kehadiran</h2>
                                <p>
                                    Kinerja dan kehadiran dihitung sebagai
                                    indikator terpisah.
                                </p>
                            </div>
                            <a
                                href="/template-absensi.xlsx"
                                class="text-button"
                                download
                                >Template Excel <ArrowDownToLine :size="15"
                            /></a>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Karyawan</th>
                                        <th>Tanggal kerja</th>
                                        <th>Masuk</th>
                                        <th>Keluar</th>
                                        <th>Mode / sumber</th>
                                        <th>Status</th>
                                        <th>Terlambat / pulang awal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="a in attendances" :key="a.id">
                                        <td>
                                            <strong>{{
                                                employeeName(a.employee_id)
                                            }}</strong>
                                        </td>
                                        <td>{{ date(a.work_date) }}</td>
                                        <td>{{ time(a.clock_in) }}</td>
                                        <td>{{ time(a.clock_out) }}</td>
                                        <td>
                                            {{ a.mode.toUpperCase() }} ·
                                            {{ a.source }}
                                        </td>
                                        <td>
                                            <span
                                                class="pill"
                                                :class="
                                                    a.late_minutes
                                                        ? 'orange'
                                                        : 'green'
                                                "
                                                >{{
                                                    statusText(a.status)
                                                }}</span
                                            >
                                        </td>
                                        <td>
                                            {{ a.late_minutes }} /
                                            {{ a.early_minutes }} menit
                                        </td>
                                    </tr>
                                    <tr v-if="!attendances.length">
                                        <td colspan="7" class="table-empty">
                                            Belum ada catatan absensi pada
                                            periode ini.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <section class="panel">
                        <div class="panel-heading">
                            <div>
                                <h2>Pengajuan karyawan</h2>
                                <p>
                                    Setiap keputusan tersimpan beserta alasan.
                                </p>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Karyawan</th>
                                        <th>Tanggal</th>
                                        <th>Jenis</th>
                                        <th>Alasan</th>
                                        <th>Status</th>
                                        <th>Keputusan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="r in requests" :key="r.id">
                                        <td>
                                            {{ employeeName(r.employee_id) }}
                                        </td>
                                        <td>{{ date(r.work_date) }}</td>
                                        <td>{{ r.type }}</td>
                                        <td>{{ r.reason }}</td>
                                        <td>
                                            <span
                                                class="pill"
                                                :class="
                                                    r.status === 'approved'
                                                        ? 'green'
                                                        : r.status ===
                                                            'rejected'
                                                          ? 'orange'
                                                          : 'neutral'
                                                "
                                                >{{
                                                    statusText(r.status)
                                                }}</span
                                            >
                                        </td>
                                        <td>
                                            <button
                                                v-if="
                                                    has('attendance.approve') &&
                                                    r.status === 'pending'
                                                "
                                                class="text-button"
                                                @click="decide(r)"
                                            >
                                                Tinjau
                                                <ArrowRight
                                                    :size="15"
                                                /></button
                                            ><span v-else>{{
                                                r.decision_note || '—'
                                            }}</span>
                                        </td>
                                    </tr>
                                    <tr v-if="!requests.length">
                                        <td colspan="6" class="table-empty">
                                            Tidak ada pengajuan.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <section class="panel">
                        <div class="panel-heading">
                            <h2>Jadwal kerja</h2>
                            <span class="muted"
                                >Maksimal 500 jadwal terbaru</span
                            >
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Karyawan</th>
                                        <th>Tanggal</th>
                                        <th>Jadwal masuk</th>
                                        <th>Jadwal keluar</th>
                                        <th>Toleransi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="s in schedules" :key="s.id">
                                        <td>
                                            {{
                                                employeeName(
                                                    Number(s.employee_id),
                                                )
                                            }}
                                        </td>
                                        <td>{{ date(String(s.work_date)) }}</td>
                                        <td>{{ s.scheduled_in }}</td>
                                        <td>{{ s.scheduled_out }}</td>
                                        <td>{{ s.tolerance_minutes }} menit</td>
                                    </tr>
                                    <tr v-if="!schedules.length">
                                        <td colspan="5" class="table-empty">
                                            Tetapkan jadwal sebelum mencatat
                                            absensi.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </template>

                <template v-if="tab === 'kpi'">
                    <div class="info-banner">
                        <Target :size="20" /><span
                            >Target dan bobot dikunci saat periode aktif. KPI
                            tim tetap ditampilkan sebagai indikator tim.</span
                        ><button
                            v-if="has('kpi.manage')"
                            class="text-button"
                            @click="changeStatus"
                        >
                            {{
                                period
                                    ? statusText(period.status)
                                    : 'Atur periode'
                            }}
                            <ArrowRight :size="16" />
                        </button>
                    </div>
                    <section class="panel">
                        <div class="panel-heading">
                            <div>
                                <h2>Target & realisasi KPI</h2>
                                <p>
                                    Data tervalidasi menjadi dasar laporan
                                    kinerja.
                                </p>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Indikator / sumber</th>
                                        <th>Pemilik</th>
                                        <th>Target</th>
                                        <th>Bobot</th>
                                        <th>Realisasi</th>
                                        <th>Status</th>
                                        <th>Tindakan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="k in targets" :key="k.id">
                                        <td>
                                            <strong>{{
                                                k.definition.name
                                            }}</strong
                                            ><small
                                                >{{ k.definition.source }} ·
                                                {{
                                                    k.definition.direction ===
                                                    'higher'
                                                        ? '↑ Lebih tinggi lebih baik'
                                                        : '↓ Lebih rendah lebih baik'
                                                }}</small
                                            >
                                        </td>
                                        <td>
                                            {{
                                                k.employee_id
                                                    ? employeeName(
                                                          k.employee_id,
                                                      )
                                                    : 'Tim divisi #' +
                                                      k.department_id
                                            }}<small>{{
                                                k.employee_id
                                                    ? 'Individu'
                                                    : 'Tim'
                                            }}</small>
                                        </td>
                                        <td>
                                            {{ number(k.target) }}
                                            {{ k.definition.unit }}
                                        </td>
                                        <td>
                                            {{
                                                k.employee_id
                                                    ? `${number(k.weight)}%`
                                                    : 'Terpisah'
                                            }}
                                        </td>
                                        <td>
                                            {{ number(k.submission?.actual)
                                            }}<a
                                                v-if="
                                                    k.submission?.evidence_path
                                                "
                                                :href="`/evidence/${k.submission.id}`"
                                                class="text-link"
                                                >Unduh bukti</a
                                            >
                                        </td>
                                        <td>
                                            <span
                                                class="pill"
                                                :class="
                                                    k.submission?.status ===
                                                    'validated'
                                                        ? 'green'
                                                        : 'orange'
                                                "
                                                >{{
                                                    k.submission
                                                        ? statusText(
                                                              k.submission
                                                                  .status,
                                                          )
                                                        : 'Belum Lengkap'
                                                }}</span
                                            >
                                        </td>
                                        <td>
                                            <button
                                                v-if="
                                                    has('kpi.manage') &&
                                                    period?.status ===
                                                        'planning'
                                                "
                                                class="text-button"
                                                @click="editTarget(k)"
                                            >
                                                Ubah target</button
                                            ><button
                                                v-if="
                                                    has('kpi.submit') &&
                                                    k.submission?.status !==
                                                        'validated'
                                                "
                                                class="text-button"
                                                @click="submitKpi(k)"
                                            >
                                                Input realisasi</button
                                            ><button
                                                v-if="
                                                    has('kpi.validate') &&
                                                    k.submission
                                                "
                                                class="text-button"
                                                @click="validateKpi(k)"
                                            >
                                                Validasi
                                                <ArrowRight
                                                    :size="14"
                                                /></button
                                            ><button
                                                v-if="
                                                    has('kpi.validate') &&
                                                    has('reviews.publish') &&
                                                    k.submission &&
                                                    [
                                                        'review',
                                                        'closed',
                                                    ].includes(
                                                        period?.status || '',
                                                    )
                                                "
                                                class="text-button"
                                                @click="correctKpi(k)"
                                            >
                                                Koreksi beralasan
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="!targets.length">
                                        <td colspan="7" class="table-empty">
                                            Belum ada target. Buat template KPI
                                            dan tetapkan target pada periode
                                            perencanaan.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </template>

                <template v-if="tab === 'reviews' || tab === 'reports'">
                    <div class="toolbar">
                        <div class="search-input">
                            <Search :size="17" /><input
                                v-model="query"
                                placeholder="Cari laporan karyawan…"
                            />
                        </div>
                        <select v-model="filter">
                            <option value="all">Semua laporan</option>
                            <option value="incomplete">Belum lengkap</option>
                            <option value="missed">KPI meleset</option>
                            <option value="unpublished">
                                Belum diterbitkan
                            </option></select
                        ><select v-model="department">
                            <option value="">Semua divisi</option>
                            <option
                                v-for="d in [
                                    ...new Set(
                                        employees.map((e) => e.department),
                                    ),
                                ]"
                                :key="d"
                            >
                                {{ d }}
                            </option>
                        </select>
                    </div>
                    <select
                        v-model="shiftFilter"
                        aria-label="Filter shift"
                        class="report-shift-filter"
                    >
                        <option value="">Semua shift</option>
                        <option
                            v-for="shift in shifts"
                            :key="shift.id"
                            :value="shift.id"
                        >
                            {{ shift.name }}
                        </option>
                    </select>
                    <section v-if="!currentReport" class="stats-grid">
                        <article>
                            <p>Karyawan dalam filter</p>
                            <strong
                                >{{ reportSummary.count
                                }}<small>orang</small></strong
                            >
                        </article>
                        <article>
                            <p>Rata-rata KPI individu</p>
                            <strong
                                >{{ number(reportSummary.average)
                                }}<small>%</small></strong
                            ><span class="stat-foot"
                                >Hanya nilai lengkap & tervalidasi</span
                            >
                        </article>
                        <article>
                            <p>Total kehadiran</p>
                            <strong
                                >{{ reportSummary.present
                                }}<small
                                    >/ {{ reportSummary.scheduled }} hari</small
                                ></strong
                            >
                        </article>
                        <article>
                            <p>Review diterbitkan</p>
                            <strong
                                >{{ reportSummary.published
                                }}<small>laporan</small></strong
                            >
                        </article>
                    </section>
                    <div v-if="!currentReport" class="panel">
                        <div class="panel-heading">
                            <div>
                                <h2>
                                    {{
                                        tab === 'reviews'
                                            ? 'Antrian review'
                                            : 'Laporan per orang'
                                    }}
                                </h2>
                                <p>
                                    {{
                                        tab === 'reviews'
                                            ? 'Validasi hasil, catat evaluasi, sepakati tindak lanjut.'
                                            : 'Indikator kinerja dan kehadiran dalam satu halaman.'
                                    }}
                                </p>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Karyawan</th>
                                        <th>Kinerja individu</th>
                                        <th>Kehadiran</th>
                                        <th>Kelengkapan</th>
                                        <th>Review</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="r in filteredReports"
                                        :key="r.employee.id"
                                    >
                                        <td>
                                            <div class="table-person">
                                                <span class="person-avatar">{{
                                                    initials(r.employee.name)
                                                }}</span>
                                                <div>
                                                    <strong>{{
                                                        r.employee.name
                                                    }}</strong
                                                    ><small
                                                        >{{
                                                            r.employee.position
                                                        }}
                                                        ·
                                                        {{
                                                            r.employee
                                                                .department
                                                        }}</small
                                                    >
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <strong
                                                >{{ number(r.data.score)
                                                }}{{
                                                    r.data.score !== null
                                                        ? '%'
                                                        : ''
                                                }}</strong
                                            >
                                        </td>
                                        <td>
                                            {{ r.data.attendance.present }} /
                                            {{ r.data.attendance.scheduled }}
                                            hari<small
                                                >{{
                                                    r.data.attendance.late
                                                }}
                                                kali terlambat</small
                                            >
                                        </td>
                                        <td>
                                            <span
                                                class="pill"
                                                :class="
                                                    r.data.complete
                                                        ? 'green'
                                                        : 'orange'
                                                "
                                                >{{
                                                    r.data.complete
                                                        ? 'Lengkap'
                                                        : 'Belum Lengkap'
                                                }}</span
                                            >
                                        </td>
                                        <td>
                                            {{
                                                r.review
                                                    ? statusText(
                                                          r.review.status,
                                                      )
                                                    : 'Belum direview'
                                            }}
                                        </td>
                                        <td>
                                            <button
                                                class="text-button"
                                                @click="
                                                    selectedReport =
                                                        r.employee.id
                                                "
                                            >
                                                Lihat detail
                                                <ArrowRight :size="15" />
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="!filteredReports.length">
                                        <td colspan="6" class="table-empty">
                                            Belum ada laporan yang sesuai filter
                                            dan cakupan akses Anda.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div v-else class="report-detail">
                        <button
                            class="text-button"
                            @click="selectedReport = null"
                        >
                            ← Kembali ke daftar laporan
                        </button>
                        <section class="panel report-header">
                            <div class="large-avatar">
                                {{ initials(currentReport.employee.name) }}
                            </div>
                            <div>
                                <span class="eyebrow"
                                    >LAPORAN KINERJA · {{ period?.name }}</span
                                >
                                <h2>{{ currentReport.employee.name }}</h2>
                                <p>
                                    {{ currentReport.employee.nik }} ·
                                    {{ currentReport.employee.position }} ·
                                    {{ currentReport.employee.department }}
                                </p>
                            </div>
                            <button
                                v-if="currentReport.can_review"
                                class="primary"
                                @click="reviewForm(currentReport)"
                            >
                                <ClipboardCheck :size="17" />{{
                                    currentReport.review?.status === 'published'
                                        ? 'Revisi review'
                                        : 'Tulis review'
                                }}
                            </button>
                        </section>
                        <div class="stats-grid report-stats">
                            <article>
                                <p>Kinerja individu</p>
                                <strong
                                    >{{ number(currentReport.data.score)
                                    }}<small>%</small></strong
                                ><span class="stat-foot"
                                    >Bobot
                                    {{ number(currentReport.data.weight) }}% ·
                                    periode sebelumnya
                                    {{
                                        number(currentReport.previous_score)
                                    }}%</span
                                >
                            </article>
                            <article>
                                <p>Kehadiran</p>
                                <strong
                                    >{{ currentReport.data.attendance.present
                                    }}<small
                                        >/
                                        {{
                                            currentReport.data.attendance
                                                .scheduled
                                        }}
                                        hari</small
                                    ></strong
                                ><span class="stat-foot"
                                    >{{ currentReport.data.attendance.late }}
                                    terlambat ·
                                    {{ currentReport.data.attendance.early }}
                                    pulang awal</span
                                >
                            </article>
                            <article>
                                <p>Izin & ketidakhadiran</p>
                                <strong
                                    >{{
                                        currentReport.data.attendance.leave +
                                        currentReport.data.attendance.sick +
                                        currentReport.data.attendance
                                            .permission
                                    }}<small>hari</small></strong
                                ><span class="stat-foot"
                                    >Cuti
                                    {{ currentReport.data.attendance.leave }} ·
                                    Sakit
                                    {{ currentReport.data.attendance.sick }} ·
                                    Izin
                                    {{
                                        currentReport.data.attendance.permission
                                    }}
                                    · Tanpa keterangan
                                    {{
                                        currentReport.data.attendance.unexcused
                                    }}</span
                                >
                            </article>
                        </div>
                        <section class="panel">
                            <div class="panel-heading">
                                <h2>Detail pencapaian</h2>
                                <span
                                    class="pill"
                                    :class="
                                        currentReport.data.complete
                                            ? 'green'
                                            : 'orange'
                                    "
                                    >{{
                                        currentReport.data.complete
                                            ? 'Data lengkap'
                                            : 'Belum Lengkap'
                                    }}</span
                                >
                            </div>
                            <div
                                v-for="k in currentReport.data.targets"
                                :key="k.id"
                                class="kpi-detail"
                            >
                                <div>
                                    <strong>{{ k.definition.name }}</strong
                                    ><small
                                        >{{
                                            k.employee_id
                                                ? 'KPI individu'
                                                : 'KPI tim — tidak digabung ke nilai individu'
                                        }}
                                        · {{ k.definition.source }}</small
                                    >
                                </div>
                                <div>
                                    <strong
                                        >{{ number(k.submission?.actual) }} /
                                        {{ number(k.target) }}
                                        {{ k.definition.unit }}</strong
                                    ><small
                                        >{{
                                            k.complete
                                                ? 'Tervalidasi'
                                                : 'Belum lengkap'
                                        }}
                                        · {{ number(k.score) }}%</small
                                    >
                                </div>
                                <div class="progress-track">
                                    <span
                                        :class="{
                                            'amber-bar': (k.score ?? 0) < 100,
                                        }"
                                        :style="{
                                            width: `${Math.min(100, k.score ?? 0)}%`,
                                        }"
                                    ></span>
                                </div>
                                <p v-if="k.submission?.note">
                                    {{ k.submission.note }}
                                </p>
                            </div>
                        </section>
                        <div class="dashboard-columns">
                            <section class="panel review-text">
                                <span class="stat-icon lavender"
                                    ><ChartNoAxesCombined :size="20"
                                /></span>
                                <h2>Analisis supervisor</h2>
                                <p>
                                    {{
                                        currentReport.review?.analysis ||
                                        'Analisis belum diterbitkan.'
                                    }}
                                </p>
                                <span
                                    v-if="currentReport.review"
                                    class="pill neutral"
                                    >{{
                                        statusText(currentReport.review.status)
                                    }}
                                    · revisi
                                    {{ currentReport.review.revision }}</span
                                >
                            </section>
                            <section class="panel review-text">
                                <span class="stat-icon mint"
                                    ><Target :size="20"
                                /></span>
                                <h2>Tindak lanjut</h2>
                                <p>
                                    {{
                                        currentReport.review?.follow_up ||
                                        'Tindak lanjut belum ditetapkan.'
                                    }}
                                </p>
                                <span
                                    v-if="currentReport.review"
                                    class="pill green"
                                    >Tinjau kembali
                                    {{
                                        date(currentReport.review.due_on)
                                    }}</span
                                >
                            </section>
                        </div>
                        <section
                            v-if="currentReport.review?.status === 'published'"
                            class="panel review-text"
                        >
                            <h2>Tanggapan karyawan</h2>
                            <p>
                                {{
                                    currentReport.review.response ||
                                    'Belum ada tanggapan.'
                                }}
                            </p>
                            <button
                                v-if="currentReport.employee.id === me?.id"
                                class="secondary"
                                @click="
                                    modal = {
                                        title: 'Tanggapan laporan',
                                        url: `/reviews/${currentReport.review.id}/respond`,
                                        fields: [
                                            field(
                                                'response',
                                                'Tanggapan',
                                                'textarea',
                                            ),
                                        ],
                                    }
                                "
                            >
                                Tulis tanggapan
                            </button>
                        </section>
                    </div>
                </template>

                <template v-if="tab === 'organization'">
                    <div class="info-banner">
                        <Building2 :size="20" /><span
                            >Mulai dari divisi → jabatan → akun → karyawan →
                            shift & jadwal → template KPI → periode &
                            target.</span
                        >
                    </div>
                    <div class="master-grid">
                        <section
                            v-if="has('organization.manage')"
                            class="panel"
                        >
                            <div class="panel-heading">
                                <h2>Divisi</h2>
                                <button
                                    class="round-button"
                                    aria-label="Tambah divisi"
                                    @click="
                                        openForm(
                                            'Tambah divisi',
                                            'departments',
                                            [field('name', 'Nama divisi')],
                                        )
                                    "
                                >
                                    <Plus :size="18" />
                                </button>
                            </div>
                            <div
                                v-for="d in departments"
                                :key="d.id"
                                class="master-row"
                            >
                                <Building2 :size="17" /><strong>{{
                                    d.name
                                }}</strong
                                ><button
                                    class="text-button"
                                    @click="editMaster('departments', d)"
                                >
                                    Ubah
                                </button>
                            </div>
                            <p v-if="!departments.length" class="table-empty">
                                Belum ada divisi.
                            </p>
                        </section>
                        <section
                            v-if="has('organization.manage')"
                            class="panel"
                        >
                            <div class="panel-heading">
                                <h2>Jabatan</h2>
                                <button
                                    class="round-button"
                                    aria-label="Tambah jabatan"
                                    @click="
                                        openForm(
                                            'Tambah jabatan',
                                            'positions',
                                            [
                                                field(
                                                    'department_id',
                                                    'Divisi',
                                                    'select',
                                                    true,
                                                    names(departments),
                                                ),
                                                field('name', 'Nama jabatan'),
                                            ],
                                        )
                                    "
                                >
                                    <Plus :size="18" />
                                </button>
                            </div>
                            <div
                                v-for="p in positions"
                                :key="p.id"
                                class="master-row"
                            >
                                <div>
                                    <strong>{{ p.name }}</strong
                                    ><small>{{
                                        departments.find(
                                            (d) => d.id === p.department_id,
                                        )?.name
                                    }}</small>
                                </div>
                                <button
                                    class="text-button"
                                    @click="editMaster('positions', p)"
                                >
                                    Ubah
                                </button>
                            </div>
                            <p v-if="!positions.length" class="table-empty">
                                Belum ada jabatan.
                            </p>
                        </section>
                        <section
                            v-if="has('organization.manage')"
                            class="panel"
                        >
                            <div class="panel-heading">
                                <h2>Shift kerja</h2>
                                <button
                                    class="round-button"
                                    aria-label="Tambah shift"
                                    @click="
                                        openForm('Tambah shift', 'shifts', [
                                            field('name', 'Nama shift'),
                                            field(
                                                'starts_at',
                                                'Jam masuk',
                                                'time',
                                            ),
                                            field(
                                                'ends_at',
                                                'Jam keluar',
                                                'time',
                                            ),
                                            field(
                                                'tolerance_minutes',
                                                'Toleransi keterlambatan (menit)',
                                                'number',
                                            ),
                                        ])
                                    "
                                >
                                    <Plus :size="18" />
                                </button>
                            </div>
                            <div
                                v-for="s in shifts"
                                :key="s.id"
                                class="master-row"
                            >
                                <div>
                                    <strong>{{ s.name }}</strong
                                    ><small
                                        >{{ s.starts_at }} – {{ s.ends_at }} ·
                                        toleransi
                                        {{ s.tolerance_minutes }} menit</small
                                    >
                                </div>
                                <button
                                    class="text-button"
                                    @click="editMaster('shifts', s)"
                                >
                                    Ubah
                                </button>
                            </div>
                            <button
                                class="text-button padded"
                                @click="schedule"
                            >
                                Tetapkan jadwal <ArrowRight :size="15" />
                            </button>
                        </section>
                        <section v-if="has('kpi.manage')" class="panel">
                            <div class="panel-heading">
                                <h2>Periode penilaian</h2>
                                <button
                                    class="round-button"
                                    aria-label="Tambah periode"
                                    @click="
                                        openForm('Tambah periode', 'periods', [
                                            field('name', 'Nama periode'),
                                            field(
                                                'starts_on',
                                                'Tanggal mulai',
                                                'date',
                                            ),
                                            field(
                                                'ends_on',
                                                'Tanggal akhir',
                                                'date',
                                            ),
                                        ])
                                    "
                                >
                                    <Plus :size="18" />
                                </button>
                            </div>
                            <div
                                v-for="p in periods"
                                :key="p.id"
                                class="master-row"
                            >
                                <div>
                                    <strong>{{ p.name }}</strong
                                    ><small
                                        >{{ date(p.starts_on) }} –
                                        {{ date(p.ends_on) }}</small
                                    >
                                </div>
                                <span class="pill neutral">{{
                                    statusText(p.status)
                                }}</span
                                ><button
                                    v-if="p.status === 'planning'"
                                    class="text-button"
                                    @click="
                                        editMaster(
                                            'periods',
                                            p as unknown as Row,
                                        )
                                    "
                                >
                                    Ubah
                                </button>
                            </div>
                            <button
                                class="text-button padded"
                                @click="changeStatus"
                            >
                                Lanjutkan tahap periode
                                <ArrowRight :size="15" />
                            </button>
                        </section>
                    </div>
                    <section v-if="has('kpi.manage')" class="panel">
                        <div class="panel-heading">
                            <div>
                                <h2>Template KPI per jabatan</h2>
                                <p>
                                    Tipe, satuan, arah penilaian, dan sumber
                                    data ditetapkan sebelum periode.
                                </p>
                            </div>
                            <button
                                class="primary"
                                @click="
                                    openForm('Template KPI', 'metrics', [
                                        field(
                                            'position_id',
                                            'Jabatan',
                                            'select',
                                            true,
                                            names(positions),
                                        ),
                                        field('name', 'Nama indikator'),
                                        field('unit', 'Satuan'),
                                        field(
                                            'direction',
                                            'Arah penilaian',
                                            'select',
                                            true,
                                            [
                                                {
                                                    value: 'higher',
                                                    label: 'Semakin tinggi semakin baik',
                                                },
                                                {
                                                    value: 'lower',
                                                    label: 'Semakin rendah semakin baik',
                                                },
                                            ],
                                        ),
                                        field(
                                            'level',
                                            'Tingkat pencatatan',
                                            'select',
                                            true,
                                            [
                                                {
                                                    value: 'individual',
                                                    label: 'Individu',
                                                },
                                                { value: 'team', label: 'Tim' },
                                            ],
                                        ),
                                        field('source', 'Sumber data'),
                                        field(
                                            'score_cap',
                                            'Batas nilai (%)',
                                            'number',
                                            true,
                                            undefined,
                                            120,
                                        ),
                                    ])
                                "
                            >
                                <Plus :size="16" />Template KPI
                            </button>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Indikator</th>
                                        <th>Jabatan</th>
                                        <th>Tingkat</th>
                                        <th>Sumber</th>
                                        <th>Batas nilai</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="m in metrics" :key="m.id">
                                        <td>
                                            <strong>{{ m.name }}</strong
                                            ><small
                                                >{{ m.unit }} ·
                                                {{ m.direction }}</small
                                            >
                                        </td>
                                        <td>
                                            {{
                                                positions.find(
                                                    (p) =>
                                                        p.id === m.position_id,
                                                )?.name
                                            }}
                                        </td>
                                        <td>{{ m.level }}</td>
                                        <td>{{ m.source }}</td>
                                        <td>
                                            {{ m.score_cap }}%
                                            <button
                                                class="text-button"
                                                @click="
                                                    editMaster('metrics', m)
                                                "
                                            >
                                                Ubah
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="!metrics.length">
                                        <td colspan="5" class="table-empty">
                                            Belum ada template KPI.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <section v-if="placements.length" class="panel">
                        <div class="panel-heading">
                            <h2>Riwayat penempatan</h2>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Karyawan</th>
                                        <th>Jabatan</th>
                                        <th>Mulai</th>
                                        <th>Sampai</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in placements" :key="p.id">
                                        <td>
                                            {{
                                                employeeName(
                                                    Number(p.employee_id),
                                                )
                                            }}
                                        </td>
                                        <td>
                                            {{
                                                positions.find(
                                                    (x) =>
                                                        x.id === p.position_id,
                                                )?.name
                                            }}
                                        </td>
                                        <td>{{ p.starts_on }}</td>
                                        <td>{{ p.ends_on || 'Sekarang' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </template>

                <template v-if="tab === 'access'">
                    <section v-if="has('accounts.manage')" class="panel">
                        <div class="panel-heading">
                            <div>
                                <h2>Akun internal</h2>
                                <p>
                                    Hanya domain tepat @alfar.id. Password
                                    pertama wajib diganti.
                                </p>
                            </div>
                            <button
                                class="primary"
                                @click="
                                    openForm('Buat akun internal', 'accounts', [
                                        field('name', 'Nama lengkap'),
                                        field(
                                            'email',
                                            'Email @alfar.id',
                                            'email',
                                        ),
                                        {
                                            ...field(
                                                'password',
                                                'Password sementara',
                                                'password',
                                            ),
                                            hint: 'Minimal 12 karakter, huruf dan angka',
                                        },
                                        field(
                                            'password_confirmation',
                                            'Ulangi password',
                                            'password',
                                        ),
                                    ])
                                "
                            >
                                <Plus :size="16" />Buat akun
                            </button>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nama</th>
                                        <th>Email</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="u in accounts" :key="u.id">
                                        <td>{{ u.name }}</td>
                                        <td>{{ u.email }}</td>
                                        <td>
                                            <span class="pill green">{{
                                                u.active ? 'Aktif' : 'Nonaktif'
                                            }}</span>
                                            <button
                                                class="text-button"
                                                @click="
                                                    editMaster('accounts', u)
                                                "
                                            >
                                                Ubah
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <section v-if="has('roles.manage')" class="panel">
                        <div class="panel-heading">
                            <div>
                                <h2>Role & cakupan data</h2>
                                <p>
                                    Izin mengelola akun terpisah dari izin
                                    membaca laporan.
                                </p>
                            </div>
                            <button class="primary" @click="editRole()">
                                <Plus :size="16" />Buat role
                            </button>
                        </div>
                        <div
                            v-for="role in roles"
                            :key="role.id"
                            class="master-row"
                        >
                            <div>
                                <strong>{{ role.name }}</strong
                                ><small
                                    >{{ role.grants.length }} izin ·
                                    {{
                                        role.grants
                                            .map((g) => g.scope)
                                            .filter(
                                                (x, i, a) => a.indexOf(x) === i,
                                            )
                                            .join(', ')
                                    }}</small
                                >
                            </div>
                            <button class="text-button" @click="editRole(role)">
                                Ubah izin <ArrowRight :size="15" />
                            </button>
                        </div>
                        <p v-if="!roles.length" class="table-empty">
                            Buat role sesuai kebutuhan organisasi.
                        </p>
                    </section>
                    <form
                        v-if="has('roles.manage')"
                        class="panel role-assignment"
                        @submit.prevent="assignRoles"
                    >
                        <h2>Tetapkan role ke akun</h2>
                        <select
                            v-model="assignUser"
                            required
                            @change="loadRoles"
                        >
                            <option value="">Pilih akun…</option>
                            <option
                                v-for="u in accounts"
                                :key="u.id"
                                :value="u.id"
                            >
                                {{ u.name }} · {{ u.email }}
                            </option></select
                        ><label v-for="role in roles" :key="role.id"
                            ><input
                                v-model="assignedRoles"
                                type="checkbox"
                                :value="role.id"
                            />{{ role.name }}</label
                        ><button class="primary" :disabled="!assignUser">
                            Simpan penetapan
                        </button>
                    </form>
                </template>

                <template v-if="tab === 'devices'">
                    <div class="info-banner">
                        <Fingerprint :size="21" /><span
                            >Endpoint fingerprint siap menerima event WFO.
                            Koneksi alat fisik memerlukan adapter sesuai
                            merek/model.</span
                        >
                    </div>
                    <section class="panel">
                        <div class="panel-heading">
                            <div>
                                <h2>Perangkat fingerprint</h2>
                                <p>
                                    Token disimpan sebagai hash dan hanya
                                    dikirim melalui HTTPS.
                                </p>
                            </div>
                            <button
                                class="primary"
                                @click="
                                    openForm('Daftarkan perangkat', 'devices', [
                                        field('name', 'Nama perangkat'),
                                        {
                                            ...field(
                                                'token',
                                                'Token acak perangkat',
                                                'password',
                                            ),
                                            hint: 'Minimal 40 karakter acak. Simpan token asli di adapter perangkat.',
                                        },
                                    ])
                                "
                            >
                                <Plus :size="16" />Perangkat
                            </button>
                        </div>
                        <div
                            v-for="d in devices"
                            :key="d.id"
                            class="master-row"
                        >
                            <Fingerprint :size="20" /><strong>{{
                                d.name
                            }}</strong
                            ><span class="pill green">{{
                                d.active ? 'Aktif' : 'Nonaktif'
                            }}</span
                            ><button
                                class="text-button"
                                @click="editMaster('devices', d)"
                            >
                                Ubah
                            </button>
                        </div>
                    </section>
                    <section class="panel">
                        <div class="panel-heading">
                            <h2>Pemetaan identitas fingerprint</h2>
                            <button
                                class="primary"
                                @click="
                                    openForm(
                                        'Hubungkan identitas alat',
                                        'device-mappings',
                                        [
                                            field(
                                                'device_id',
                                                'Perangkat',
                                                'select',
                                                true,
                                                names(devices),
                                            ),
                                            field(
                                                'employee_id',
                                                'Karyawan',
                                                'select',
                                                true,
                                                employeeOptions,
                                            ),
                                            field(
                                                'external_id',
                                                'ID pengguna pada alat',
                                            ),
                                        ],
                                    )
                                "
                            >
                                <Plus :size="16" />Hubungkan
                            </button>
                        </div>
                        <div
                            v-for="m in deviceMappings"
                            :key="m.id"
                            class="master-row"
                        >
                            <strong>{{
                                employeeName(Number(m.employee_id))
                            }}</strong
                            ><span
                                >{{
                                    devices.find((d) => d.id === m.device_id)
                                        ?.name
                                }}
                                · ID {{ m.external_id }}</span
                            >
                        </div>
                    </section>
                </template>
                <section v-if="tab === 'audit'" class="panel">
                    <div class="panel-heading">
                        <div>
                            <h2>Jejak perubahan</h2>
                            <p>
                                100 aktivitas terbaru. Snapshot sebelum/sesudah
                                tersimpan di database.
                            </p>
                        </div>
                        <ShieldCheck :size="20" />
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Waktu</th>
                                    <th>Aktor</th>
                                    <th>Tindakan</th>
                                    <th>Objek</th>
                                    <th>Alasan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="a in audits" :key="a.id">
                                    <td>{{ a.created_at }}</td>
                                    <td>
                                        {{
                                            accounts.find(
                                                (u) => u.id === a.actor_id,
                                            )?.name || 'Perangkat / sistem'
                                        }}
                                    </td>
                                    <td>
                                        <span class="pill neutral">{{
                                            a.action
                                        }}</span>
                                    </td>
                                    <td>{{ a.subject }}</td>
                                    <td>{{ a.reason || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <footer class="alfar-footer">
                    <span>© {{ new Date().getFullYear() }} Alfar People</span
                    ><span
                        >Kinerja yang terukur. Pertumbuhan yang terarah.</span
                    >
                </footer>
            </main>
        </div>
        <div v-if="modal" class="modal-backdrop" @click.self="modal = null">
            <section
                class="modal-card"
                role="dialog"
                aria-modal="true"
                :aria-label="modal.title"
            >
                <button
                    class="modal-close icon-button"
                    aria-label="Tutup formulir"
                    @click="modal = null"
                >
                    <X :size="21" /></button
                ><DataForm
                    :key="modal.title + modal.url"
                    v-bind="modal"
                    @saved="modal = null"
                />
            </section>
        </div>
        <div
            v-if="roleEditor"
            class="modal-backdrop"
            @click.self="roleEditor = false"
        >
            <form
                class="modal-card role-editor"
                role="dialog"
                aria-modal="true"
                aria-label="Pengaturan role"
                @submit.prevent="
                    roleForm.post('/manage/roles', {
                        preserveScroll: true,
                        onSuccess: () => (roleEditor = false),
                    })
                "
            >
                <button
                    type="button"
                    class="modal-close icon-button"
                    aria-label="Tutup"
                    @click="roleEditor = false"
                >
                    <X :size="21" />
                </button>
                <h2>{{ roleForm.id ? 'Ubah role' : 'Buat role' }}</h2>
                <label
                    >Nama role<input v-model="roleForm.name" required
                /></label>
                <p class="muted">
                    self = diri sendiri · reports = bawahan langsung ·
                    department = satu divisi · all = seluruh data. Administrasi
                    master dan akun harus memakai all.
                </p>
                <div
                    v-for="permission in permissionCatalog"
                    :key="permission"
                    class="grant-row"
                >
                    <label
                        ><input
                            type="checkbox"
                            :checked="
                                roleForm.grants.some(
                                    (g) => g.permission === permission,
                                )
                            "
                            @change="
                                toggleGrant(
                                    permission,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />{{ permission }}</label
                    ><template
                        v-for="grant in roleForm.grants.filter(
                            (g) => g.permission === permission,
                        )"
                        :key="grant.permission"
                        ><select v-model="grant.scope">
                            <option v-for="scope in scopeCatalog" :key="scope">
                                {{ scope }}
                            </option>
                        </select></template
                    >
                </div>
                <p v-for="error in roleForm.errors" :key="error" class="error">
                    {{ error }}
                </p>
                <button class="primary" :disabled="roleForm.processing">
                    Simpan role & izin
                </button>
            </form>
        </div>
    </div>
</template>
<style src="./workspace.css"></style>
