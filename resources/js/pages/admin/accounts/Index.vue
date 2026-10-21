<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { AtSign, KeyRound, Pencil, Plus } from '@lucide/vue';
import AccountController from '@/actions/App/Http/Controllers/Admin/AccountController';
import EmailOverrideDialog from '@/components/admin/EmailOverrideDialog.vue';
import StaffAccountFormDialog from '@/components/admin/StaffAccountFormDialog.vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import SetupLinkDialog from '@/components/SetupLinkDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useFilters } from '@/composables/useFilters';
import type { Account, College, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Accounts', href: AccountController.index() }],
    },
});

const props = defineProps<{
    accounts: Paginated<Account>;
    colleges: Pick<College, 'id' | 'code' | 'name'>[];
    filters: {
        search: string | null;
        role: string | null;
        college: number | null;
        status: string | null;
    };
}>();

const filters = useFilters(AccountController.index.url(), {
    search: props.filters.search ?? '',
    role: props.filters.role,
    college: props.filters.college,
    status: props.filters.status,
});

const roleLabels: Record<string, string> = {
    admin: 'Admin',
    registrar: 'Registrar',
    dean: 'Dean',
    teacher: 'Teacher',
    learner: 'Learner',
};

function identifier(account: Account): string {
    return (
        account.teacher?.employee_number ??
        account.learner?.student_number ??
        ''
    );
}

function lastLogin(account: Account): string {
    return account.last_login_at
        ? new Date(account.last_login_at).toLocaleString(undefined, {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          })
        : 'Never';
}
</script>

<template>
    <Head title="Accounts" />
    <SetupLinkDialog />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Accounts"
                description="Registrar, dean and teacher accounts are made here; learners are admitted by the registrar"
            />
            <StaffAccountFormDialog :colleges="colleges">
                <Button><Plus class="h-4 w-4" /> Add staff account</Button>
            </StaffAccountFormDialog>
        </div>

        <div class="flex flex-wrap gap-3">
            <Input
                v-model="filters.search"
                class="max-w-xs"
                placeholder="Search name, email or number"
                aria-label="Search accounts"
            />
            <NativeSelect v-model="filters.role" aria-label="Role">
                <option :value="null">All roles</option>
                <option
                    v-for="(label, role) in roleLabels"
                    :key="role"
                    :value="role"
                >
                    {{ label }}
                </option>
            </NativeSelect>
            <NativeSelect v-model="filters.college" aria-label="College">
                <option :value="null">All colleges</option>
                <option
                    v-for="college in colleges"
                    :key="college.id"
                    :value="college.id"
                >
                    {{ college.code }}
                </option>
            </NativeSelect>
            <NativeSelect v-model="filters.status" aria-label="Status">
                <option :value="null">Active and deactivated</option>
                <option value="active">Active</option>
                <option value="inactive">Deactivated</option>
            </NativeSelect>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Role</th>
                        <th class="px-4 py-2 font-medium">College</th>
                        <th class="px-4 py-2 font-medium">Number</th>
                        <th class="px-4 py-2 font-medium">Last logged in</th>
                        <th class="px-4 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="account in accounts.data"
                        :key="account.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">
                            <div class="font-medium">
                                {{ account.full_name }}
                                <Badge
                                    v-if="!account.is_active"
                                    variant="secondary"
                                    class="ml-2"
                                    >Deactivated</Badge
                                >
                                <Badge
                                    v-if="
                                        account.teacher?.duty_status ===
                                        'on_leave'
                                    "
                                    variant="outline"
                                    class="ml-2"
                                    >On leave</Badge
                                >
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ account.email }}
                            </div>
                        </td>
                        <td class="px-4 py-2">
                            {{ roleLabels[account.role] }}
                        </td>
                        <td class="px-4 py-2">
                            {{ account.college?.code ?? '—' }}
                        </td>
                        <td class="px-4 py-2 font-mono text-xs">
                            {{ identifier(account) }}
                        </td>
                        <td
                            class="px-4 py-2 whitespace-nowrap"
                            :class="
                                account.last_login_at
                                    ? ''
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ lastLogin(account) }}
                        </td>
                        <td class="px-4 py-2">
                            <div
                                v-if="account.role !== 'admin'"
                                class="flex justify-end gap-1"
                            >
                                <StaffAccountFormDialog
                                    v-if="account.role !== 'learner'"
                                    :colleges="colleges"
                                    :account="account"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        title="Edit"
                                    >
                                        <Pencil class="h-4 w-4" />
                                        <span class="sr-only">Edit</span>
                                    </Button>
                                </StaffAccountFormDialog>
                                <EmailOverrideDialog :account="account">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        title="Change email (lost inbox)"
                                    >
                                        <AtSign class="h-4 w-4" />
                                        <span class="sr-only"
                                            >Change email</span
                                        >
                                    </Button>
                                </EmailOverrideDialog>
                                <ConfirmActionDialog
                                    v-if="account.is_active"
                                    title="Send a new setup link?"
                                    :description="`${account.full_name} gets an email with a link to set a new password, and you'll see the link to copy. Their current password keeps working until they use it.`"
                                    confirm-label="Make link"
                                    :url="
                                        AccountController.setupLink.url(
                                            account.id,
                                        )
                                    "
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        title="New setup link"
                                    >
                                        <KeyRound class="h-4 w-4" />
                                        <span class="sr-only"
                                            >New setup link</span
                                        >
                                    </Button>
                                </ConfirmActionDialog>
                                <template v-if="account.role !== 'learner'">
                                    <ConfirmActionDialog
                                        v-if="account.is_active"
                                        :title="`Deactivate ${account.full_name}?`"
                                        description="They won't be able to log in. Their records stay, and you can reactivate them at any time. A teacher's classes stay assigned to them until the dean reassigns them."
                                        confirm-label="Deactivate"
                                        destructive
                                        method="put"
                                        :data="{ active: false }"
                                        :url="
                                            AccountController.status.url(
                                                account.id,
                                            )
                                        "
                                    >
                                        <Button variant="ghost" size="sm"
                                            >Deactivate</Button
                                        >
                                    </ConfirmActionDialog>
                                    <ConfirmActionDialog
                                        v-else
                                        :title="`Reactivate ${account.full_name}?`"
                                        description="They'll be able to log in again."
                                        confirm-label="Reactivate"
                                        method="put"
                                        :data="{ active: true }"
                                        :url="
                                            AccountController.status.url(
                                                account.id,
                                            )
                                        "
                                    >
                                        <Button variant="ghost" size="sm"
                                            >Reactivate</Button
                                        >
                                    </ConfirmActionDialog>
                                </template>
                            </div>
                            <p
                                v-else
                                class="text-right text-xs text-muted-foreground"
                                title="Admins share the same permissions, so admin accounts are kept on the server (php artisan app:create-admin)"
                            >
                                Managed on the server
                            </p>
                        </td>
                    </tr>
                    <tr v-if="accounts.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No accounts match.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="accounts" />
    </div>
</template>
