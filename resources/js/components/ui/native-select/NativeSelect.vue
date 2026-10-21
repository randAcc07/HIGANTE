<script setup lang="ts" generic="T">
import type { HTMLAttributes } from "vue"
import { getCurrentInstance } from "vue"
import { ChevronDown } from "@lucide/vue"
import { cn } from "@/lib/utils"

// A native <select> with our own chevron: the browser's sits ~6px from the
// edge whatever the padding. The chevron is inset by the same amount as the
// text, with an 8px gap between them. Every other attribute and listener
// (id, :value, @change, :disabled…) lands on the <select> itself.
defineOptions({ inheritAttrs: false })

const props = withDefaults(
  defineProps<{
    size?: "default" | "sm"
    // Applies to the wrapper, so width utilities (w-full, max-w-md) work.
    class?: HTMLAttributes["class"]
  }>(),
  { size: "default" },
)

const [model, modifiers] = defineModel<T, "number">({
  set: (value) => {
    if (!modifiers.number) {
      return value
    }

    const number = Number.parseFloat(String(value))

    return (Number.isNaN(number) ? value : number) as T
  },
})

// Only drive the <select> through v-model when the parent uses v-model;
// otherwise an unset model would override a plain :value binding.
const bound = "onUpdate:modelValue" in (getCurrentInstance()?.vnode.props ?? {})

const selectClass = cn(
  "border-input w-full min-w-0 appearance-none truncate rounded-md border bg-transparent text-sm disabled:cursor-not-allowed disabled:opacity-50",
  props.size === "sm" ? "h-8 pr-8 pl-2" : "h-9 pr-9 pl-3",
)
</script>

<template>
  <div
    data-slot="native-select"
    :class="cn('relative inline-flex', props.class)"
  >
    <select
      v-if="bound"
      v-model="model"
      v-bind="$attrs"
      :class="selectClass"
    >
      <slot />
    </select>
    <select
      v-else
      v-bind="$attrs"
      :class="selectClass"
    >
      <slot />
    </select>
    <ChevronDown
      aria-hidden="true"
      :class="cn(
        'text-muted-foreground pointer-events-none absolute top-1/2 size-4 -translate-y-1/2',
        props.size === 'sm' ? 'right-2' : 'right-3',
      )"
    />
  </div>
</template>
