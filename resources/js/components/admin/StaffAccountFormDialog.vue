<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AccountController from '@/actions/App/Http/Controllers/Admin/AccountController';
import InputError from '@/components/InputError.vue';
import NameFields from '@/components/NameFields.vue';
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
import type { Account, College, DutyStatus, UserRole } from '@/types';

const props = defineProps<{
    colleges: Pick<College, 'id' | 'code' | 'name'>[];
    account?: Account;
}>();

type StaffRole = Exclude<UserRole, 'learner' | 'admin'>;

const open = ref(false);
const form = useForm({
    role: (props.account?.role ?? 'teacher') as StaffRole,
    college_id: props.account?.college_id ?? null,
    first_name: props.account?.first_name ?? '',
    middle_name: props.account?.middle_name ?? '',
    last_name: props.account?.last_name ?? '',
    suffix: props.account?.suffix ?? '',
    email: props.account?.email ?? '',
    employee_number: props.account?.teacher?.employee_number ?? '',
    duty_status: (props.account?.teacher?.duty_status ??
        'active') as DutyStatus,
});

const inCollege = computed(
    () => form.role === 'dean' || form.role === 'teacher',
);

function submit(): void {
    form.transform((data) => {
        const names = {
            first_name: data.first_name,
            middle_name: data.middle_name || null,
            last_name: data.last_name,
            suffix: data.suffix || null,
        };
        const teacher =
            data.role === 'teacher'
                ? {
                      employee_number: data.employee_number,
                      duty_status: data.duty_status,
                  }
                : {};

        return props.account
            ? { ...names, ...teacher }
            : {
                  ...names,
                  ...teacher,
                  role: data.role,
                  email: data.email,
                  college_id: inCollege.value ? data.college_id : null,
              };
    }).submit(
        props.account
            ? AccountController.update(props.account.id)
            : AccountController.store(),
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;

                if (!props.account) {
                    form.reset();
                }
            },
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent class="max-h-[90vh] overflow-y-auto">
            <DialogTitle>{{
                account ? `Edit ${account.full_name}` : 'Add staff account'
            }}</DialogTitle>
            <DialogDescription>
                {{
                    account
                        ? 'Role, college and email stay as they are. Use the email override for a lost inbox.'
                        : 'They get a link to set their own password.'
                }}
            </DialogDescription>
            <form class="space-y-4" @submit.prevent="submit">
                <div v-if="!account" class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="staff-role">Role</Label>
                        <select
                            id="staff-role"
                            v-model="form.role"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option value="teacher">Teacher</option>
                            <option value="dean">Dean</option>
                            <option value="registrar">Registrar</option>
                        </select>
                        <InputError :message="form.errors.role" />
                    </div>
                    <div v-if="inCollege" class="grid gap-2">
                        <Label for="staff-college">College</Label>
                        <select
                            id="staff-college"
                            v-model="form.college_id"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option :value="null" disabled>Choose…</option>
                            <option
                                v-for="college in colleges"
                                :key="college.id"
                                :value="college.id"
                            >
                                {{ college.code }}
                            </option>
                        </select>
                        <InputError :message="form.errors.college_id" />
                    </div>
                </div>

                <NameFields
                    v-model:first-name="form.first_name"
                    v-model:middle-name="form.middle_name"
                    v-model:last-name="form.last_name"
                    v-model:suffix="form.suffix"
                    :errors="form.errors"
                    id-prefix="staff"
                />

                <div v-if="!account" class="grid gap-2">
                    <Label for="staff-email">Email</Label>
                    <Input id="staff-email" v-model="form.email" type="email" />
                    <InputError :message="form.errors.email" />
                </div>

                <div
                    v-if="form.role === 'teacher'"
                    class="grid grid-cols-2 gap-4"
                >
                    <div class="grid gap-2">
                        <Label for="staff-employee">Employee number</Label>
                        <Input
                            id="staff-employee"
                            v-model="form.employee_number"
                            placeholder="URS-P-00001"
                        />
                        <InputError :message="form.errors.employee_number" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="staff-duty">Duty status</Label>
                        <select
                            id="staff-duty"
                            v-model="form.duty_status"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option value="active">Active</option>
                            <option value="on_leave">On leave</option>
                        </select>
                        <InputError :message="form.errors.duty_status" />
                    </div>
                </div>

                <DialogFooter>
                    <Button :disabled="form.processing">Save</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
