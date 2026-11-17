<script setup lang="ts">
import { Toaster } from 'vue-sonner';
import 'vue-sonner/style.css';
</script>
<template>
    <slot /><Toaster
        theme="light"
        rich-colors
        close-button
        position="top-right"
        :duration="5000"
        :visible-toasts="3"
    />
</template>
