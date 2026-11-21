<script setup lang="ts">
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
            field.options &&
            field.required &&
            (form[field.key] === null ||
                form[field.key] === '' ||
                form[field.key] === undefined)
        ) {
            form.setError(field.key, `${field.label} wajib dipilih.`);
            toast.error(`${field.label} wajib dipilih.`);
            return;
        }
    form.transform((data) =>
        Object.fromEntries(
            Object.entries(data).map(([k, v]) => [
                k,
                v === '' && !props.fields.find((f) => f.key === k)?.required
                    ? null
                    : v,
            ]),
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
                (item) => item.key !== 'id' && item.type !== 'hidden',
            )"
            :key="field.key"
        >
            <span
                >{{ field.label }} <small v-if="field.required">*</small></span
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
