<script setup lang="ts">
import AppDateTimePicker from './AppDateTimePicker.vue';
import { useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppSelect from '@/components/alfar/AppSelect.vue';
import { watch } from 'vue';
export interface Field {
    key: string;
    label: string;
    type?: string;
    required?: boolean;
    options?: { value: string | number; label: string }[];
    hint?: string;
    showWhen?: { key: string; value: string };
    requiredWhen?: { key: string; value: string };
    dateFrom?: string;
    value?: string | number | boolean | null;
}
const props = defineProps<{
    title: string;
    url: string;
    fields: Field[];
    submit?: string;
}>();
const emit = defineEmits<{ saved: [] }>();
type Value = string | number | boolean | File | null;
const form = useForm<Record<string, Value>>({});
function reset() {
    form.defaults(
        Object.fromEntries(
            props.fields.map((f) => [
                f.key,
                f.value ?? (f.type === 'checkbox' ? false : ''),
            ]),
        ),
    );
    form.reset();
}
watch(() => [props.url, props.fields], reset, { immediate: true });
function send() {
    for (const field of props.fields)
        if (
            (field.options ||
                ['date', 'time', 'datetime'].includes(field.type || '')) &&
            (!field.showWhen ||
                form[field.showWhen.key] === field.showWhen.value) &&
            (field.required ||
                (field.requiredWhen &&
                    form[field.requiredWhen.key] ===
                        field.requiredWhen.value)) &&
            (form[field.key] === null ||
                form[field.key] === '' ||
                form[field.key] === undefined)
        ) {
            form.setError(field.key, `${field.label} wajib dipilih.`);
            toast.error(`${field.label} wajib dipilih.`);
            return;
        }
    if (
        props.url === '/attendance/requests' &&
        form.type === 'correction' &&
        form.clock_in &&
        form.clock_out &&
        String(form.clock_out) <= String(form.clock_in)
    ) {
        const message =
            'Waktu keluar harus setelah waktu masuk. Pilih tanggal berikutnya jika shift melewati tengah malam.';
        form.setError('clock_out', message);
        toast.error(message);
        return;
    }
    form.transform((data) =>
        Object.fromEntries(
            Object.entries(data).map(([k, v]) => {
                const field = props.fields.find((f) => f.key === k);
                const hidden =
                    field?.showWhen &&
                    data[field.showWhen.key] !== field.showWhen.value;
                return [k, hidden || (v === '' && !field?.required) ? null : v];
            }),
        ),
    ).post(props.url, {
        preserveScroll: true,
        onSuccess: () => {
            reset();
            emit('saved');
        },
    });
}
function upload(event: Event, key: string) {
    form[key] = (event.target as HTMLInputElement).files?.[0] ?? null;
}
</script>
<template>
    <form class="alfar-form" @submit.prevent="send">
        <h3>{{ title }}</h3>
        <label
            v-for="field in fields.filter(
                (item) =>
                    item.key !== 'id' &&
                    item.type !== 'hidden' &&
                    (!item.showWhen ||
                        form[item.showWhen.key] === item.showWhen.value),
            )"
            :key="field.key"
        >
            <span
                >{{ field.label }}
                <small
                    v-if="
                        field.required ||
                        (field.requiredWhen &&
                            form[field.requiredWhen.key] ===
                                field.requiredWhen.value)
                    "
                    >*</small
                ></span
            >
            <AppSelect
                v-if="field.options"
                v-model="form[field.key]"
                :options="
                    field.required
                        ? field.options
                        : [
                              { value: '', label: 'Tidak dipilih' },
                              ...field.options,
                          ]
                "
                :aria-label="field.label"
            />
            <AppDateTimePicker
                v-else-if="
                    ['date', 'time', 'datetime'].includes(field.type || '')
                "
                :model-value="form[field.key]"
                @update:model-value="
                    (value) => {
                        form[field.key] = value;
                    }
                "
                :mode="field.type as 'date' | 'time' | 'datetime'"
                :label="field.label"
                :seconds="field.type === 'datetime'"
                :required="
                    field.required ||
                    !!(
                        field.requiredWhen &&
                        form[field.requiredWhen.key] ===
                            field.requiredWhen.value
                    )
                "
                :default-date="
                    field.dateFrom
                        ? String(form[field.dateFrom] || '')
                        : undefined
                "
            />
            <textarea
                v-else-if="field.type === 'textarea'"
                v-model="form[field.key] as string"
                :required="field.required"
                rows="3"
            />
            <input
                v-else-if="field.type === 'file'"
                type="file"
                :required="field.required"
                accept=".xlsx,.pdf,.jpg,.jpeg,.png"
                @change="upload($event, field.key)"
            />
            <input
                v-else-if="field.type === 'checkbox'"
                v-model="form[field.key]"
                type="checkbox"
            />
            <input
                v-else
                v-model="form[field.key]"
                :type="field.type || 'text'"
                :required="field.required"
                :step="field.type === 'number' ? 'any' : undefined"
                :placeholder="field.hint"
            />
            <small v-if="field.hint">{{ field.hint }}</small
            ><small v-if="form.errors[field.key]" class="error">{{
                form.errors[field.key]
            }}</small>
        </label>
        <button class="primary" :disabled="form.processing">
            {{ form.processing ? 'Menyimpan…' : submit || 'Simpan' }}
        </button>
    </form>
</template>
