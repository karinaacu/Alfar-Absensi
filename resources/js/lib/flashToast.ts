import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import type { FlashToast } from '@/types/ui';

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const data = (event as CustomEvent).detail?.flash?.toast as
            | FlashToast
            | undefined;
        if (data) toast[data.type](data.message);
    });
    let lastNoticeId: string | undefined;
    router.on('success', (event) => {
        const notice = event.detail.page.props.success_notice as
            | { id: string; message: string }
            | null
            | undefined;
        if (
            notice &&
            typeof notice.message === 'string' &&
            notice.id !== lastNoticeId
        ) {
            lastNoticeId = notice.id;
            toast.success(notice.message);
        }
    });
    router.on('error', (event) => {
        const messages = Object.values(event.detail.errors)
            .flat()
            .filter(Boolean);
        if (messages.length)
            toast.error(messages.join('\n'), { id: 'form-errors' });
    });
    router.on('httpException', (event) => {
        const status = event.detail.response.status;
        const message =
            status === 403
                ? 'Anda tidak memiliki akses untuk tindakan ini.'
                : status === 419
                  ? 'Sesi telah berakhir. Muat ulang halaman dan masuk kembali.'
                  : status === 429
                    ? 'Permintaan terlalu sering. Tunggu sebentar sebelum mencoba lagi.'
                    : 'Tindakan belum berhasil. Silakan coba lagi atau hubungi pengelola.';
        toast.error(message, { id: 'request-error' });
        event.preventDefault();
    });
    router.on('networkError', (event) => {
        toast.error('Koneksi terputus. Periksa jaringan lalu coba lagi.', {
            id: 'network-error',
        });
        event.preventDefault();
    });
}
