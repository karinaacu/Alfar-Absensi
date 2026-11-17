import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { notificationRequest } from '@/lib/notificationApi';
export interface EmployeeNotification {
    id: number;
    title: string;
    body: string;
    event: string;
    read_at: string | null;
    created_at: string;
}
export const unreadNotifications = ref(0);
export const notificationVersion = ref(0);
let user: number | null = null;
let cursor: number | undefined;
let timer: ReturnType<typeof setTimeout> | undefined;
let busy = false;
let generation = 0;
export async function refreshNotificationFeed(force = false): Promise<void> {
    if (busy || user === null) return;
    busy = true;
    const current = generation;
    try {
        const data = await notificationRequest<{
            items: EmployeeNotification[];
            cursor: number;
            unread: number;
        }>(
            '/notifications/feed' +
                (cursor === undefined ? '' : `?after=${cursor}`),
            'GET',
            false,
        );
        if (current !== generation) return;
        cursor = Math.max(cursor ?? 0, data.cursor);
        const changed = unreadNotifications.value !== data.unread;
        unreadNotifications.value = data.unread;
        for (const item of data.items)
            if (!item.read_at)
                toast.success(item.title, {
                    description: item.body,
                    id: `notification-${item.id}`,
                });
        if (changed || data.items.length || force) notificationVersion.value++;
    } catch {
        /* Background connectivity failures never create notification records or repeating error toasts. */
    } finally {
        busy = false;
    }
}
export function startNotifications(userId: number): void {
    stopNotifications();
    if (user !== userId) {
        user = userId;
        cursor = undefined;
        unreadNotifications.value = 0;
        notificationVersion.value++;
    }
    const current = generation;
    async function tick() {
        await refreshNotificationFeed();
        if (current === generation)
            timer = setTimeout(() => {
                void tick();
            }, 5000);
    }
    void tick();
}
export function stopNotifications(): void {
    generation++;
    if (timer) clearTimeout(timer);
    timer = undefined;
}
