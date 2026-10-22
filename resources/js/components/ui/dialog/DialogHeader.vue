<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="dialog-header"
    :class="cn('bg-background sticky -top-6 z-10 -mx-6 -mt-6 -mb-2 flex flex-col gap-2 px-6 pt-6 pr-12 pb-2 text-left', props.class)"
  >
    <slot />
  </div>
</template>
