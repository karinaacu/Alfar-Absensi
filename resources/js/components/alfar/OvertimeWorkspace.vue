<script setup lang="ts">
import { ref, onBeforeUnmount } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
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
import AppSelect from './AppSelect.vue';
interface Entry {
    id: number;
    name: string;
    work_date: string;
    starts_at: string;
    ends_at: string;
    break_minutes: number;
    minutes: number;
    reason: string;
    status: string;
    decision_note: string | null;
    can_decide: boolean;
}
defineProps<{ canSubmit: boolean }>();
const entries = ref<Entry[]>([]),
    page = ref(1),
    last = ref(1),
    loading = ref(false),
    creating = ref(false),
    selected = ref<Entry | null>(null);
const today = new Date().toLocaleDateString('en-CA', {
    timeZone: 'Asia/Jakarta',
});
const form = useForm({
    work_date: today,
    starts_at: '',
    ends_at: '',
    break_minutes: 0,
    reason: '',
});
const decision = useForm({ status: '', decision_note: '' });
let disposed = false;
async function load() {
    loading.value = true;
    try {
        const response = await fetch('/overtime?page=' + page.value, {
            headers: { Accept: 'application/json' },
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message);
        if (!disposed) {
            entries.value = data.data;
            last.value = data.last_page;
        }
    } catch (e) {
        toast.error(
            e instanceof Error ? e.message : 'Lembur belum dapat dimuat.',
        );
    } finally {
        loading.value = false;
    }
}
function submit() {
    form.transform((data) => ({
        ...data,
        starts_at: data.starts_at.replace('T', ' ') + ':00',
        ends_at: data.ends_at.replace('T', ' ') + ':00',
    })).post('/overtime', {
        preserveScroll: true,
        onSuccess: () => {
            creating.value = false;
            form.reset();
            void load();
        },
    });
}
function review(item: Entry) {
    selected.value = item;
    decision.reset();
    decision.clearErrors();
}
function decide() {
    if (selected.value)
        decision.post(`/overtime/${selected.value.id}/decide`, {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = null;
                void load();
            },
        });
}
const status: Record<string, string> = {
    pending: 'Menunggu persetujuan',
    approved: 'Disetujui',
    rejected: 'Ditolak',
};
void load();
onBeforeUnmount(() => {
    disposed = true;
});
</script>
<template>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Pengajuan dan riwayat lembur</h2>
                <p>
                    Jam lembur dibayar setelah ditinjau dan dimasukkan ke
                    periode penggajian.
                </p>
            </div>
            <Button v-if="canSubmit" @click="creating = true"
                >Ajukan lembur</Button
            >
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Karyawan</th>
                        <th>Tanggal / jam</th>
                        <th>Durasi</th>
                        <th>Alasan</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in entries" :key="item.id">
                        <td>{{ item.name }}</td>
                        <td>
                            {{ item.work_date
                            }}<small
                                >{{ item.starts_at }} –
                                {{ item.ends_at }}</small
                            >
                        </td>
                        <td>
                            {{ item.minutes }} menit<small
                                >Istirahat {{ item.break_minutes }} menit</small
                            >
                        </td>
                        <td>
                            {{ item.reason
                            }}<small v-if="item.decision_note"
                                >Catatan: {{ item.decision_note }}</small
                            >
                        </td>
                        <td>{{ status[item.status] }}</td>
                        <td>
                            <Button
                                v-if="item.can_decide"
                                variant="outline"
                                @click="review(item)"
                                >Tinjau lembur</Button
                            >
                        </td>
                    </tr>
                    <tr v-if="!entries.length">
                        <td colspan="6">
                            {{
                                loading
                                    ? 'Memuat…'
                                    : 'Belum ada pengajuan lembur.'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="ot-pagination">
            <Button
                variant="outline"
                :disabled="page <= 1 || loading"
                @click="
                    page--;
                    load();
                "
                >Sebelumnya</Button
            ><span>{{ page }} / {{ last }}</span
            ><Button
                variant="outline"
                :disabled="page >= last || loading"
                @click="
                    page++;
                    load();
                "
                >Berikutnya</Button
            >
        </div>
    </section>
    <Dialog v-model:open="creating"
        ><DialogContent class="alfar-confirmation"
            ><DialogHeader
                ><DialogTitle>Ajukan lembur</DialogTitle
                ><DialogDescription
                    >Isi waktu lembur sebenarnya dan kurangi istirahat. Jam
                    kerja biasa bukan lembur. Atasan memeriksa pengajuan sebelum
                    dibayarkan.</DialogDescription
                ></DialogHeader
            >
            <form class="ot-form" @submit.prevent="submit">
                <label
                    >Tanggal kerja<Input
                        v-model="form.work_date"
                        type="date"
                        required /></label
                ><label
                    >Mulai lembur<Input
                        v-model="form.starts_at"
                        type="datetime-local"
                        required /></label
                ><label
                    >Selesai lembur<Input
                        v-model="form.ends_at"
                        type="datetime-local"
                        required /></label
                ><label
                    >Istirahat (menit)<Input
                        v-model="form.break_minutes"
                        type="number"
                        min="0"
                        required /></label
                ><label
                    >Alasan lembur<Input
                        v-model="form.reason"
                        required
                        maxlength="2000" /></label
                ><DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        @click="creating = false"
                        >Batal</Button
                    ><Button :disabled="form.processing"
                        >Kirim pengajuan lembur</Button
                    ></DialogFooter
                >
            </form></DialogContent
        ></Dialog
    ><Dialog
        :open="!!selected"
        @update:open="
            (value) => {
                if (!value && !decision.processing) selected = null;
            }
        "
        ><DialogContent class="alfar-confirmation"
            ><DialogHeader
                ><DialogTitle>Tinjau lembur · {{ selected?.name }}</DialogTitle
                ><DialogDescription
                    >{{ selected?.starts_at }} – {{ selected?.ends_at }} ·
                    {{ selected?.minutes }} menit. Periksa jam kerja dan alasan
                    sebelum menyetujui.</DialogDescription
                ></DialogHeader
            >
            <p>{{ selected?.reason }}</p>
            <form class="ot-form" @submit.prevent="decide">
                <label
                    >Keputusan<AppSelect
                        v-model="decision.status"
                        :options="[
                            { value: 'approved', label: 'Setujui lembur' },
                            { value: 'rejected', label: 'Tolak pengajuan' },
                        ]"
                        aria-label="Keputusan lembur" /></label
                ><label
                    >Catatan peninjauan<Input
                        v-model="decision.decision_note"
                        required
                        maxlength="2000" /></label
                ><DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        @click="selected = null"
                        >Batal</Button
                    ><Button :disabled="decision.processing || !decision.status"
                        >Simpan keputusan lembur</Button
                    ></DialogFooter
                >
            </form></DialogContent
        ></Dialog
    >
</template>
<style scoped>
td small {
    display: block;
    color: #8d93a8;
    margin-top: 5px;
    font-size: 11px;
}
.ot-pagination {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 14px;
    padding: 20px;
}
.ot-form {
    display: grid;
    gap: 16px;
}
.ot-form label {
    display: grid;
    gap: 7px;
    font-size: 12px;
}
</style>
