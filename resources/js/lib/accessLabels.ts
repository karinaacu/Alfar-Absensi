export const permissionLabels: Record<string, string> = {
    'organization.manage': 'Kelola divisi, jabatan, dan jadwal kerja',
    'accounts.manage': 'Kelola akun karyawan',
    'roles.manage': 'Kelola peran dan hak akses',
    'attendance.read': 'Lihat catatan kehadiran',
    'attendance.manage': 'Impor data absensi',
    'attendance.approve': 'Setujui pengajuan WFH, izin, dan koreksi absensi',
    'attendance.scan': 'Pindai QR karyawan untuk mencatat absensi',
    'devices.manage': 'Kelola perangkat sidik jari',
    'kpi.manage': 'Atur periode, indikator, dan target kinerja',
    'kpi.submit': 'Isi realisasi kinerja',
    'kpi.validate': 'Validasi realisasi kinerja',
    'reviews.publish': 'Terbitkan dan revisi penilaian kinerja',
    'payroll.manage': 'Kelola gaji dan draf penggajian',
    'payroll.publish': 'Periksa dan terbitkan slip gaji',
    'payroll.read': 'Lihat slip gaji yang sudah diterbitkan',
    'overtime.approve': 'Tinjau dan setujui lembur',
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
    'devices.manage',
    'kpi.manage',
    'payroll.manage',
    'payroll.publish',
];
export const permissionLabel = (code: string) =>
    permissionLabels[code] || 'Izin tambahan';
export const scopeLabel = (code: string) =>
    scopeLabels[code] || 'Cakupan lainnya';
