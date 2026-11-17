<script setup lang="ts">
import { useId } from 'vue';
defineProps<{ code: string }>();
const tooltipId = useId();
</script>

<template>
    <span class="technical-hint">
        <button
            type="button"
            class="technical-hint-button"
            :aria-describedby="tooltipId"
            aria-label="Lihat kode teknis"
        >
            !
        </button>
        <span :id="tooltipId" role="tooltip" class="technical-hint-tooltip">{{
            code
        }}</span>
    </span>
</template>

<style scoped>
.technical-hint {
    position: relative;
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
}
.technical-hint-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    padding: 0;
    border: 1px solid #b5adcf;
    border-radius: 50%;
    background: #faf9ff;
    color: #786a9e;
    font-size: 12px;
    font-weight: 700;
    line-height: 1;
    cursor: help;
}
.technical-hint-tooltip {
    position: absolute;
    z-index: 5;
    bottom: calc(100% + 7px);
    right: 0;
    width: max-content;
    max-width: 240px;
    padding: 8px 10px;
    border-radius: 7px;
    background: #30304a;
    color: #fff;
    font-size: 11px;
    line-height: 1.5;
    overflow-wrap: anywhere;
    visibility: hidden;
    opacity: 0;
    pointer-events: none;
}
.technical-hint:hover .technical-hint-tooltip,
.technical-hint:focus-within .technical-hint-tooltip {
    visibility: visible;
    opacity: 1;
}
</style>
