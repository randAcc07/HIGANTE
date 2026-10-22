<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

const props = defineProps<{
    title: string;
    description: string;
    url: string;
}>();

const open = ref(false);
const deleting = ref(false);

function confirm(): void {
    deleting.value = true;
    router.delete(props.url, {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                variant="ghost"
                size="sm"
                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
            >
                <Trash2 class="h-4 w-4" />
                <span class="sr-only">Delete</span>
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">Cancel</Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    :disabled="deleting"
                    @click="confirm"
                >
                    {{ deleting ? 'Deleting…' : 'Delete' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
