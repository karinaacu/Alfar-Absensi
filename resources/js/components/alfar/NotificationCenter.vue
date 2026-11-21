<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';

import { onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import {
    notificationVersion,
    refreshNotificationFeed,
    type EmployeeNotification,
} from '@/composables/useNotifications';
import { notificationRequest } from '@/lib/notificationApi';
const items = ref<EmployeeNotification[]>([]);
const current = ref(1);
const last = ref(1);
const total = ref(0);
const loading = ref(false);
const working = ref(false);
async function load() {
    loading.value = true;
    try {
        const data = await notificationRequest<{
            data: EmployeeNotification[];
            last_page: number;
            total: number;
        }>(`/notifications?page=${current.value}`);
        items.value = data.data;
        last.value = data.last_page;
        total.value = data.total;
        if (current.value > last.value) {
            current.value = last.value;
            await load();
        }
    } catch {
        /* The request helper shows a single toast. */
    } finally {
        loading.value = false;
    }
}
async function act(path: string, method: string) {
    if (working.value) return false;
    working.value = true;
    try {
        const data = await notificationRequest<{ message: string }>(
            path,
            method,
        );
        toast.success(data.message);
        await load();
        await refreshNotificationFeed(true);
        return true;
    } catch {
        /* The request helper shows a single toast. */
        return false;
    } finally {
        working.value = false;
    }
}
const deletion = ref<{
    path: string;
    title: string;
    description: string;
    label: string;
} | null>(null);
function deleteAll() {
    deletion.value = {
        path: '/notifications',
        title: 'Hapus semua notifikasi?',
        description:
            'Notifikasi pribadi dihapus. Data absensi dan riwayat audit tetap tersimpan.',
        label: 'Hapus semua',
    };
}
function deleteOne(item: EmployeeNotification) {
    deletion.value = {
        path: `/notifications/${item.id}`,
        title: 'Hapus notifikasi ini?',
        description: `Notifikasi “${item.title}” akan dihapus dari akun Anda. Data absensi dan riwayat audit tetap tersimpan.`,
        label: 'Hapus notifikasi',
    };
}
async function confirmDeletion() {
    if (!deletion.value || working.value) return;
    if (await act(deletion.value.path, 'DELETE')) deletion.value = null;
}
const formatDate = (value: string) =>
    new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(
        new Date(
            value.includes('T') ? value : value.replace(' ', 'T') + '+07:00',
        ),
    );
watch(notificationVersion, () => {
    void load();
});
onMounted(() => {
    void load();
});
</script>
<template>
    <section class="panel notification-center">
        <div class="panel-heading">
            <div>
                <h2>Notifikasi saya</h2>
                <p>
                    Aktivitas yang berhasil dicatat. Maksimal 200 notifikasi
                    terbaru per akun.
                </p>
            </div>
            <div class="notification-actions">
                <button
                    class="secondary"
                    :disabled="working || !items.length"
                    @click="act('/notifications/read-all', 'POST')"
                >
                    Tandai semua dibaca</button
                ><button
                    class="secondary danger-button"
                    :disabled="working || !items.length"
                    @click="deleteAll"
                >
                    Hapus semua
                </button>
            </div>
        </div>
        <div v-if="loading && !items.length" class="empty-state">
            Memuat notifikasi…
        </div>
        <div v-else-if="!items.length" class="empty-state">
            <h3>Belum ada notifikasi</h3>
            <p>Absensi dan aktivitas akun yang berhasil akan muncul di sini.</p>
        </div>
        <article
            v-for="item in items"
            :key="item.id"
            class="notification-item"
            :class="{ 'notification-unread': !item.read_at }"
        >
            <div>
                <span v-if="!item.read_at" class="pill purple-light">BARU</span>
                <h3>{{ item.title }}</h3>
                <p>{{ item.body }}</p>
                <time>{{ formatDate(item.created_at) }}</time>
            </div>
            <div class="notification-item-actions">
                <button
                    v-if="!item.read_at"
                    class="text-button"
                    :disabled="working"
                    @click="act(`/notifications/${item.id}/read`, 'PATCH')"
                >
                    Tandai dibaca</button
                ><button
                    class="text-button danger-button"
                    :disabled="working"
                    :aria-label="`Hapus notifikasi ${item.title}`"
                    @click="deleteOne(item)"
                >
                    Hapus
                </button>
            </div>
        </article>
        <div v-if="total" class="notification-pagination">
            <button
                class="secondary"
                :disabled="current <= 1 || loading"
                @click="
                    current--;
                    load();
                "
            >
                Sebelumnya</button
            ><span
                >Halaman {{ current }} dari {{ last }} ·
                {{ total }} notifikasi</span
            ><button
                class="secondary"
                :disabled="current >= last || loading"
                @click="
                    current++;
                    load();
                "
            >
                Berikutnya
            </button>
        </div>
    </section>
    <Dialog
        :open="!!deletion"
        @update:open="
            (value) => {
                if (!value && !working) deletion = null;
            }
        "
    >
        <DialogContent
            class="alfar-confirmation"
            :show-close-button="!working"
            @escape-key-down="
                (event) => {
                    if (working) event.preventDefault();
                }
            "
            @interact-outside="
                (event) => {
                    if (working) event.preventDefault();
                }
            "
        >
            <DialogHeader
                ><DialogTitle>{{ deletion?.title }}</DialogTitle
                ><DialogDescription>{{
                    deletion?.description
                }}</DialogDescription></DialogHeader
            >
            <DialogFooter
                ><Button
                    variant="outline"
                    :disabled="working"
                    @click="deletion = null"
                    >Batal</Button
                ><Button
                    variant="destructive"
                    :disabled="working"
                    @click="confirmDeletion"
                    >{{ working ? 'Menghapus…' : deletion?.label }}</Button
                ></DialogFooter
            >
        </DialogContent>
    </Dialog>
</template>
<style scoped>
.notification-actions,
.notification-item-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.notification-item {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    padding: 22px 25px;
    border-top: 1px solid #eef0f6;
}
.notification-unread {
    background: #faf9ff;
}
.notification-item h3 {
    color: #30304a;
    font-size: 14px;
    margin: 8px 0;
}
.notification-item p {
    font-size: 12px;
    color: #64748b;
    line-height: 1.8;
}
.notification-item time {
    display: block;
    margin-top: 9px;
    font-size: 10px;
    color: #8a90a6;
}
.notification-item-actions {
    align-content: center;
    flex-shrink: 0;
}
.notification-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 20px;
    font-size: 11px;
    color: #64748b;
}
@media (max-width: 700px) {
    .notification-center .panel-heading,
    .notification-item {
        flex-direction: column;
        align-items: stretch;
    }
    .notification-pagination {
        flex-wrap: wrap;
    }
    .notification-actions .secondary {
        font-size: 10px;
    }
}
</style>
