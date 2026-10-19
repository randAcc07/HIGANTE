<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';

defineOptions({
    layout: {
        title: 'Email change',
        description: 'Changing an account email needs both addresses to agree.',
    },
});

type State =
    | 'confirm-new'
    | 'review-current'
    | 'waiting-for-current'
    | 'waiting-for-new'
    | 'completed'
    | 'taken'
    | 'denied'
    | 'invalid';

const props = defineProps<{
    state: State;
    newEmail: string | null;
    accountName: string | null;
    confirmUrl?: string;
    approveUrl?: string;
    denyUrl?: string;
}>();

const messages: Record<
    Exclude<State, 'confirm-new' | 'review-current'>,
    string
> = {
    'waiting-for-current':
        'This address is confirmed. The change finishes once the current address approves it.',
    'waiting-for-new':
        'The change is approved. It finishes once the new address confirms it.',
    completed:
        'Both addresses agreed. The account now uses the new email address.',
    taken: 'Another account started using that address first, so the change was stopped. Request it again with a different address.',
    denied: 'The change was cancelled. The account keeps its current email address. If you did not ask for it, change your password.',
    invalid:
        'This link has expired or is no longer valid. It may have been replaced by a newer request, or the change may already be finished.',
};

const message = computed(() =>
    props.state === 'confirm-new' || props.state === 'review-current'
        ? null
        : messages[props.state],
);
</script>

<template>
    <Head title="Email change" />

    <div v-if="state === 'confirm-new'" class="space-y-6 text-center">
        <p class="text-sm">
            Use
            <span class="font-medium">{{ newEmail }}</span>
            as the email for
            <span class="font-medium">{{ accountName }}</span
            >'s account?
        </p>
        <Form :action="confirmUrl" method="post" v-slot="{ processing }">
            <Button :disabled="processing" class="w-full">
                <Spinner v-if="processing" />
                Confirm this address
            </Button>
        </Form>
    </div>

    <div v-else-if="state === 'review-current'" class="space-y-6 text-center">
        <p class="text-sm">
            Move <span class="font-medium">{{ accountName }}</span
            >'s account to <span class="font-medium">{{ newEmail }}</span
            >?
        </p>
        <Form :action="approveUrl" method="post" v-slot="{ processing }">
            <Button :disabled="processing" class="w-full">
                <Spinner v-if="processing" />
                Approve the change
            </Button>
        </Form>
        <Form :action="denyUrl" method="post" v-slot="{ processing }">
            <Button
                variant="outline"
                :disabled="processing"
                class="w-full"
                data-test="deny-email-change"
            >
                This wasn't me, cancel it
            </Button>
        </Form>
    </div>

    <div v-else class="space-y-6 text-center">
        <p class="text-sm" data-test="email-change-message">{{ message }}</p>
        <TextLink :href="login()" class="text-sm">Go to log in</TextLink>
    </div>
</template>
