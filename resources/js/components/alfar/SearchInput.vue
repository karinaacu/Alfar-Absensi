<script setup lang="ts">
import { Search, X } from '@lucide/vue';
defineProps<{ placeholder?: string }>();
const model = defineModel<string>({ default: '' });
</script>
<template>
    <div class="alfar-search">
        <Search :size="17" aria-hidden="true" /><input
            v-model="model"
            type="search"
            :aria-label="placeholder || 'Cari data'"
            :placeholder="placeholder || 'Cari data…'"
        /><button
            v-if="model"
            type="button"
            aria-label="Kosongkan pencarian"
            @click="model = ''"
        >
            <X :size="15" />
        </button>
    </div>
</template>
<style scoped>
.alfar-search {
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid #e6e7f2;
    border-radius: 12px;
    background: white;
    padding: 0 14px;
    min-width: 220px;
    color: #8187a2;
}
.alfar-search input {
    border: 0 !important;
    background: transparent;
    outline: 0;
    min-width: 0;
    width: 100%;
    padding: 12px 0;
    color: #252840;
}
.alfar-search button {
    border: 0;
    background: transparent;
    color: #626983;
    cursor: pointer;
}
</style>
<style scoped>
input::-webkit-search-cancel-button,
input::-webkit-search-decoration {
    display: none;
}
</style>
