<script setup lang="ts" generic="T">
import { Check, ChevronDown, Search, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, useId } from 'vue';
defineOptions({ inheritAttrs: false });
interface SelectOption {
    value: string | number;
    label: string;
    description?: string;
    disabled?: boolean;
}
const props = withDefaults(
    defineProps<{
        modelValue?: T;
        options: SelectOption[];
        multiple?: boolean;
        disabled?: boolean;
        placeholder?: string;
        searchable?: boolean;
    }>(),
    { placeholder: 'Pilih…', searchable: true },
);
const emit = defineEmits<{
    'update:modelValue': [value: T];
    change: [value: T];
}>();
const id = useId();
const open = ref(false);
const query = ref('');
const active = ref(0);
const trigger = ref<HTMLButtonElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);
const style = ref<Record<string, string>>({});
const selected = computed(() =>
    Array.isArray(props.modelValue)
        ? props.modelValue
        : props.modelValue === null || props.modelValue === undefined
          ? []
          : [props.modelValue],
);
const isSelected = (value: string | number) =>
    selected.value.some((v) => String(v) === String(value));
const filtered = computed(() =>
    props.options.filter((o) =>
        `${o.label} ${o.description || ''}`
            .toLocaleLowerCase('id')
            .includes(query.value.toLocaleLowerCase('id')),
    ),
);
const caption = computed(() => {
    if (props.multiple)
        return selected.value.length > 1
            ? `${selected.value.length} karyawan dipilih`
            : props.options.find((o) => isSelected(o.value))?.label ||
                  props.placeholder;
    return (
        props.options.find((o) => isSelected(o.value))?.label ||
        props.placeholder
    );
});
function update(value: string | number | (string | number)[] | null) {
    emit('update:modelValue', value as T);
    emit('change', value as T);
}
function place() {
    if (!trigger.value) return;
    const r = trigger.value.getBoundingClientRect();
    const width = Math.min(Math.max(r.width, 240), innerWidth - 24);
    const below = innerHeight - r.bottom - 12;
    const above = r.top - 12;
    const height = Math.min(360, Math.max(150, below >= 200 ? below : above));
    const estimated = Math.min(
        height,
        filtered.value.length * 44 + (props.multiple ? 140 : 72),
    );
    const top =
        below >= 200 || below >= above
            ? r.bottom + 6
            : Math.max(8, r.top - estimated - 6);
    style.value = {
        position: 'fixed',
        top: `${top}px`,
        left: `${Math.max(12, Math.min(r.left, innerWidth - width - 12))}px`,
        width: `${width}px`,
        maxHeight: `${height}px`,
    };
}
function close(focus = false) {
    open.value = false;
    window.removeEventListener('resize', place);
    window.removeEventListener('scroll', place, true);
    document.removeEventListener('pointerdown', outside);
    if (focus) trigger.value?.focus();
}
function outside(e: PointerEvent) {
    if (
        !panel.value?.contains(e.target as Node) &&
        !trigger.value?.contains(e.target as Node)
    )
        close();
}
async function show() {
    if (props.disabled) return;
    if (open.value) {
        close();
        return;
    }
    query.value = '';
    active.value = Math.max(
        0,
        props.options.findIndex((o) => isSelected(o.value)),
    );
    open.value = true;
    place();
    await nextTick();
    (searchInput.value || panel.value)?.focus();
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    document.addEventListener('pointerdown', outside);
}
function choose(option: SelectOption) {
    if (option.disabled) return;
    if (props.multiple) {
        const values = props.options
            .filter((o) => isSelected(o.value))
            .map((o) => o.value);
        update(
            isSelected(option.value)
                ? values.filter((v) => String(v) !== String(option.value))
                : [...values, option.value],
        );
    } else {
        update(option.value);
        close(true);
    }
}
function chooseAll(filteredOnly = false) {
    const options = (filteredOnly ? filtered.value : props.options).filter(
        (o) => !o.disabled,
    );
    const values = props.options
        .filter((o) => isSelected(o.value))
        .map((o) => o.value);
    update([
        ...new Map(
            [...values, ...options.map((o) => o.value)].map((v) => [
                String(v),
                v,
            ]),
        ).values(),
    ]);
}
async function keyboard(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        e.preventDefault();
        close(true);
        return;
    }
    if (e.key === 'Tab') {
        close(true);
        return;
    }
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End', 'Enter'].includes(e.key))
        return;
    e.preventDefault();
    if (!open.value) {
        await show();
        return;
    }
    const list = filtered.value;
    if (!list.length) return;
    if (e.key === 'Enter') {
        choose(list[active.value] || list[0]);
        return;
    }
    if (e.key === 'Home') active.value = 0;
    else if (e.key === 'End') active.value = list.length - 1;
    else
        active.value =
            (active.value + (e.key === 'ArrowDown' ? 1 : -1) + list.length) %
            list.length;
    await nextTick();
    panel.value
        ?.querySelector(`[data-option-index="${active.value}"]`)
        ?.scrollIntoView({ block: 'nearest' });
}
onBeforeUnmount(() => close());
</script>
<template>
    <span class="alfar-select"
        ><button
            ref="trigger"
            v-bind="$attrs"
            type="button"
            class="alfar-select-trigger"
            role="combobox"
            :aria-expanded="open"
            :aria-controls="id"
            aria-haspopup="listbox"
            :disabled="disabled"
            @click="show"
            @keydown="keyboard"
        >
            <span>{{ caption }}</span
            ><ChevronDown :size="16" /></button
        ><Teleport to="body"
            ><div
                v-if="open"
                ref="panel"
                class="alfar-select-panel"
                :style="style"
                tabindex="-1"
                @keydown="keyboard"
            >
                <div v-if="searchable" class="alfar-select-search">
                    <Search :size="16" /><input
                        ref="searchInput"
                        v-model="query"
                        aria-label="Cari pilihan"
                        placeholder="Cari pilihan…"
                        autocomplete="off"
                        @input="active = 0"
                    />
                </div>
                <div v-if="multiple" class="alfar-select-tools">
                    <button type="button" @click="chooseAll()">
                        Pilih semua ({{
                            options.filter((o) => !o.disabled).length
                        }})</button
                    ><button type="button" @click="update([])">
                        <X :size="13" />Kosongkan</button
                    ><button
                        v-if="query"
                        type="button"
                        @click="chooseAll(true)"
                    >
                        Pilih hasil pencarian
                    </button>
                </div>
                <div
                    :id="id"
                    class="alfar-select-options"
                    role="listbox"
                    :aria-multiselectable="multiple"
                    :aria-activedescendant="
                        filtered.length ? `${id}-${active}` : undefined
                    "
                >
                    <button
                        v-for="(option, index) in filtered"
                        :id="`${id}-${index}`"
                        :key="String(option.value)"
                        type="button"
                        role="option"
                        :aria-selected="isSelected(option.value)"
                        :disabled="option.disabled"
                        :data-option-index="index"
                        :class="{
                            chosen: isSelected(option.value),
                            highlighted: active === index,
                        }"
                        @pointermove="active = index"
                        @click="choose(option)"
                    >
                        <span
                            ><strong>{{ option.label }}</strong
                            ><small v-if="option.description">{{
                                option.description
                            }}</small></span
                        ><Check v-if="isSelected(option.value)" :size="16" />
                    </button>
                    <p v-if="!filtered.length">Tidak ada pilihan yang cocok.</p>
                </div>
                <div v-if="multiple" class="alfar-select-footer">
                    {{ selected.length }} dari
                    {{ options.length }} dipilih<button
                        type="button"
                        @click="close(true)"
                    >
                        Selesai
                    </button>
                </div>
            </div></Teleport
        ></span
    >
