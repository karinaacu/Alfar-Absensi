<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 42"
        :class="className"
        v-bind="$attrs"
    >
        <text
            x="5"
            y="33"
            font-family="Arial, sans-serif"
            font-size="39"
            font-weight="700"
            fill="currentColor"
        >
            a
        </text>
        <circle cx="31" cy="31" r="3" fill="#b2c785" />
    </svg>
</template>
