<script setup lang="ts">
import { computed, nextTick, ref, useAttrs, useId } from 'vue';
import { CalendarDays, Clock3, ChevronLeft, ChevronRight } from '@lucide/vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import AppSelect from './AppSelect.vue';
defineOptions({ inheritAttrs: false });
const props = withDefaults(
    defineProps<{
        modelValue?: unknown;
        mode?: 'date' | 'time' | 'datetime';
        label?: string;
        placeholder?: string;
        min?: string;
        max?: string;
        required?: boolean;
        disabled?: boolean;
        defaultDate?: string;
        seconds?: boolean;
    }>(),
    {
        mode: 'date',
        label: '',
        placeholder: '',
        required: false,
        disabled: false,
        seconds: false,
    },
);
const emit = defineEmits<{
    'update:modelValue': [value: string];
    change: [value: string];
}>();
const attrs = useAttrs();
const id = useId();
const open = ref(false),
    draft = ref(''),
    hour = ref('00'),
    minute = ref('00'),
    second = ref('00'),
    year = ref(2026),
    month = ref(1);
const calendar = ref<HTMLElement | null>(null);
const pickerBody = ref<HTMLElement | null>(null);
const pad = (value: number) => String(value).padStart(2, '0');
function dateValue(value: unknown): string {
    if (typeof value !== 'string') return '';
    const match = value.match(/^(\d{4})-(\d{2})-(\d{2})(?:$|[ T])/);
    if (!match) return '';
    const [y, m, d] = match.slice(1).map(Number);
    const date = new Date(0);
    date.setFullYear(y, m - 1, d);
    date.setHours(12, 0, 0, 0);
    return y > 0 &&
        date.getFullYear() === y &&
        date.getMonth() === m - 1 &&
        date.getDate() === d
        ? `${match[1]}-${match[2]}-${match[3]}`
        : '';
}
function makeDate(value: string) {
    const [y, m, d] = value.split('-').map(Number);
    const date = new Date(0);
    date.setFullYear(y, m - 1, d);
    date.setHours(12, 0, 0, 0);
    return date;
}
const today = () =>
    new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Jakarta' });
