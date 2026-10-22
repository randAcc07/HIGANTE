<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BatchController from '@/actions/App/Http/Controllers/Dean/BatchController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Label } from '@/components/ui/label';
import type { Offering } from '@/types';

/** Give other subjects of the section the same split as this one. */
const props = defineProps<{
    source: Offering;
    offerings: Offering[];
}>();

const open = ref(false);
const targets = computed(() =>
    props.offerings.filter((offering) => offering.id !== props.source.id),
);
const names = computed(() => props.source.batches.map((batch) => batch.name));

const form = useForm<{ targets: number[] }>({ targets: [] });

function toggle(id: number, on: boolean | 'indeterminate'): void {
    form.targets =
        on === true
            ? [...form.targets, id]
            : form.targets.filter((target) => target !== id);
}

/** Batch names the target lacks, which the copy adds. */
function missing(offering: Offering): string[] {
    const have = new Set(offering.batches.map((batch) => batch.name));

    return names.value.filter((name) => !have.has(name));
}

function submit(): void {
    form.post(BatchController.copy.url(props.source.id), {
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
            <DialogHeader>
                <DialogTitle
                    >Copy {{ source.subject.code }}'s batches</DialogTitle
                >
                <DialogDescription>
                    The chosen subjects get the same split ({{
                        names.join(', ')
                    }}): missing batches are added, and everyone taking both is
                    put in the batch of the same name. Learners without a batch
                    in {{ source.subject.code }} are left as they are. Classes
                    aren't copied; schedule new batches on the Timetables page.
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label
                        v-for="offering in targets"
                        :key="offering.id"
                        class="flex items-start gap-2 font-normal"
                    >
                        <Checkbox
                            class="mt-0.5"
                            :model-value="form.targets.includes(offering.id)"
                            @update:model-value="toggle(offering.id, $event)"
                        />
                        <span>
                            <span class="font-medium">{{
                                offering.subject.code
                            }}</span>
                            {{ offering.subject.title }}
                            <span class="block text-xs text-muted-foreground">
                                <template v-if="missing(offering).length"
                                    >Adds
                                    {{ missing(offering).join(', ') }}</template
                                >
                                <template v-else>Has these batches</template
                                ><template
                                    v-if="
                                        offering.batches.some(
                                            (batch) =>
                                                !names.includes(batch.name),
                                        )
                                    "
                                >
                                    · its other batches stay</template
                                >
                            </span>
                        </span>
                    </Label>
                    <p
                        v-if="targets.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        The section has no other subjects.
                    </p>
                </div>

                <div
                    v-if="Object.keys(form.errors).length"
                    class="rounded-lg border border-destructive/50 p-3 text-sm text-destructive"
                    role="alert"
                >
                    <p v-for="error in Object.values(form.errors)" :key="error">
                        {{ error }}
                    </p>
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary"
                            >Cancel</Button
                        >
                    </DialogClose>
                    <Button
                        :disabled="form.processing || form.targets.length === 0"
                        >Copy</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
