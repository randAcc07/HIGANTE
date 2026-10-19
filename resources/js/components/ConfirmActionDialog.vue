<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

const props = withDefaults(
    defineProps<{
        title: string;
        description: string;
        confirmLabel: string;
        url: string;
        method?: 'post' | 'put' | 'delete';
        data?: Record<string, string | number | boolean | null>;
        destructive?: boolean;
    }>(),
    { method: 'post', data: () => ({}), destructive: false },
);

const open = ref(false);
const busy = ref(false);

function confirm(): void {
    busy.value = true;
    router.visit(props.url, {
        method: props.method,
        data: props.data,
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent>
            <DialogTitle>{{ title }}</DialogTitle>
            <DialogDescription>{{ description }}</DialogDescription>
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">Cancel</Button>
                </DialogClose>
                <Button
                    :variant="destructive ? 'destructive' : 'default'"
                    :disabled="busy"
                    @click="confirm"
                >
                    {{ confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