const label = computed(
    () =>
        props.label ||
        String(attrs['aria-label'] || '') ||
        (props.mode === 'date'
            ? 'Pilih tanggal'
            : props.mode === 'time'
              ? 'Pilih waktu'
              : 'Pilih tanggal dan waktu'),
);
const value = computed(() =>
    typeof props.modelValue === 'string' ? props.modelValue : '',
);
const display = computed(() => {
    const date = dateValue(value.value);
    const clock = value.value.match(/(?:^|[ T])(\d{2}):(\d{2})(?::(\d{2}))?$/);
    const time = clock
        ? `${clock[1]}.${clock[2]}${props.seconds ? '.' + (clock[3] || '00') : ''}`
        : '';
    if (props.mode === 'time') return time;
    const text = date
        ? makeDate(date).toLocaleDateString('id-ID', {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
          })
        : '';
    return props.mode === 'datetime' && text && time
        ? `${text} · ${time}`
        : text;
});
const months = Array.from({ length: 12 }, (_, i) => ({
    value: i + 1,
    label: new Date(2026, i, 1).toLocaleDateString('id-ID', { month: 'long' }),
}));
const years = computed(() =>
    Array.from(
        {
            length:
                Math.max(2100, year.value + 5) -
                Math.min(1900, year.value - 5) +
                1,
        },
        (_, i) => {
            const y = Math.min(1900, year.value - 5) + i;
            return { value: y, label: String(y) };
        },
    ).filter((y) => y.value >= 1 && y.value <= 9999),
);
const cells = computed(() => {
    const first = makeDate(
        `${String(year.value).padStart(4, '0')}-${pad(month.value)}-01`,
    );
    first.setDate(first.getDate() - ((first.getDay() + 6) % 7));
    return Array.from({ length: 42 }, (_, i) => {
        const date = new Date(first);
        date.setDate(date.getDate() + i);
        const iso = `${String(date.getFullYear()).padStart(4, '0')}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
        return {
            value: iso,
            day: date.getDate(),
            outside: date.getMonth() + 1 !== month.value,
            label: date.toLocaleDateString('id-ID', {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric',
            }),
        };
    });
});
function blocked(date: string) {
    if (!dateValue(date)) return true;
    return !!(
        (props.min && date < dateValue(props.min)) ||
        (props.max && dateValue(props.max) && date > dateValue(props.max))
    );
}
const candidate = computed(() =>
    props.mode === 'date'
        ? draft.value
        : props.mode === 'time'
          ? `${hour.value}:${minute.value}${props.seconds ? ':' + second.value : ''}`
          : `${draft.value} ${hour.value}:${minute.value}:${second.value}`,
);
const invalid = computed(() => {
    if (
        props.mode !== 'time' &&
        (!dateValue(draft.value) || blocked(draft.value))
    )
        return true;
    const normal = (v: string) => v.replace('T', ' ');
    return !!(
        (props.min && candidate.value < normal(props.min)) ||
        (props.max && candidate.value > normal(props.max))
    );
});
function show() {
    if (props.disabled) return;
    let seed =
        dateValue(value.value) || dateValue(props.defaultDate) || today();
    if (props.min && dateValue(props.min) && seed < dateValue(props.min))
        seed = dateValue(props.min);
    if (props.max && dateValue(props.max) && seed > dateValue(props.max))
        seed = dateValue(props.max);
    draft.value = seed;
    year.value = Number(seed.slice(0, 4));
    month.value = Number(seed.slice(5, 7));
    const match = value.value.match(/(?:^|[ T])(\d{2}):(\d{2})(?::(\d{2}))?$/);
    hour.value = match?.[1] || '00';
    minute.value = match?.[2] || '00';
    second.value = match?.[3] || '00';
    open.value = true;
}
function focusPicker(event: Event) {
    event.preventDefault();
    void nextTick(() => {
        const root = pickerBody.value;
        root?.querySelectorAll<HTMLElement>('.picker-clock-list').forEach(
            (list) => {
                const selected = list.querySelector<HTMLButtonElement>(
                    '[aria-selected=true]',
                );
                if (selected)
                    list.scrollTop = Math.max(0, selected.offsetTop - 40);
            },
        );
        const target =
            props.mode === 'time'
                ? root?.querySelector<HTMLButtonElement>(
                      '.picker-clock-list [aria-selected=true]',
                  )
                : root?.querySelector<HTMLButtonElement>(
                      '.picker-grid [aria-pressed=true]',
                  );
        target?.focus({ preventScroll: true });
    });
}
function move(delta: number) {
    const date = makeDate(
        `${String(year.value).padStart(4, '0')}-${pad(month.value)}-01`,
    );
    date.setMonth(date.getMonth() + delta);
    if (date.getFullYear() < 1 || date.getFullYear() > 9999) return;
    year.value = date.getFullYear();
    month.value = date.getMonth() + 1;
}
function chooseDate(date: string) {
    if (blocked(date)) return;
    draft.value = date;
    year.value = Number(date.slice(0, 4));
    month.value = Number(date.slice(5, 7));
}
function apply() {
    if (invalid.value) return;
    emit('update:modelValue', candidate.value);
    emit('change', candidate.value);
    open.value = false;
}
function clear() {
    emit('update:modelValue', '');
    emit('change', '');
    open.value = false;
}
function clockKey(
    event: KeyboardEvent,
    part: 'hour' | 'minute' | 'second',
    limit: number,
) {
    const values = { hour, minute, second };
    let n = Number(values[part].value);
    if (event.key === 'ArrowDown' || event.key === 'ArrowRight')
        n = (n + 1) % limit;
    else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft')
        n = (n - 1 + limit) % limit;
    else if (event.key === 'Home') n = 0;
    else if (event.key === 'End') n = limit - 1;
    else return;
    event.preventDefault();
    values[part].value = pad(n);
    void nextTick(() =>
        document.getElementById(`${id}-${part}-${pad(n)}`)?.focus(),
    );
}
function calendarKey(event: KeyboardEvent) {
    if (!(event.target instanceof HTMLElement) || !event.target.dataset.date)
        return;
    const delta = (
        { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 } as Record<
            string,
            number
        >
    )[event.key];
    if (!delta) return;
    event.preventDefault();
    const date = makeDate(event.target.dataset.date);
    date.setDate(date.getDate() + delta);
    const iso = `${String(date.getFullYear()).padStart(4, '0')}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    chooseDate(iso);
    void nextTick(() =>
        calendar.value
            ?.querySelector<HTMLButtonElement>(`[data-date="${iso}"]`)
            ?.focus(),
    );
}
</script>
<template>
    <span class="alfar-picker"
        ><button
            v-bind="attrs"
            type="button"
            class="alfar-picker-trigger"
            role="combobox"
            aria-haspopup="dialog"
            :aria-expanded="open"
            :aria-label="label"
            :aria-required="required"
            :disabled="disabled"
            :class="{ 'picker-empty': !display }"
            @click="show"
        >
            <CalendarDays v-if="mode !== 'time'" :size="17" /><Clock3
                v-else
                :size="17"
            /><span>{{ display || placeholder || label }}</span></button
        ><Dialog v-model:open="open"
            ><DialogContent
                class="alfar-confirmation alfar-picker-dialog"
                @open-auto-focus="focusPicker"
                ><div ref="pickerBody" class="picker-body">
                    <DialogHeader
                        ><DialogTitle>{{ label }}</DialogTitle
                        ><DialogDescription>{{
                            mode === 'date'
                                ? 'Pilih tanggal pada kalender.'
                                : mode === 'time'
                                  ? 'Pilih jam dan menit dalam format 24 jam.'
                                  : 'Pilih tanggal, jam, dan menit. Format disimpan otomatis oleh sistem.'
                        }}</DialogDescription></DialogHeader
                    >
                    <div v-if="mode !== 'time'" class="picker-calendar">
                        <div class="picker-calendar-nav">
                            <button
                                type="button"
                                aria-label="Bulan sebelumnya"
                                @click="move(-1)"
                            >
                                <ChevronLeft :size="18" /></button
                            ><AppSelect
                                v-model="month"
                                :options="months"
                                aria-label="Bulan kalender"
                                :searchable="false"
                            /><AppSelect
                                v-model="year"
                                :options="years"
                                aria-label="Tahun kalender"
                            /><button
                                type="button"
                                aria-label="Bulan berikutnya"
                                @click="move(1)"
                            >
                                <ChevronRight :size="18" />
                            </button>
                        </div>
                        <div
                            ref="calendar"
                            class="picker-grid"
                            @keydown="calendarKey"
                        >
                            <span
                                v-for="day in [
                                    'Sen',
                                    'Sel',
                                    'Rab',
                                    'Kam',
                                    'Jum',
                                    'Sab',
                                    'Min',
                                ]"
                                :key="day"
                                class="picker-weekday"
                                >{{ day }}</span
                            ><button
                                v-for="cell in cells"
                                :key="cell.value"
                                type="button"
                                :data-date="cell.value"
                                :aria-label="cell.label"
                                :aria-pressed="draft === cell.value"
                                :aria-current="
                                    cell.value === today() ? 'date' : undefined
                                "
                                :disabled="blocked(cell.value)"
                                :class="{
                                    'picker-outside': cell.outside,
                                    'picker-selected': draft === cell.value,
                                    'picker-today': cell.value === today(),
                                }"
                                @click="chooseDate(cell.value)"
                            >
                                {{ cell.day }}
                            </button>
                        </div>
                        <button
                            type="button"
                            class="picker-today-button"
                            :disabled="blocked(today())"
                            @click="chooseDate(today())"
                        >
                            Hari ini
                        </button>
                    </div>
                    <div v-if="mode !== 'date'" class="picker-clock">
                        <div
                            v-for="part in seconds
                                ? [
                                      {
                                          key: 'hour' as const,
                                          label: 'Jam',
                                          count: 24,
                                      },
                                      {
                                          key: 'minute' as const,
                                          label: 'Menit',
                                          count: 60,
                                      },
                                      {
                                          key: 'second' as const,
                                          label: 'Detik',
                                          count: 60,
                                      },
                                  ]
                                : [
                                      {
                                          key: 'hour' as const,
                                          label: 'Jam',
                                          count: 24,
                                      },
                                      {
                                          key: 'minute' as const,
                                          label: 'Menit',
                                          count: 60,
                                      },
                                  ]"
                            :key="part.key"
                            class="picker-clock-part"
                        >
                            <strong>{{ part.label }}</strong>
                            <div
                                role="listbox"
                                :aria-label="part.label"
                                class="picker-clock-list"
                                @keydown="
                                    (event) =>
                                        clockKey(event, part.key, part.count)
                                "
                            >
                                <button
                                    v-for="n in part.count"
                                    :id="`${id}-${part.key}-${pad(n - 1)}`"
                                    :key="n"
                                    type="button"
                                    role="option"
                                    :aria-label="`${part.label} ${pad(n - 1)}`"
                                    :aria-selected="
                                        (part.key === 'hour'
                                            ? hour
                                            : part.key === 'minute'
                                              ? minute
                                              : second) === pad(n - 1)
                                    "
                                    :tabindex="
                                        (part.key === 'hour'
                                            ? hour
                                            : part.key === 'minute'
                                              ? minute
                                              : second) === pad(n - 1)
                                            ? 0
                                            : -1
                                    "
                                    @click="
                                        part.key === 'hour'
                                            ? (hour = pad(n - 1))
                                            : part.key === 'minute'
                                              ? (minute = pad(n - 1))
                                              : (second = pad(n - 1))
                                    "
                                >
                                    {{ pad(n - 1) }}
                                </button>
                            </div>
                        </div>
                    </div>
                    <p class="picker-preview">
                        {{
                            mode !== 'time'
                                ? makeDate(draft).toLocaleDateString('id-ID', {
                                      day: 'numeric',
                                      month: 'long',
                                      year: 'numeric',
                                  })
                                : ''
                        }}{{ mode === 'datetime' ? ' · ' : ''
                        }}{{
                            mode !== 'date'
                                ? `${hour}.${minute}${seconds ? '.' + second : ''}`
                                : ''
                        }}
                    </p>
                    <p v-if="invalid" class="picker-range">
                        Pilih nilai dalam rentang yang diperbolehkan.
                    </p>
                    <DialogFooter
                        ><Button
                            v-if="!required && value"
                            type="button"
                            variant="outline"
                            @click="clear"
                            >Kosongkan</Button
                        ><Button
                            type="button"
                            variant="outline"
                            @click="open = false"
                            >Batal</Button
                        ><Button
                            type="button"
                            :disabled="invalid"
                            @click="apply"
                            >Terapkan</Button
                        ></DialogFooter
                    >
                </div></DialogContent
            ></Dialog
        ></span
    >
</template>
<style>
.alfar-picker {
    display: inline-flex;
    width: 100%;
    min-width: 0;
}
.alfar-picker-trigger {
    width: 100%;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 13px;
    border: 1px solid #dedfee;
    border-radius: 10px;
    background: white;
    color: #363b55;
    font-size: 12px;
    line-height: 1.5;
    text-align: left;
    cursor: pointer;
}
.alfar-picker-trigger svg {
    flex-shrink: 0;
    color: #8b83ad;
}
.alfar-picker-trigger span {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
}
.alfar-picker-trigger.picker-empty {
    color: #8990a6;
}
.alfar-picker-trigger:focus-visible {
    outline: 3px solid #dcd6ff;
    outline-offset: 2px;
    border-color: #7664e8;
}
.alfar-picker-trigger:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.alfar-confirmation.alfar-picker-dialog {
    max-width: 440px;
    padding: 22px;
    gap: 16px;
}
.picker-body {
    display: grid;
    gap: 16px;
}
.picker-calendar-nav {
    display: grid;
    grid-template-columns: 32px minmax(0, 1fr) 105px 32px;
    gap: 6px;
    align-items: center;
    margin-bottom: 14px;
}
.picker-calendar-nav > button {
    display: grid;
    place-items: center;
    padding: 8px 4px;
    background: #f5f2ff;
    border: 1px solid #e6e1f8;
    border-radius: 8px;
    color: #6758b0;
    cursor: pointer;
}
.picker-calendar-nav .alfar-select-trigger {
    padding: 9px;
    font-size: 12px;
}
.picker-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 4px;
}
.picker-weekday {
    font-size: 10px;
    color: #8f94a9;
    text-align: center;
    padding: 7px 0;
}
.picker-grid button {
    height: 36px;
    border: 1px solid transparent;
    border-radius: 9px;
    background: #f7f7fc;
    color: #353b55;
    font-size: 12px;
    cursor: pointer;
}
.picker-grid button:hover:not(:disabled) {
    background: #eee9ff;
}
.picker-grid button.picker-selected {
    background: #5946e8;
    color: white;
}
.picker-grid .picker-outside {
    color: #b2b6c8;
    background: transparent;
}
.picker-grid .picker-today {
    border-color: #bdb1f6;
}
.picker-grid button:disabled {
    opacity: 0.3;
    cursor: not-allowed;
}
.picker-grid button:focus-visible,
.picker-clock-list button:focus-visible {
    outline: 2px solid #8e7fe6;
    outline-offset: 1px;
}
.picker-today-button {
    font-size: 11px;
    border: 0;
    background: transparent;
    color: #6752d4;
    margin-top: 10px;
    cursor: pointer;
}
.picker-clock {
    display: flex;
    gap: 12px;
    padding: 12px;
    background: #faf9ff;
    border: 1px solid #eeeaf8;
    border-radius: 12px;
}
.picker-clock-part {
    flex: 1;
    min-width: 0;
}
.picker-clock-part > strong {
    display: block;
    font-size: 11px;
    color: #85819c;
    margin-bottom: 8px;
    text-align: center;
}
.picker-clock-list {
    position: relative;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 5px;
    max-height: 144px;
    overflow-y: auto;
    scrollbar-width: thin;
    align-content: start;
    padding: 3px;
}
.picker-clock-list button {
    border: 1px solid #e8e4f4;
    border-radius: 7px;
    background: white;
    padding: 7px;
    color: #5a5673;
    font-size: 12px;
    cursor: pointer;
}
.picker-clock-list button[aria-selected='true'] {
    background: #5946e8;
    color: white;
    border-color: #5946e8;
}
.picker-preview {
    margin: 0;
    text-align: center;
    font-size: 13px;
    color: #5a4a94;
    font-weight: 600;
}
.picker-range {
    font-size: 11px;
    color: #bb3c50;
    margin: 0;
}
@media (max-width: 480px) {
    .alfar-confirmation.alfar-picker-dialog {
        padding: 18px;
    }
    .picker-calendar-nav {
        grid-template-columns: 28px minmax(0, 1fr) 95px 28px;
        gap: 4px;
    }
    .picker-calendar-nav .alfar-select-trigger {
        padding: 8px 6px;
        font-size: 11px;
    }
    .picker-clock {
        gap: 8px;
        padding: 9px;
    }
}
</style>
