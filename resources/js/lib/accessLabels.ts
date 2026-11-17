export const permissionLabels: Record<string, string> = {
    'organization.manage': 'Kelola divisi, jabatan, dan jadwal kerja',
    'accounts.manage': 'Kelola akun karyawan',
    'roles.manage': 'Kelola peran dan hak akses',
    'attendance.read': 'Lihat catatan kehadiran',
    'attendance.manage': 'Impor data absensi',
    'attendance.approve': 'Setujui pengajuan WFH, izin, dan koreksi absensi',
    'qr.display': 'Tampilkan kode QR absensi kantor',
    'devices.manage': 'Kelola perangkat sidik jari',
    'kpi.manage': 'Atur periode, indikator, dan target kinerja',
    'kpi.submit': 'Isi realisasi kinerja',
    'kpi.validate': 'Validasi realisasi kinerja',
    'reviews.publish': 'Terbitkan dan revisi penilaian kinerja',
    'reports.read': 'Lihat laporan kinerja',
};
export const scopeLabels: Record<string, string> = {
    self: 'Diri sendiri',
    reports: 'Bawahan langsung',
    department: 'Divisi yang sama',
    all: 'Seluruh perusahaan',
};
export const companyWidePermissions = [
    'organization.manage',
    'accounts.manage',
    'roles.manage',
    'qr.display',
    'devices.manage',
    'kpi.manage',
];
export const permissionLabel = (code: string) =>
    permissionLabels[code] || 'Izin tambahan';
export const scopeLabel = (code: string) =>
    scopeLabels[code] || 'Cakupan lainnya';
