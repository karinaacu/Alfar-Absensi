<script setup lang="ts">
import { ref, watch, nextTick, onBeforeUnmount } from 'vue';
import { ClipboardCheck, Clock3, ArrowRight, RefreshCw, X } from '@lucide/vue';
import { toast } from 'vue-sonner';
import SearchInput from './SearchInput.vue';
import AppSelect from './AppSelect.vue';
import DataForm, { type Field } from './DataForm.vue';
const props = defineProps<{
    view: 'attention' | 'approvals';
    periodId: number;
    compact?: boolean;
}>();
const emit = defineEmits<{
    open: [
        item: {
            destination: string;
            name: string;
            date: string;
            entity_id: number;
            employee_id?: number | null;
        },
    ];
    expand: [];
    dialog: [open: boolean];
}>();
interface Item {
    id: string;
    entity_id: number;
    employee_id?: number | null;
    kind: string;
    name: string;
    date: string;
    title: string;
    detail: string;
    can_decide: boolean;
    destination: string;
    clock_in?: string;
    clock_out?: string;
    target?: number;
    actual?: number;
    unit?: string;
    evidence_id?: number;
}
const today = new Date().toLocaleDateString('en-CA', {
    timeZone: 'Asia/Jakarta',
});
const start = new Date(today + 'T12:00:00');
start.setDate(start.getDate() - 29);
const from = ref(start.toLocaleDateString('en-CA')),
    to = ref(today),
    search = ref(''),
    kind = ref('all'),
    page = ref(1),
    last = ref(1),
    total = ref(0),
    items = ref<Item[]>([]),
    counts = ref<Record<string, number>>({}),
    busy = ref(false),
    selected = ref<Item | null>(null),
    showForm = ref(false);
