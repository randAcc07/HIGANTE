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
    <!-- The university seal, in full colour (public/favicon.svg, also the tab icon). -->
    <img
        src="/favicon.svg"
        alt=""
        :class="['object-contain', className]"
        v-bind="$attrs"
    />
</template>
