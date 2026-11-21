<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
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
    Menu,
    Settings2,
    ShieldCheck,
    Target,
    TrendingUp,
    Users,
    X,
} from '@lucide/vue';
import {
    computed,
    ref,
    watch,
    nextTick,
    onMounted,
    onBeforeUnmount,
} from 'vue';
import { useMediaQuery } from '@vueuse/core';
import ActionCenter from '@/components/alfar/ActionCenter.vue';
import NotificationCenter from '@/components/alfar/NotificationCenter.vue';
import {
    unreadNotifications,
    startNotifications,
    stopNotifications,
} from '@/composables/useNotifications';
import EmployeeQr from '@/components/alfar/EmployeeQr.vue';
import AttendanceScanner, {
    type ScanDevice,
} from '@/components/alfar/AttendanceScanner.vue';
import TechnicalHint from '@/components/alfar/TechnicalHint.vue';
import {
    companyWidePermissions,
    permissionLabel,
    scopeLabel,
} from '@/lib/accessLabels';
import AppSelect from '@/components/alfar/AppSelect.vue';
import BulkScheduleForm from '@/components/alfar/BulkScheduleForm.vue';
import SearchInput from '@/components/alfar/SearchInput.vue';
import EmployeeSchedules from '@/components/alfar/EmployeeSchedules.vue';
import AttendanceReport from '@/components/alfar/AttendanceReport.vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';

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
    active?: boolean | number;
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
    devices: ScanDevice[];
    deviceMappings: Row[];
    audits: Row[];
    placements: Row[];
}>();
const page = usePage();
const tab = ref(
    props.me &&
        !props.permissions.some((p) =>
            [
                'organization.manage',
                'accounts.manage',
                'roles.manage',
                'attendance.scan',
                'reviews.publish',
                'kpi.validate',
            ].includes(p),
        )
        ? 'my-attendance'
        : 'overview',
);
const sidebarOpen = ref(false);
const compactNavigation = useMediaQuery('(max-width: 900px)');
const navigationToggle = ref<HTMLButtonElement | null>(null);
function closeNavigation() {
    if (sidebarOpen.value) navigationToggle.value?.focus();
    sidebarOpen.value = false;
}
watch(tab, closeNavigation);
watch(compactNavigation, () => {
    sidebarOpen.value = false;
});
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
const canUseActionCenter = computed(() =>
    [
        'attendance.read',
        'attendance.approve',
        'kpi.validate',
        'kpi.manage',
        'reviews.publish',
    ].some(has),
);
const attentionDate = ref('');
const actionDialogOpen = ref(false);
async function openAction(item: {
    destination: string;
    name: string;
    date: string;
    entity_id: number;
    employee_id?: number | null;
}) {
    tab.value = item.destination;
    query.value =
        item.destination === 'reviews' && !item.employee_id ? '' : item.name;
    if (item.destination === 'reviews')
        selectedReport.value = item.employee_id ?? null;
    attentionDate.value = item.date;
    await nextTick();
    document
        .getElementById(
            `${item.destination === 'kpi' ? 'target' : 'request'}-${item.entity_id}`,
        )
        ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
const menuGroups = computed(() =>
    [
        {
            label: 'Ringkasan',
            items: [
                {
                    id: 'overview',
                    label: 'Dasbor',
                    icon: LayoutDashboard,
                    show: true,
                },
                {
                    id: 'follow-ups',
                    label: 'Perlu ditindaklanjuti',
                    icon: ClipboardCheck,
                    show: canUseActionCenter.value,
                },
                {
                    id: 'approvals',
                    label: 'Pusat persetujuan',
                    icon: Check,
                    show: has('attendance.approve') || has('kpi.validate'),
                },
                {
                    id: 'notifications',
                    label: 'Notifikasi saya',
                    icon: Bell,
                    show: true,
                },
            ],
        },
        {
            label: '1. Organisasi',
            items: [
                {
                    id: 'departments',
                    label: 'Divisi',
                    icon: Building2,
                    show: has('organization.manage'),
                },
                {
                    id: 'positions',
                    label: 'Jabatan',
                    icon: Building2,
                    show: has('organization.manage'),
                },
            ],
        },
        {
            label: '2. Tim & hak akses',
            items: [
                {
                    id: 'roles',
                    label: 'Peran & hak akses',
                    icon: ShieldCheck,
                    show: has('roles.manage'),
                },
                {
                    id: 'accounts',
                    label: 'Akun karyawan',
                    icon: Users,
                    show: has('accounts.manage'),
                },
                {
                    id: 'employees',
                    label: 'Data karyawan',
                    icon: Users,
                    show:
                        props.employees.length > 0 ||
                        has('organization.manage'),
                },
            ],
        },
        {
            label: '3. Kehadiran',
            items: [
                {
                    id: 'shifts',
                    label: 'Shift kerja',
                    icon: Clock3,
                    show: has('organization.manage'),
                },
                {
                    id: 'schedules',
                    label: 'Jadwal karyawan',
                    icon: CalendarDays,
                    show: has('organization.manage'),
                },
                {
                    id: 'devices',
                    label: 'Perangkat absensi',
                    icon: Fingerprint,
                    show: has('devices.manage'),
                },
                {
                    id: 'scanner',
                    label: 'Pemindai QR kantor',
                    icon: QrCode,
                    show: has('attendance.scan'),
                },
                {
                    id: 'my-attendance',
                    label: 'Absensi saya',
                    icon: QrCode,
                    show: true,
                },
                {
                    id: 'attendance',
                    label: 'Rekap kehadiran',
                    icon: CalendarDays,
                    show: has('attendance.read') || has('attendance.manage'),
                },
                {
                    id: 'requests',
                    label: 'Pengajuan & persetujuan',
                    icon: ClipboardCheck,
                    show: !!props.me || has('attendance.approve'),
                },
            ],
        },
        {
            label: '4. Penilaian kinerja',
            items: [
                {
                    id: 'metrics',
                    label: 'Indikator per jabatan',
                    icon: Target,
                    show: has('kpi.manage'),
                },
                {
                    id: 'periods',
                    label: 'Periode penilaian',
                    icon: CalendarDays,
                    show: has('kpi.manage'),
                },
                {
                    id: 'kpi',
                    label: 'Target & realisasi',
                    icon: Target,
                    show: props.targets.length > 0 || has('kpi.manage'),
                },
                {
                    id: 'reviews',
                    label: 'Penilaian supervisor',
                    icon: ClipboardCheck,
                    show: props.reports.length > 0 || has('reviews.publish'),
                },
                {
                    id: 'reports',
                    label: 'Laporan & analisis',
                    icon: ChartNoAxesCombined,
                    show: has('reports.read'),
                },
            ],
        },
        {
            label: 'Pengawasan',
            items: [
                {
                    id: 'audit',
                    label: 'Riwayat aktivitas',
                    icon: Activity,
                    show: props.audits.length > 0,
                },
            ],
        },
    ]
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => item.show),
        }))
        .filter((group) => group.items.length),
);
const tabs = computed(() => menuGroups.value.flatMap((group) => group.items));
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
            `${e.name} ${e.position} ${e.department}`
                .toLowerCase()
                .includes(query.value.toLowerCase()) &&
            (!department.value || e.department === department.value),
    ),
);
const filteredReports = computed(() =>
    props.reports.filter(
        (r) =>
            `${r.employee.name} ${r.employee.position} ${r.employee.department}`
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
function changePeriod(value: unknown) {
    router.get(
        '/dashboard',
        { period: Number(value) },
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
        field(
            'department_id',
            'Divisi',
            'select',
            true,
            names(props.departments),
            e?.department_id,
        ),
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
const bulkScheduleOpen = ref(false);
function schedule() {
    bulkScheduleOpen.value = true;
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
                hint: 'Header: email, work_date, clock_in, clock_out, mode. Tanggal YYYY-MM-DD; waktu YYYY-MM-DD HH:mm:ss. Maksimal 2000 baris. Semua sel berupa teks; mode wfo/wfh.',
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
        positions: [['name', 'Nama jabatan']],
        shifts: [
            ['name', 'Nama shift'],
            ['starts_at', 'Jam masuk', 'time'],
            ['ends_at', 'Jam keluar', 'time'],
            ['tolerance_minutes', 'Toleransi (menit)', 'number'],
            [
                'checkin_early_minutes',
                'Boleh masuk sebelum jadwal (menit)',
                'number',
            ],
            [
                'checkout_after_minutes',
                'Boleh pulang setelah jam masuk (menit)',
                'number',
            ],
            [
                'checkout_grace_minutes',
                'Batas pindai setelah jam pulang (menit)',
                'number',
            ],
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
            ['type', 'Jenis perangkat', 'select'],
            ['active', 'Perangkat aktif', 'checkbox'],
            ['token', 'Ganti kunci koneksi (opsional)', 'password'],
        ],
    };
    const options: Record<string, Field['options']> = {
        type: deviceTypes,
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
    roleForm.grants =
        role?.grants.map((g) => ({
            ...g,
            scope: companyWidePermissions.includes(g.permission)
                ? 'all'
                : g.scope,
        })) ?? [];
    roleEditor.value = true;
}
function toggleGrant(permission: string, checked: boolean) {
    roleForm.grants = roleForm.grants.filter(
        (g) => g.permission !== permission,
    );
    if (checked)
        roleForm.grants.push({
            permission,
            scope: companyWidePermissions.includes(permission) ? 'all' : 'self',
        });
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
const clock = useForm({ event: 'in', mode: 'wfh', work_date: '' });
const deviceTypes = [
    { value: 'fingerprint', label: 'Sidik jari' },
    { value: 'scanner', label: 'Pemindai QR — kamera laptop / alat scanner' },
    { value: 'phone_camera', label: 'Kamera HP' },
];
const deviceTypeName = (type: unknown) =>
    deviceTypes.find((d) => d.value === type)?.label || 'Sidik jari';
function registerDevice() {
    openForm('Daftarkan perangkat absensi', 'devices', [
        field('name', 'Nama perangkat'),
        field(
            'type',
            'Jenis perangkat',
            'select',
            true,
            deviceTypes,
            'scanner',
        ),
        {
            ...field(
                'token',
                'Kunci koneksi sidik jari (khusus alat fingerprint)',
                'password',
                false,
            ),
            hint: 'Untuk sidik jari: minimal 40 karakter acak. Kamera dan scanner tidak memerlukan kunci.',
        },
    ]);
}
onMounted(() => startNotifications(Number(page.props.auth.user.id)));
onBeforeUnmount(stopNotifications);
const accountQuery = ref('');
const filteredAccounts = computed(() =>
    props.accounts.filter((u) =>
        `${u.name} ${u.email}`
            .toLocaleLowerCase('id')
            .includes(accountQuery.value.toLocaleLowerCase('id')),
    ),
);
const deletion = ref<{ entity: string; id: number; name: string } | null>(null);
const deleting = ref(false);
function removeItem(entity: string, id: number, name: string) {
    deletion.value = { entity, id, name };
}
function confirmDeletion() {
    if (!deletion.value || deleting.value) return;
    const item = deletion.value;
    deleting.value = true;
    router.delete(`/manage/${item.entity}/${item.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deletion.value = null;
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}
</script>

<template>
    <Head :title="title" />
    <div class="alfar-app" :inert="actionDialogOpen || undefined">
        <button
            v-if="sidebarOpen && compactNavigation"
            class="navigation-backdrop"
            tabindex="-1"
            aria-label="Tutup navigasi"
            @click="closeNavigation"
        />
        <aside
            id="alfar-navigation"
            class="alfar-sidebar"
            :class="{ 'navigation-open': sidebarOpen }"
            @keydown.esc.stop.prevent="closeNavigation"
        >
            <button
                ref="navigationToggle"
                type="button"
                class="navigation-toggle"
                :aria-expanded="sidebarOpen"
                aria-controls="alfar-navigation-menu"
                :aria-label="
                    sidebarOpen ? 'Tutup menu navigasi' : 'Buka menu navigasi'
                "
                @click="sidebarOpen = !sidebarOpen"
            >
                <X v-if="sidebarOpen" :size="20" /><Menu
                    v-else
                    :size="20"
                /><span v-if="sidebarOpen">Tutup menu</span>
            </button>
            <Link href="/dashboard" class="alfar-brand" @click="closeNavigation"
                ><span class="brand-icon">a<span>•</span></span
                ><span
                    >alfar<span class="brand-sub"
                        >PEOPLE & PERFORMANCE</span
                    ></span
                ></Link
            >
            <div class="workspace-label">WORKSPACE <span>ALFAR</span></div>
            <nav id="alfar-navigation-menu" aria-label="Navigasi utama">
                <section
                    v-for="group in menuGroups"
                    :key="group.label"
                    class="sidebar-group"
                >
                    <h2 class="nav-caption">{{ group.label }}</h2>
                    <button
                        v-for="item in group.items"
                        :key="item.id"
                        :class="{ active: tab === item.id }"
                        :title="item.label"
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
                </section>
            </nav>
            <div class="sidebar-bottom">
                <div class="help-card">
                    <span class="mini-logo">✦</span
                    ><strong>Tumbuh bersama Alfar</strong>
                    <p>Target yang jelas.<br />Kontribusi yang berarti.</p>
                </div>
                <Link href="/password/change" @click="closeNavigation"
                    ><Settings2 :size="17" /> Keamanan akun</Link
                ><Link
                    href="/logout"
                    @click="closeNavigation"
                    method="post"
                    as="button"
                    ><LogOut :size="17" /> Keluar</Link
                >
            </div>
        </aside>
        <div
            class="alfar-main"
            :inert="sidebarOpen && compactNavigation ? true : undefined"
        >
            <header class="alfar-header">
                <div class="breadcrumb">
                    Workspace <span>/</span> <strong>{{ title }}</strong>
                </div>
                <div class="header-right">
                    <button
                        v-if="me && tab !== 'my-attendance'"
                        class="text-button personal-qr-shortcut"
                        @click="tab = 'my-attendance'"
                    >
                        <QrCode :size="17" /><span>QR absensi saya</span>
                    </button>
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
                        aria-label="Buka notifikasi saya"
                        @click="tab = 'notifications'"
                    >
                        <Bell :size="19" /><i v-if="unreadNotifications > 0" />
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
                        <AppSelect
                            :model-value="periodId"
                            :options="names(periods)"
                            aria-label="Periode laporan"
                            :placeholder="
                                periods.length
                                    ? 'Pilih periode'
                                    : 'Belum ada periode'
                            "
                            @change="changePeriod"
                        /><button
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

                <ActionCenter
                    v-if="tab === 'follow-ups' || tab === 'approvals'"
                    :view="tab === 'approvals' ? 'approvals' : 'attention'"
                    :period-id="periodId"
                    @dialog="actionDialogOpen = $event"
                    @open="openAction"
                />
                <NotificationCenter v-if="tab === 'notifications'" />
                <template v-if="tab === 'overview'">
                    <ActionCenter
                        v-if="canUseActionCenter"
                        view="attention"
                        :period-id="periodId"
                        compact
                        @dialog="actionDialogOpen = $event"
                        @open="openAction"
                        @expand="tab = 'follow-ups'"
                    />
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
                                    @click="tab = 'departments'"
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
                        <SearchInput
                            v-model="query"
                            placeholder="Cari nama, jabatan, atau divisi…"
                        />
                        <AppSelect
                            v-model="department"
                            :options="[
                                { value: '', label: 'Semua divisi' },
                                ...[
                                    ...new Set(
                                        employees.map((e) => e.department),
                                    ),
                                ].map((d) => ({ value: d, label: d })),
                            ]"
                            aria-label="Filter divisi karyawan"
                        /><span class="muted"
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
                                <span class="pill neutral">Karyawan</span
                                ><span class="employee-dot" />
                            </div>
                            <div
                                class="large-avatar"
                                :class="['avatar-' + (i % 4)]"
                            >
                                {{ initials(e.name) }}
                            </div>
                            <h3>{{ e.name }}</h3>
                            <p>{{ e.position }} · {{ e.department }}</p>
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
                            <button
                                v-if="has('organization.manage')"
                                class="text-button danger-button"
                                @click="removeItem('employees', e.id, e.name)"
                            >
                                Hapus karyawan
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

                <template
                    v-if="
                        [
                            'my-attendance',
                            'scanner',
                            'attendance',
                            'requests',
                            'schedules',
                        ].includes(tab)
                    "
                >
                    <section
                        v-if="tab === 'my-attendance' && !me"
                        class="panel missing-employee-profile"
                    >
                        <h2>Profil karyawan belum terhubung</h2>
                        <p>
                            QR pribadi tersedia setelah pengelola menghubungkan
                            akun ini ke profil karyawan melalui menu Data
                            karyawan.
                        </p>
                    </section>
                    <section
                        v-if="tab === 'my-attendance' && me"
                        class="attendance-personal"
                    >
                        <EmployeeQr
                            :name="
                                String(
                                    page.props.auth?.user?.name || 'Karyawan',
                                )
                            "
                            :allow-wfo="Boolean(me.allow_wfo)"
                        />
                        <form
                            class="panel clock-form"
                            @submit.prevent="
                                clock.post('/attendance/clock', {
                                    preserveScroll: true,
                                })
                            "
                        >
                            <h3>Saya WFH</h3>
                            <p class="wfh-note">
                                Akun dengan akses WFH dapat langsung absen. Jika
                                belum memiliki akses, ajukan persetujuan
                                terlebih dahulu.
                            </p>
                            <AppSelect
                                v-model="clock.event"
                                :options="[
                                    { value: 'in', label: 'Absen masuk WFH' },
                                    { value: 'out', label: 'Absen pulang WFH' },
                                ]"
                                aria-label="Jenis absensi WFH"
                            /><label
                                >Tanggal kerja<input
                                    v-model="clock.work_date"
                                    type="date"
                                    required /></label
                            ><button
                                class="primary"
                                :disabled="clock.processing"
                            >
                                Catat absensi WFH</button
                            ><button
                                class="secondary"
                                type="button"
                                @click="requestChange"
                            >
                                Ajukan WFH / koreksi / izin
                            </button>
                        </form>
                    </section>
                    <EmployeeSchedules
                        v-if="tab === 'schedules'"
                        :employees="employees"
                        :can-manage="has('organization.manage')"
                    />
                    <AttendanceReport
                        :initial-search="query"
                        :initial-date="attentionDate"
                        v-if="tab === 'attendance' && has('attendance.read')"
                    />
                    <template v-if="tab === 'scanner' && has('attendance.scan')"
                        ><div class="info-banner">
                            <QrCode :size="20" /><span
                                >Gunakan kamera laptop dahulu. Scanner QR dan
                                kamera HP dapat digunakan bersamaan dari
                                perangkat lain.</span
                            ><button
                                v-if="has('devices.manage')"
                                class="text-button"
                                @click="registerDevice"
                            >
                                Daftarkan perangkat
                            </button>
                        </div>
                        <AttendanceScanner :devices="devices"
                    /></template>
                    <section
                        v-if="tab === 'requests' && me"
                        class="panel attendance-actions"
                    >
                        <div>
                            <h2>Pengajuan saya</h2>
                            <p>
                                Ajukan WFH, cuti, izin, atau koreksi absensi
                                kepada atasan.
                            </p>
                        </div>
                        <button class="primary" @click="requestChange">
                            Buat pengajuan
                        </button>
                    </section>
                    <section
                        v-if="tab === 'schedules' && has('organization.manage')"
                        class="panel attendance-actions"
                    >
                        <div>
                            <h2>Jadwal kerja karyawan</h2>
                            <p>
                                Lengkapi divisi, jabatan, akun, profil karyawan,
                                dan shift sebelum menetapkan jadwal.
                            </p>
                        </div>
                        <button class="primary" @click="schedule">
                            Tetapkan jadwal
                        </button>
                    </section>
                    <section v-if="tab === 'my-attendance'" class="panel">
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
                    <section v-if="tab === 'requests'" class="panel">
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
                                    <tr
                                        v-for="r in requests"
                                        :key="r.id"
                                        :id="`request-${r.id}`"
                                    >
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
                    <section v-if="tab === 'my-attendance'" class="panel">
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
                                    <tr
                                        v-for="k in targets"
                                        :id="`target-${k.id}`"
                                        :key="k.id"
                                    >
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
                        <SearchInput
                            v-model="query"
                            placeholder="Cari laporan karyawan…"
                        />
                        <AppSelect
                            v-model="filter"
                            :options="[
                                { value: 'all', label: 'Semua laporan' },
                                { value: 'incomplete', label: 'Belum lengkap' },
                                { value: 'missed', label: 'KPI meleset' },
                                {
                                    value: 'unpublished',
                                    label: 'Belum diterbitkan',
                                },
                            ]"
                            aria-label="Filter laporan"
                        /><AppSelect
                            v-model="department"
                            :options="[
                                { value: '', label: 'Semua divisi' },
                                ...[
                                    ...new Set(
                                        employees.map((e) => e.department),
                                    ),
                                ].map((d) => ({ value: d, label: d })),
                            ]"
                            aria-label="Filter divisi laporan"
                        />
                    </div>
                    <AppSelect
                        v-model="shiftFilter"
                        :options="[
                            { value: '', label: 'Semua shift' },
                            ...shifts.map((s) => ({
                                value: s.id,
                                label: s.name,
                            })),
                        ]"
                        aria-label="Filter shift"
                        class="report-shift-filter"
                    />
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
                                    {{ currentReport.employee.position }} ·
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

                <template
                    v-if="
                        [
                            'departments',
                            'positions',
                            'shifts',
                            'metrics',
                            'periods',
                        ].includes(tab)
                    "
                >
                    <div class="info-banner">
                        <Building2 :size="20" /><span
                            >Ikuti urutan menu: divisi → jabatan → peran → akun
                            → karyawan → shift → jadwal → perangkat → indikator
                            → periode → target.</span
                        >
                    </div>
                    <div class="master-grid">
                        <section
                            v-if="
                                tab === 'departments' &&
                                has('organization.manage')
                            "
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
                            v-if="
                                tab === 'positions' &&
                                has('organization.manage')
                            "
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
                                            [field('name', 'Nama jabatan')],
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
                                    <strong>{{ p.name }}</strong>
                                </div>
                                <button
                                    class="text-button"
                                    @click="editMaster('positions', p)"
                                >
                                    Ubah</button
                                ><button
                                    class="text-button danger-button"
                                    @click="
                                        removeItem('positions', p.id, p.name)
                                    "
                                >
                                    Hapus
                                </button>
                            </div>
                            <p v-if="!positions.length" class="table-empty">
                                Belum ada jabatan.
                            </p>
                        </section>
                        <section
                            v-if="
                                tab === 'shifts' && has('organization.manage')
                            "
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
                                            field(
                                                'checkin_early_minutes',
                                                'Boleh masuk sebelum jadwal (menit)',
                                                'number',
                                                true,
                                                undefined,
                                                120,
                                            ),
                                            field(
                                                'checkout_after_minutes',
                                                'Boleh pulang setelah jam masuk (menit)',
                                                'number',
                                                true,
                                                undefined,
                                                240,
                                            ),
                                            field(
                                                'checkout_grace_minutes',
                                                'Batas pindai setelah jam pulang (menit)',
                                                'number',
                                                true,
                                                undefined,
                                                120,
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
                        <section
                            v-if="tab === 'periods' && has('kpi.manage')"
                            class="panel"
                        >
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
                    <section
                        v-if="tab === 'metrics' && has('kpi.manage')"
                        class="panel"
                    >
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
                </template>

                <template v-if="['accounts', 'roles'].includes(tab)">
                    <section
                        v-if="tab === 'accounts' && has('accounts.manage')"
                        class="panel"
                    >
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
                        <SearchInput
                            class="account-search"
                            v-model="accountQuery"
                            placeholder="Cari nama atau email akun…"
                        />
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nama</th>
                                        <th>Email</th>
                                        <th>Status</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="u in filteredAccounts"
                                        :key="u.id"
                                    >
                                        <td>{{ u.name }}</td>
                                        <td>{{ u.email }}</td>
                                        <td>
                                            <span
                                                class="pill"
                                                :class="
                                                    u.active
                                                        ? 'green'
                                                        : 'neutral'
                                                "
                                                >{{
                                                    u.active
                                                        ? 'Aktif'
                                                        : 'Nonaktif'
                                                }}</span
                                            >
                                        </td>
                                        <td>
                                            <div class="row-actions">
                                                <button
                                                    class="text-button"
                                                    @click="
                                                        editMaster(
                                                            'accounts',
                                                            u,
                                                        )
                                                    "
                                                >
                                                    Ubah</button
                                                ><button
                                                    class="text-button danger-button"
                                                    @click="
                                                        removeItem(
                                                            'accounts',
                                                            u.id,
                                                            u.name,
                                                        )
                                                    "
                                                >
                                                    Hapus
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <section
                        v-if="tab === 'roles' && has('roles.manage')"
                        class="panel"
                    >
                        <div class="panel-heading">
                            <div>
                                <h2>Peran dan cakupan akses</h2>
                                <p>
                                    Izin mengelola akun terpisah dari izin
                                    membaca laporan.
                                </p>
                            </div>
                            <button class="primary" @click="editRole()">
                                <Plus :size="16" />Buat peran
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
                                            .map((g) => scopeLabel(g.scope))
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
                            Buat peran sesuai kebutuhan organisasi.
                        </p>
                    </section>
                    <form
                        v-if="tab === 'roles' && has('roles.manage')"
                        class="panel role-assignment"
                        @submit.prevent="assignRoles"
                    >
                        <h2>Tetapkan peran ke akun</h2>
                        <AppSelect
                            v-model="assignUser"
                            :options="
                                accounts.map((u) => ({
                                    value: String(u.id),
                                    label: u.name,
                                    description: String(u.email),
                                }))
                            "
                            aria-label="Pilih akun untuk penetapan peran"
                            placeholder="Pilih akun…"
                            @change="loadRoles"
                        /><label v-for="role in roles" :key="role.id"
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
                            >Daftarkan sidik jari, pemindai QR (kamera laptop
                            atau scanner), atau kamera HP. Pilihan sidik jari
                            memerlukan koneksi sesuai merek alat.</span
                        >
                    </div>
                    <section class="panel">
                        <div class="panel-heading">
                            <div>
                                <h2>Perangkat absensi</h2>
                                <p>
                                    Untuk tahap awal, daftarkan kamera laptop
                                    sebagai Pemindai QR. Kunci koneksi hanya
                                    diperlukan untuk alat sidik jari.
                                </p>
                            </div>
                            <button class="primary" @click="registerDevice()">
                                <Plus :size="16" />Perangkat
                            </button>
                        </div>
                        <div
                            v-for="d in devices"
                            :key="d.id"
                            class="master-row"
                        >
                            <Fingerprint :size="20" />
                            <div>
                                <strong>{{ d.name }}</strong
                                ><small>{{ deviceTypeName(d.type) }}</small>
                            </div>
                            <span class="pill green">{{
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
                                                names(
                                                    devices.filter(
                                                        (d) =>
                                                            d.type ===
                                                            'fingerprint',
                                                    ),
                                                ),
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
        <Dialog
            :open="!!deletion"
            @update:open="
                (value) => {
                    if (!value && !deleting) deletion = null;
                }
            "
        >
            <DialogContent
                class="alfar-confirmation"
                :show-close-button="!deleting"
                @escape-key-down="
                    (event) => {
                        if (deleting) event.preventDefault();
                    }
                "
                @interact-outside="
                    (event) => {
                        if (deleting) event.preventDefault();
                    }
                "
            >
                <DialogHeader
                    ><DialogTitle>Hapus {{ deletion?.name }}?</DialogTitle
                    ><DialogDescription
                        >Data akan dihapus dari daftar aktif. Riwayat yang sudah
                        tercatat tetap disimpan.</DialogDescription
                    ></DialogHeader
                >
                <DialogFooter
                    ><Button
                        variant="outline"
                        :disabled="deleting"
                        @click="deletion = null"
                        >Batal</Button
                    ><Button
                        variant="destructive"
                        :disabled="deleting"
                        @click="confirmDeletion"
                        >{{ deleting ? 'Menghapus…' : 'Hapus' }}</Button
                    ></DialogFooter
                >
            </DialogContent>
        </Dialog>
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
                aria-label="Pengaturan peran"
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
                <h2>{{ roleForm.id ? 'Ubah peran' : 'Buat peran' }}</h2>
                <label
                    >Nama peran<input v-model="roleForm.name" required
                /></label>
                <p class="muted role-guidance">
                    Pilih kegiatan yang boleh dilakukan oleh pemegang peran ini,
                    lalu tentukan data siapa yang dapat diakses. Ikon
                    <span class="hint-example">!</span> menampilkan kode teknis
                    sebagai informasi tambahan.
                </p>
                <div class="grant-column-headings">
                    <span>Kegiatan yang diizinkan</span
                    ><span>Cakupan akses</span>
                </div>
                <div
                    v-for="permission in permissionCatalog"
                    :key="permission"
                    class="grant-row"
                >
                    <div class="grant-permission">
                        <label :for="`permission-${permission}`"
                            ><input
                                :id="`permission-${permission}`"
                                type="checkbox"
                                :checked="
                                    roleForm.grants.some(
                                        (g) => g.permission === permission,
                                    )
                                "
                                @change="
                                    toggleGrant(
                                        permission,
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            /><span>{{
                                permissionLabel(permission)
                            }}</span></label
                        ><TechnicalHint :code="permission" />
                    </div>
                    <div
                        v-for="grant in roleForm.grants.filter(
                            (g) => g.permission === permission,
                        )"
                        :key="grant.permission"
                        class="grant-scope"
                    >
                        <AppSelect
                            v-model="grant.scope"
                            :aria-label="`Cakupan akses untuk ${permissionLabel(permission)}`"
                            :disabled="
                                companyWidePermissions.includes(permission)
                            "
                            :options="
                                (companyWidePermissions.includes(permission)
                                    ? ['all']
                                    : scopeCatalog
                                ).map((scope) => ({
                                    value: scope,
                                    label: scopeLabel(scope),
                                }))
                            "
                        /><TechnicalHint :code="grant.scope" />
                        <small
                            v-if="companyWidePermissions.includes(permission)"
                            >Berlaku untuk seluruh perusahaan.</small
                        >
                    </div>
                    <span
                        v-if="
                            !roleForm.grants.some(
                                (g) => g.permission === permission,
                            )
                        "
                        class="grant-not-selected"
                        >Belum diizinkan</span
                    >
                </div>
                <button class="primary" :disabled="roleForm.processing">
                    Simpan peran dan hak akses
                </button>
            </form>
        </div>
        <div
            v-if="bulkScheduleOpen"
            class="modal-backdrop"
            @click.self="bulkScheduleOpen = false"
        >
            <section
                class="modal-card bulk-schedule-modal"
                role="dialog"
                aria-modal="true"
                aria-label="Jadwal kerja massal"
            >
                <button
                    type="button"
                    class="modal-close icon-button"
                    aria-label="Tutup jadwal massal"
                    @click="bulkScheduleOpen = false"
                >
                    <X :size="21" /></button
                ><BulkScheduleForm
                    :employees="employees"
                    :shifts="shifts"
                    @saved="bulkScheduleOpen = false"
                />
            </section>
        </div>
    </div>
</template>
<style src="./workspace.css"></style>