const labels: Record<string, string> = {
    missing_in: 'Belum masuk',
    missing_out: 'Belum pulang',
    request: 'Pengajuan',
    overtime: 'Lembur',
    kpi_missing: 'KPI belum lengkap',
    kpi_pending: 'Validasi KPI',
    kpi_rejected: 'KPI perlu perbaikan',
};
const detailDialog = ref<HTMLElement | null>(null);
let previousFocus: HTMLElement | null = null;
watch(selected, (item) => {
    emit('dialog', !!item);
    if (item) void nextTick(() => detailDialog.value?.focus());
});
let request: AbortController | undefined;
let timer: ReturnType<typeof setTimeout>;
let generation = 0;
async function load() {
    request?.abort();
    request = new AbortController();
    const current = ++generation;
    busy.value = true;
    try {
        const params = new URLSearchParams({
            view: props.view,
            from: from.value,
            to: to.value,
            search: search.value,
            kind: kind.value,
            page: String(page.value),
        });
        if (props.periodId) params.set('period', String(props.periodId));
        const response = await fetch('/action-center?' + params, {
            headers: { Accept: 'application/json' },
            signal: request.signal,
        });
        const data = await response.json();
        if (current !== generation) return;
        if (!response.ok)
            throw new Error(
                Object.values(data.errors || {})
                    .flat()
                    .join(' ') || data.message,
            );
        items.value = data.data;
        counts.value = data.counts;
        total.value = data.total;
        last.value = data.last_page;
    } catch (error) {
        if (
            error instanceof Error &&
            error.name !== 'AbortError' &&
            current === generation
        ) {
            items.value = [];
            toast.error(error.message);
        }
    } finally {
        if (current === generation) busy.value = false;
    }
}
watch([search, kind, from, to, () => props.periodId, () => props.view], () => {
    clearTimeout(timer);
    if (page.value !== 1) page.value = 1;
    else timer = setTimeout(load, 300);
});
watch(page, load);
void load();
onBeforeUnmount(() => {
    emit('dialog', false);
    request?.abort();
    clearTimeout(timer);
});
function fields(item: Item): Field[] {
    return [
        ...(!['request', 'overtime'].includes(item.kind)
            ? [
                  {
                      key: 'expected_status',
                      label: 'Status sebelumnya',
                      type: 'hidden',
                      value: 'pending',
                  },
              ]
            : []),
        {
            key: 'status',
            label: 'Keputusan',
            required: true,
            options: ['request', 'overtime'].includes(item.kind)
                ? [
                      { value: 'approved', label: 'Setujui' },
                      { value: 'rejected', label: 'Tolak' },
                  ]
                : [
                      { value: 'validated', label: 'Validasi realisasi' },
                      {
                          value: 'rejected',
                          label: 'Kembalikan untuk perbaikan',
                      },
                  ],
        },
        {
            key: ['request', 'overtime'].includes(item.kind)
                ? 'decision_note'
                : 'reason',
            label: 'Catatan keputusan',
            type: 'textarea',
            required: true,
        },
    ];
}
function saved() {
    selected.value = null;
    showForm.value = false;
    void load();
}
function choose(item: Item) {
    previousFocus =
        document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;
    selected.value = item;
    showForm.value = false;
}
function close() {
    selected.value = null;
    showForm.value = false;
    void nextTick(() => previousFocus?.focus());
}
</script>
<template>
    <section class="panel action-center">
        <div class="panel-heading">
            <div>
                <h2>
                    {{
                        view === 'approvals'
                            ? 'Pusat persetujuan'
                            : 'Perlu ditindaklanjuti'
                    }}
                </h2>
                <p>
                    {{
                        view === 'approvals'
                            ? 'Periksa pengajuan dan bukti realisasi sebelum mengambil keputusan.'
                            : 'Temukan data kehadiran dan kinerja yang perlu diperiksa.'
                    }}
                </p>
            </div>
            <button class="text-button" :disabled="busy" @click="load">
                <RefreshCw :size="15" /> Perbarui
            </button>
        </div>
        <div class="action-counts">
            <button
                v-for="(label, key) in labels"
                v-show="
                    (view === 'attention' ||
                        ['request', 'kpi_pending', 'overtime'].includes(key)) &&
                    (!compact || counts[key])
                "
                :key="key"
                :class="['action-count', { active: kind === key }]"
                @click="kind = kind === key ? 'all' : key"
            >
                <span>{{ label }}</span
                ><strong>{{ counts[key] || 0 }}</strong>
            </button>
        </div>
        <div v-if="!compact" class="action-filters">
            <SearchInput
                v-model="search"
                placeholder="Cari karyawan atau pengajuan…"
            /><AppSelect
                v-model="kind"
                :options="[
                    { value: 'all', label: 'Semua kategori' },
                    ...Object.entries(labels)
                        .filter(
                            ([key]) =>
                                view === 'attention' ||
                                ['request', 'kpi_pending', 'overtime'].includes(
                                    key,
                                ),
                        )
                        .map(([value, label]) => ({ value, label })),
                ]"
                aria-label="Kategori tindak lanjut"
            /><template v-if="view === 'attention'"
                ><label
                    >Kehadiran dari<input
                        v-model="from"
                        type="date"
                        :max="to" /></label
                ><label
                    >Sampai<input v-model="to" type="date" :min="from" /></label
            ></template>
        </div>
        <p class="action-caption">
            {{
                view === 'attention'
                    ? `Kehadiran ${from} – ${to}. Pengajuan menampilkan seluruh antrean aktif; KPI mengikuti periode terpilih.`
                    : 'Hanya pengajuan dan realisasi yang menjadi kewenangan Anda. Pengajuan sendiri ditangani atasan.'
            }}
        </p>
        <div :aria-busy="busy" class="action-list">
            <article
                v-for="item in compact ? items.slice(0, 5) : items"
                :key="item.id"
                class="action-row"
            >
                <div class="action-icon">
                    <Clock3
                        v-if="item.kind.startsWith('missing')"
                        :size="18"
                    /><ClipboardCheck v-else :size="18" />
                </div>
                <div class="action-description">
                    <strong>{{ item.name }}</strong>
                    <p>{{ item.title }}</p>
                    <small>{{ item.date }}</small>
                </div>
                <button class="secondary" @click="choose(item)">
                    Tinjau <ArrowRight :size="14" />
                </button>
            </article>
            <div v-if="!items.length" class="action-empty">
                <ClipboardCheck :size="26" /><strong>{{
                    busy ? 'Memuat…' : 'Tidak ada antrean pada pilihan ini'
                }}</strong
                ><span
                    >Data akan muncul sesuai jadwal, periode, dan cakupan akses
                    Anda.</span
                >
            </div>
        </div>
        <div class="action-footer">
            <span>{{ total }} perlu diperiksa</span
            ><button v-if="compact" class="text-button" @click="emit('expand')">
                Buka seluruh tindak lanjut <ArrowRight :size="15" /></button
            ><template v-else
                ><button
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
                </button></template
            >
        </div>
    </section>
    <Teleport to="body"
        ><div
            v-if="selected"
            class="action-overlay"
            @click.self="close"
            @keydown.esc="close"
        >
            <section
                ref="detailDialog"
                class="action-dialog"
                role="dialog"
                aria-modal="true"
                aria-label="Detail tindak lanjut"
                tabindex="-1"
            >
                <button
                    class="action-close"
                    aria-label="Tutup detail tindak lanjut"
                    @click="close"
                >
                    <X :size="20" /></button
                ><span class="action-badge">{{ labels[selected.kind] }}</span>
                <h2>{{ selected.name }}</h2>
                <h3>{{ selected.title }}</h3>
                <p class="detail-date">Tanggal: {{ selected.date }}</p>
                <p class="action-reason">{{ selected.detail }}</p>
                <p v-if="selected.clock_in || selected.clock_out">
                    Koreksi yang diajukan: masuk
                    {{ selected.clock_in || '—' }} · pulang
                    {{ selected.clock_out || '—' }}
                </p>
                <p v-if="selected.target !== undefined">
                    Target {{ selected.target }} {{ selected.unit }} · Realisasi
                    {{ selected.actual ?? 'Belum diisi' }} {{ selected.unit }}
                </p>
                <a
                    v-if="selected.evidence_id"
                    :href="`/evidence/${selected.evidence_id}`"
                    class="evidence-link"
                    target="_blank"
                    rel="noopener"
                    >Buka bukti realisasi</a
                ><button
                    v-if="!showForm && selected.can_decide"
                    class="primary"
                    @click="showForm = true"
                >
                    Beri keputusan</button
                ><button
                    v-else-if="!selected.can_decide"
                    class="secondary"
                    @click="
                        emit('open', selected);
                        close();
                    "
                >
                    Buka halaman terkait</button
                ><DataForm
                    v-if="showForm"
                    title="Keputusan peninjauan"
                    :url="
                        selected.kind === 'overtime'
                            ? `/overtime/${selected.entity_id}/decide`
                            : selected.kind === 'request'
                              ? `/attendance/requests/${selected.entity_id}/decide`
                              : `/targets/${selected.entity_id}/validate`
                    "
                    :fields="fields(selected)"
                    submit="Simpan keputusan"
                    @saved="saved"
                />
            </section></div
    ></Teleport>
