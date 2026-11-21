<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppSelect from './AppSelect.vue';
export interface PayComponent {
    name: string;
    type: 'earning' | 'deduction';
    amount: number | string;
}
const rows = defineModel<PayComponent[]>({ required: true });
</script>
<template>
    <div class="pay-components">
        <div v-for="(row, i) in rows" :key="i" class="pay-component">
            <Input
                v-model="row.name"
                aria-label="Nama komponen"
                placeholder="Tunjangan, bonus, iuran, atau potongan"
                maxlength="80"
                required
            /><AppSelect
                v-model="row.type"
                :options="[
                    { value: 'earning', label: 'Pendapatan' },
                    { value: 'deduction', label: 'Potongan' },
                ]"
                aria-label="Jenis komponen"
            /><Input
                v-model="row.amount"
                type="number"
                min="0"
                step="1"
                required
                aria-label="Nominal komponen (Rp)"
                placeholder="Nominal (Rp)"
            /><Button
                type="button"
                variant="outline"
                class="remove-component"
                @click="rows.splice(i, 1)"
                >Hapus</Button
            >
        </div>
        <Button
            type="button"
            variant="outline"
            :disabled="rows.length >= 30"
            @click="rows.push({ name: '', type: 'earning', amount: 0 })"
            >Tambah komponen</Button
        >
    </div>
</template>
<style scoped>
.pay-components {
    display: grid;
    gap: 12px;
}
.pay-component {
    display: grid;
    grid-template-columns: 2fr 1.2fr 1fr auto;
    gap: 8px;
    align-items: center;
}
.remove-component {
    color: #b73749;
}
@media (max-width: 640px) {
    .pay-component {
        grid-template-columns: 1fr 1fr;
        padding: 12px;
        border: 1px solid #eeecf6;
        border-radius: 12px;
    }
}
</style>
