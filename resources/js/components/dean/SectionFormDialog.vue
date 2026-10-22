<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import SectionController from '@/actions/App/Http/Controllers/Dean/SectionController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import type { Program } from '@/types';

type SectionRow = {
    id: number;
    program_id: number;
    year_level: number;
    name: string;
    capacity: number | null;
};

const props = defineProps<{
    termId: number;
    programs: Pick<Program, 'id' | 'code' | 'name'>[];
    section?: SectionRow;
}>();

const open = ref(false);
const form = useForm({
    program_id: props.section?.program_id ?? props.programs[0]?.id ?? null,
    year_level: String(props.section?.year_level ?? 1),
    name: props.section?.name ?? '',
    capacity: props.section?.capacity?.toString() ?? '',
});

function submit(): void {
    form.transform((data) => ({
        ...(props.section
            ? {}
            : {
                  term_id: props.termId,
                  program_id: data.program_id,
                  year_level: Number(data.year_level),
              }),
        name: data.name,
        capacity: data.capacity === '' ? null : Number(data.capacity),
    })).submit(
        props.section
            ? SectionController.update(props.section.id)
            : SectionController.store(),
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
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
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    section ? `Edit ${section.name}` : 'Add section'
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        section
                            ? "Program and year level can't change once learners may be registered to it."
                            : 'A block of learners of one program and year level, for this term.'
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div v-if="!section" class="grid grid-cols-3 gap-4">
                    <div class="col-span-2 grid gap-2">
                        <Label for="section-program">Program</Label>
                        <NativeSelect
                            id="section-program"
                            v-model="form.program_id"
                        >
                            <option
                                v-for="program in programs"
                                :key="program.id"
                                :value="program.id"
                            >
                                {{ program.code }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.program_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="section-year">Year</Label>
                        <Input
                            id="section-year"
                            v-model="form.year_level"
                            type="number"
                            min="1"
                            max="6"
                        />
                        <InputError :message="form.errors.year_level" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="section-name">Name</Label>
                        <Input
                            id="section-name"
                            v-model="form.name"
                            placeholder="1-A"
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="section-capacity">Capacity</Label>
                        <Input
                            id="section-capacity"
                            v-model="form.capacity"
                            type="number"
                            min="1"
                            placeholder="No limit"
                        />
                        <InputError :message="form.errors.capacity" />
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">
                    Capacity is optional. When a program and year level has
                    several sections, new learners go to the emptiest one with
                    room.
                </p>
                <DialogFooter>
                    <Button :disabled="form.processing">Save</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
