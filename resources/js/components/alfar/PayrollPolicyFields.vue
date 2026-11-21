<script setup lang="ts">
import { Input } from '@/components/ui/input';
export interface PayPolicy {
    safe_minutes: string | number | null;
    warning_minutes: string | number | null;
    free_late_minutes: string | number;
    late_rate: string | number | null;
    late_cap: string | number | null;
    overtime_rate: string | number | null;
}
defineProps<{ form: PayPolicy }>();
const fields: { key: keyof PayPolicy; label: string; hint: string }[] = [
    {
        key: 'safe_minutes',
        label: 'Batas Safe Zone (menit)',
        hint: 'Akumulasi sampai batas ini masuk Safe Zone.',
    },
    {
        key: 'warning_minutes',
        label: 'Batas Warning Zone (menit)',
        hint: 'Di atas Safe sampai batas ini Warning; lebih dari ini Red Zone.',
    },
    {
        key: 'free_late_minutes',
        label: 'Menit bebas potongan per periode',
        hint: 'Dikurangi dari akumulasi setelah toleransi harian.',
    },
    {
        key: 'late_rate',
        label: 'Potongan per menit (Rp)',
        hint: 'Isi 0 jika tidak ada potongan. Kosong berarti belum ditetapkan.',
    },
    {
        key: 'late_cap',
        label: 'Batas nominal potongan (Rp)',
        hint: 'Opsional. Kosong berarti tanpa batas nominal.',
    },
    {
        key: 'overtime_rate',
        label: 'Tarif lembur per jam (Rp)',
        hint: 'Hanya menit disetujui. Dibulatkan ke rupiah terdekat.',
    },
];
</script>
<template>
    <div class="pay-policy">
        <label v-for="field in fields" :key="field.key"
            ><span>{{ field.label }}</span
            ><Input
                :aria-label="field.label"
                :model-value="form[field.key] ?? ''"
                @update:model-value="
                    (value) => {
                        form[field.key] = value;
                    }
                "
                type="number"
                min="0"
                step="1"
                :required="field.key === 'free_late_minutes'"
            /><small>{{ field.hint }}</small></label
        >
    </div>
</template>
<style scoped>
.pay-policy {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.pay-policy label {
    display: grid;
    gap: 6px;
    font-size: 12px;
}
.pay-policy small {
    font-size: 11px;
    color: #9298ac;
    line-height: 1.6;
}
@media (max-width: 600px) {
    .pay-policy {
        grid-template-columns: 1fr;
    }
}
</style>