</template>
<style scoped>
.action-counts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(135px, 1fr));
    gap: 10px;
    padding: 0 24px 18px;
}
.action-count {
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: start;
    border: 1px solid #ece9fb;
    border-radius: 12px;
    padding: 13px;
    background: #f9f8ff;
    color: #726a94;
    font-size: 11px;
    text-align: left;
    cursor: pointer;
}
.action-count strong {
    font-size: 22px;
    color: #393052;
}
.action-count.active {
    border-color: #7664e8;
    background: #efebff;
}
.action-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    padding: 0 24px 16px;
    align-items: end;
}
.action-filters label {
    display: flex;
    flex-direction: column;
    gap: 5px;
    font-size: 11px;
    color: #7a8096;
}
.action-filters input {
    padding: 10px;
    border: 1px solid #e6e7f2;
    border-radius: 10px;
    background: white;
    color: #33364d;
}
.action-caption {
    font-size: 11px;
    color: #8b90a5;
    padding: 0 24px 14px;
}
.action-row {
    display: flex;
    gap: 14px;
    align-items: center;
    padding: 18px 24px;
    border-top: 1px solid #f0f0f7;
}
.action-icon {
    background: #fff3df;
    color: #a57b32;
    padding: 11px;
    border-radius: 12px;
    display: flex;
}
.action-description {
    flex: 1;
    min-width: 0;
}
.action-description strong {
    font-size: 13px;
}
.action-description p {
    font-size: 12px;
    color: #727991;
    margin: 3px 0;
}
.action-description small {
    font-size: 10px;
    color: #969bad;
}
.action-row .secondary {
    display: flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
}
.action-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 30px;
    color: #8d92a8;
    font-size: 12px;
}
.action-footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    padding: 18px 24px;
    border-top: 1px solid #f0f0f7;
    font-size: 12px;
    color: #858ba2;
}
.action-footer > span:first-child {
    margin-right: auto;
}
.action-overlay {
    position: fixed;
    inset: 0;
    z-index: 150;
    background: #25233e66;
    backdrop-filter: blur(3px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 18px;
}
.action-dialog {
    position: relative;
    background: white;
    border-radius: 22px;
    padding: 30px;
    width: 100%;
    max-width: 560px;
    max-height: 90dvh;
    overflow: auto;
    color: #292c44;
    font-family: 'Instrument Sans', sans-serif;
    box-shadow: 0 20px 80px #25233e33;
}
.action-dialog h2 {
    font-size: 22px;
    margin: 14px 0 8px;
}
.action-dialog h3 {
    font-size: 15px;
    margin: 0 0 8px;
}
.action-dialog p {
    font-size: 13px;
    line-height: 1.7;
}
.action-close {
    position: absolute;
    top: 16px;
    right: 16px;
    border: 0;
    background: #f4f3fa;
    border-radius: 9px;
    padding: 6px;
    color: #817a9a;
    cursor: pointer;
}
.action-badge {
    background: #f0ecff;
    color: #725acb;
    padding: 5px 10px;
    border-radius: 7px;
    font-size: 11px;
}
.action-reason {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    background: #f8f7fc;
    padding: 14px;
    border-radius: 12px;
}
.detail-date {
    color: #9598ab;
}
.evidence-link {
    display: block;
    margin: 16px 0;
    color: #5946dc;
    font-size: 13px;
}
.action-dialog :deep(.primary),
.action-dialog :deep(.secondary) {
    padding: 10px 16px;
    border-radius: 10px;
    cursor: pointer;
}
.action-dialog :deep(.primary) {
    background: #5946e8;
    border: 0;
    color: white;
}
.action-dialog :deep(.secondary) {
    border: 1px solid #e6e7f2;
    background: white;
    color: #645e7f;
}
.action-dialog :deep(.alfar-form) {
    margin-top: 18px;
}
.action-dialog :deep(.alfar-form label) {
    display: flex;
    flex-direction: column;
    gap: 7px;
    margin: 14px 0;
    font-size: 12px;
}
.action-dialog :deep(textarea) {
    border: 1px solid #e2e2ee;
    border-radius: 10px;
    padding: 10px;
    color: #30334c;
}
.action-dialog :deep(.error) {
    color: #bf3b47;
}
@media (max-width: 600px) {
    .action-counts {
        padding: 0 16px 16px;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .action-filters {
        padding: 0 16px 14px;
    }
    .action-filters > * {
        min-width: 0;
        max-width: 100%;
    }
    .action-row {
        padding: 16px;
        gap: 10px;
        flex-wrap: wrap;
    }
    .action-row .secondary {
        margin-left: auto;
    }
    .action-caption {
        padding: 0 16px 14px;
    }
    .action-dialog {
        padding: 24px 18px;
    }
}
</style>
