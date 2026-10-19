<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

defineProps<{
    mustVerifyEmail: boolean;
    canEditName: boolean;
    status?: string;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            :description="
                canEditName
                    ? 'Update your name and email address'
                    : 'Update your email address'
            "
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div v-if="canEditName" class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="first_name">First name</Label>
                    <Input
                        id="first_name"
                        name="first_name"
                        :default-value="user.first_name"
                        required
                        autocomplete="given-name"
                    />
                    <InputError :message="errors.first_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="middle_name">Middle name</Label>
                    <Input
                        id="middle_name"
                        name="middle_name"
                        :default-value="user.middle_name ?? ''"
                        autocomplete="additional-name"
                    />
                    <InputError :message="errors.middle_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="last_name">Last name</Label>
                    <Input
                        id="last_name"
                        name="last_name"
                        :default-value="user.last_name"
                        required
                        autocomplete="family-name"
                    />
                    <InputError :message="errors.last_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="suffix">Suffix</Label>
                    <Input
                        id="suffix"
                        name="suffix"
                        :default-value="user.suffix ?? ''"
                        autocomplete="honorific-suffix"
                        placeholder="Jr., III"
                    />
                    <InputError :message="errors.suffix" />
                </div>
            </div>

            <div v-else class="grid gap-2" data-test="read-only-name">
                <Label>Name</Label>
                <p class="text-sm">{{ user.full_name }}</p>
                <p class="text-sm text-muted-foreground">
                    Your name is part of your school record. Ask the registrar's
                    office to correct it.
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    Your email address is unverified.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        Click here to re-send the verification email.
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    A new verification link has been sent to your email address.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>
    </div>
</template>
