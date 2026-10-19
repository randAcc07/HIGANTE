<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps<{
    errors: Partial<
        Record<'first_name' | 'middle_name' | 'last_name' | 'suffix', string>
    >;
    idPrefix: string;
}>();

const firstName = defineModel<string>('firstName', { required: true });
const middleName = defineModel<string>('middleName', { required: true });
const lastName = defineModel<string>('lastName', { required: true });
const suffix = defineModel<string>('suffix', { required: true });
</script>

<template>
    <div class="grid grid-cols-2 gap-4">
        <div class="grid gap-2">
            <Label :for="`${idPrefix}-first`">First name</Label>
            <Input :id="`${idPrefix}-first`" v-model="firstName" />
            <InputError :message="errors.first_name" />
        </div>
        <div class="grid gap-2">
            <Label :for="`${idPrefix}-middle`">Middle name</Label>
            <Input
                :id="`${idPrefix}-middle`"
                v-model="middleName"
                placeholder="Optional"
            />
            <InputError :message="errors.middle_name" />
        </div>
        <div class="grid gap-2">
            <Label :for="`${idPrefix}-last`">Last name</Label>
            <Input :id="`${idPrefix}-last`" v-model="lastName" />
            <InputError :message="errors.last_name" />
        </div>
        <div class="grid gap-2">
            <Label :for="`${idPrefix}-suffix`">Suffix</Label>
            <Input
                :id="`${idPrefix}-suffix`"
                v-model="suffix"
                placeholder="Jr., III"
            />
            <InputError :message="errors.suffix" />
        </div>
    </div>
</template>
