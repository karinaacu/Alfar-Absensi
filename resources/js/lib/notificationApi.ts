import { toast } from 'vue-sonner';
export async function notificationRequest<T>(
    path: string,
    method = 'GET',
    showError = true,
): Promise<T> {
    try {
        const csrf = decodeURIComponent(
            document.cookie
                .split('; ')
                .find((c) => c.startsWith('XSRF-TOKEN='))
                ?.split('=')
                .slice(1)
                .join('=') || '',
        );
        const response = await fetch(path, {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf },
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data) {
            const message =
                response.status === 401 || response.status === 419
                    ? 'Sesi telah berakhir. Silakan masuk kembali.'
                    : response.status === 403
                      ? 'Anda tidak memiliki akses ke notifikasi ini.'
                      : response.status >= 500
                        ? 'Notifikasi belum dapat diproses. Silakan coba lagi.'
                        : data?.message || 'Notifikasi belum dapat diproses.';
            throw new Error(message);
        }
        return data as T;
    } catch (e) {
        const message =
            e instanceof TypeError
                ? 'Koneksi terputus. Periksa jaringan lalu coba lagi.'
                : e instanceof Error
                  ? e.message
                  : 'Notifikasi belum dapat diproses.';
        if (showError) toast.error(message);
        throw e;
    }
}