</template>
<style>
.alfar-select {
    display: inline-flex;
    min-width: 0;
    max-width: 100%;
    vertical-align: middle;
}
.alfar-select-trigger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    width: 100%;
    min-height: 40px;
    padding: 10px 12px;
    border: 1px solid #e1e4ef;
    border-radius: 10px;
    background: #fff;
    color: #475569;
    font:
        500 12px 'Instrument Sans',
        sans-serif;
    text-align: left;
    cursor: pointer;
}
.alfar-select-trigger > span {
    min-width: 0;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}
.alfar-select-trigger svg {
    flex-shrink: 0;
    color: #9a92b1;
}
.alfar-select-trigger:disabled {
    cursor: not-allowed;
    background: #f5f4fa;
    opacity: 0.75;
}
.alfar-select-trigger:focus-visible {
    outline: 2px solid #aaa1ff;
    outline-offset: 2px;
}
.alfar-select-panel {
    z-index: 200;
    display: flex;
    flex-direction: column;
    border: 1px solid #e5e2f0;
    border-radius: 13px;
    background: #fff;
    color: #30304a;
    box-shadow: 0 12px 35px #25213f26;
    font:
        12px 'Instrument Sans',
        sans-serif;
    color-scheme: light;
    overflow: hidden;
}
.alfar-select-search {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 12px;
    border-bottom: 1px solid #efedf5;
    color: #9d94b5;
}
.alfar-select-search input {
    width: 100%;
    border: 0;
    background: #fff;
    color: #30304a;
    outline: none;
    font: inherit;
    min-width: 0;
}
.alfar-select-tools {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    padding: 10px 12px;
    border-bottom: 1px solid #efedf5;
}
.alfar-select-tools button,
.alfar-select-footer button {
    display: flex;
    align-items: center;
    gap: 4px;
    border: 0;
    background: transparent;
    color: #5546e8;
    font:
        600 11px 'Instrument Sans',
        sans-serif;
    cursor: pointer;
}
.alfar-select-options {
    overflow-y: auto;
    overscroll-behavior: contain;
    min-height: 0;
    padding: 5px;
}
.alfar-select-options > button {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    width: 100%;
    padding: 11px 10px;
    border: 0;
    border-radius: 8px;
    background: #fff;
    color: #475569;
    text-align: left;
    cursor: pointer;
    font:
        12px 'Instrument Sans',
        sans-serif;
}
.alfar-select-options strong {
    font-weight: 500;
    display: block;
}
.alfar-select-options small {
    display: block;
    color: #9397ac;
    margin-top: 4px;
}
.alfar-select-options > button.highlighted,
.alfar-select-options > button:hover {
    background: #f5f3ff;
}
.alfar-select-options > button.chosen {
    color: #5546e8;
    background: #eeebff;
}
.alfar-select-options > button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.alfar-select-options > p {
    padding: 18px;
    color: #8b91a6;
    text-align: center;
}
.alfar-select-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 12px;
    border-top: 1px solid #efedf5;
    color: #8b91a6;
    font-size: 11px;
}
.alfar-form .alfar-select,
.scanner-settings .alfar-select,
.role-assignment .alfar-select {
    width: 100%;
}
.heading-actions .alfar-select {
    min-width: 165px;
}
.toolbar .alfar-select {
    min-width: 170px;
}
.grant-scope .alfar-select {
    flex: 1;
    width: 170px;
}
.clock-form .alfar-select {
    min-width: 170px;
}
.report-shift-filter {
    min-width: 170px;
}
@media (max-width: 600px) {
    .toolbar .alfar-select {
        flex: 1;
        min-width: 130px;
    }
}
</style>
