<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmailChangeController from '@/actions/App/Http/Controllers/Settings/EmailChangeController';
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

type PendingEmailChange = {
    new_email: string;
    new_confirmed: boolean;
    old_approved: boolean;
    expires_at: string;
};

const props = defineProps<{
    mustVerifyEmail: boolean;
    canEditName: boolean;
    pendingEmailChange: PendingEmailChange | null;
    status?: string;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);

const expiresAt = computed(() =>
    props.pendingEmailChange
        ? new Date(props.pendingEmailChange.expires_at).toLocaleString()
        : '',
);
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Name"
            :description="
                canEditName
                    ? 'How your name appears across the system'
                    : 'Your name is part of your school record'
            "
        />

        <Form
            v-if="canEditName"
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-4 sm:grid-cols-2">
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

            <Button :disabled="processing" data-test="update-profile-button">
                Save name
            </Button>
        </Form>

        <div v-else class="grid gap-2" data-test="read-only-name">
            <p class="text-sm">{{ user.full_name }}</p>
            <p class="text-sm text-muted-foreground">
                Ask the registrar's office to correct it.
            </p>
        </div>
    </div>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Email address"
            description="Changing it needs a confirmation from both the new and the current address"
        />

        <div class="grid gap-1 text-sm">
            <span class="text-muted-foreground">Current address</span>
            <span>{{ user.email }}</span>
        </div>

        <div v-if="mustVerifyEmail && !user.email_verified_at">
            <p class="text-sm text-muted-foreground">
                Your email address is unverified.
                <Link
                    :href="send()"
                    as="button"
                    class="text-highlight underline decoration-highlight/40 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current!"
                >
                    Click here to re-send the verification email.
                </Link>
            </p>

            <div
                v-if="status === 'verification-link-sent'"
                class="mt-2 text-sm font-medium text-success"
            >
                A new verification link has been sent to your email address.
            </div>
        </div>

        <div
            v-if="pendingEmailChange"
            class="space-y-3 rounded-lg border p-4 text-sm"
            data-test="pending-email-change"
        >
            <p>
                Changing to
                <span class="font-medium">{{
                    pendingEmailChange.new_email
                }}</span>
            </p>
            <ul class="space-y-1 text-muted-foreground">
                <li>
                    New address:
                    {{
                        pendingEmailChange.new_confirmed
                            ? 'confirmed'
                            : 'waiting for confirmation'
                    }}
                </li>
                <li>
                    Current address:
                    {{
                        pendingEmailChange.old_approved
                            ? 'approved'
                            : 'waiting for approval'
                    }}
                </li>
                <li>The links expire {{ expiresAt }}.</li>
            </ul>
            <Form
                v-bind="EmailChangeController.destroy.form()"
                v-slot="{ processing }"
            >
                <Button variant="outline" size="sm" :disabled="processing">
                    Cancel this change
                </Button>
            </Form>
        </div>

        <Form
            v-bind="EmailChangeController.store.form()"
            class="space-y-6"
            reset-on-success
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="new_email">New email address</Label>
                <Input
                    id="new_email"
                    type="email"
                    name="new_email"
                    required
                    autocomplete="email"
                    placeholder="name@example.com"
                />
                <InputError :message="errors.new_email" />
            </div>

            <div class="grid gap-2">
                <Label for="email_change_password">Current password</Label>
                <Input
                    id="email_change_password"
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password"
                />
                <InputError :message="errors.password" />
            </div>

            <p v-if="pendingEmailChange" class="text-sm text-muted-foreground">
                Sending a new request replaces the pending one and its links.
            </p>

            <Button :disabled="processing" data-test="request-email-change">
                Send confirmation links
            </Button>
        </Form>
    </div>
</template>
