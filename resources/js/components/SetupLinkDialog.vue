<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Check, Copy } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import type { SetupLink } from '@/types';

/**
 * Shows a freshly made account setup link once, so it can be handed over
 * while the school has no mail server. It is also emailed.
 */
const page = usePage();
const open = ref(false);
const link = ref<SetupLink | null>(null);
const copied = ref(false);

watch(
    () => (page.flash as { setupLink?: SetupLink }).setupLink,
    (value) => {
        if (value) {
            link.value = value;
            copied.value = false;
            open.value = true;
        }
    },
    { immediate: true },
);

async function copy(): Promise<void> {
    if (link.value) {
        await navigator.clipboard.writeText(link.value.url);
        copied.value = true;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent v-if="link">
            <DialogHeader>
                <DialogTitle>Setup link for {{ link.name }}</DialogTitle>
                <DialogDescription>
                    It was emailed to {{ link.email }}. You can also copy it and
                    hand it over another way. It works once and expires in
                    {{ link.days }} days. It won't be shown again, but you can
                    always make a new one.
                </DialogDescription>
            </DialogHeader>
            <div class="flex gap-2">
                <Input
                    :model-value="link.url"
                    readonly
                    class="font-mono text-xs"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <Button variant="outline" @click="copy">
                    <Check v-if="copied" class="h-4 w-4" />
                    <Copy v-else class="h-4 w-4" />
                    {{ copied ? 'Copied' : 'Copy' }}
                </Button>
            </div>
            <DialogFooter>
                <Button @click="open = false">Done</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
