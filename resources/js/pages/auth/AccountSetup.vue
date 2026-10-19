<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';

defineOptions({
    layout: {
        title: 'Set your password',
        description: 'Choose the password you will log in with.',
    },
});

defineProps<{
    valid: boolean;
    name: string | null;
    email: string | null;
    submitUrl: string | null;
}>();
</script>

<template>
    <Head title="Set your password" />

    <Form
        v-if="valid && submitUrl"
        :action="submitUrl"
        method="post"
        class="space-y-6"
        v-slot="{ errors, processing }"
    >
        <p class="text-center text-sm">
            Welcome, <span class="font-medium">{{ name }}</span
            >. You'll log in as <span class="font-medium">{{ email }}</span
            >.
        </p>
        <div class="grid gap-2">
            <Label for="password">Password</Label>
            <PasswordInput
                id="password"
                name="password"
                required
                autocomplete="new-password"
                autofocus
            />
            <InputError :message="errors.password" />
        </div>
        <div class="grid gap-2">
            <Label for="password_confirmation">Confirm password</Label>
            <PasswordInput
                id="password_confirmation"
                name="password_confirmation"
                required
                autocomplete="new-password"
            />
        </div>
        <Button class="w-full" :disabled="processing">
            <Spinner v-if="processing" />
            Set password
        </Button>
    </Form>

    <div v-else class="space-y-6 text-center">
        <p class="text-sm" data-test="setup-link-invalid">
            This link has expired or was already used. Ask the school for a new
            one.
        </p>
        <TextLink :href="login()" class="text-sm">Go to log in</TextLink>
    </div>
</template>
