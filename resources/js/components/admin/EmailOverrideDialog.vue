<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import UserEmailController from '@/actions/App/Http/Controllers/Admin/UserEmailController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Account } from '@/types';

const props = defineProps<{
    account: Account;
}>();

const open = ref(false);
const form = useForm({ email: '', reason: '' });

function submit(): void {
    form.submit(UserEmailController.update(props.account.id), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
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
            <DialogTitle>Change {{ account.full_name }}'s email</DialogTitle>
            <DialogDescription>
                For someone who lost access to {{ account.email }}. The change
                applies at once, is logged with your reason, and the old address
                is told about it. People who still have their inbox should
                change it themselves under Settings.
            </DialogDescription>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="override-email">New email</Label>
                    <Input
                        id="override-email"
                        v-model="form.email"
                        type="email"
                    />
                    <InputError :message="form.errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="override-reason">Reason</Label>
                    <Input
                        id="override-reason"
                        v-model="form.reason"
                        placeholder="Lost access to the old inbox"
                    />
                    <InputError :message="form.errors.reason" />
                </div>
                <DialogFooter>
                    <Button :disabled="form.processing">Change email</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
