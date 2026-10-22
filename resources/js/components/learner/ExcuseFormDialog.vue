<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ExcuseController from '@/actions/App/Http/Controllers/Learner/ExcuseController';
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
import { addDays, daysBetween, today } from '@/lib/days';
import { reasonLabels } from '@/lib/excuses';
import type { ExcuseReason } from '@/types';

const props = defineProps<{
    reasons: ExcuseReason[];
    maxDays: number;
    dueWithinDays: number;
    /** A teacher filing for a learner: who they can choose, and where it goes. */
    learners?: { id: number; name: string; student_number: string }[];
    url?: string;
}>();

const open = ref(false);
const form = useForm({
    learner_id: null as number | null,
    date_from: '',
    date_to: '',
    reason: 'medical' as ExcuseReason,
    remarks: '',
    files: [] as File[],
});

/** Days past the deadline this excuse would be submitted (0 = on time). */
const daysLate = computed(() => {
    const last = form.date_to || form.date_from;

    return last
        ? Math.max(0, daysBetween(addDays(last, props.dueWithinDays), today()))
        : 0;
});

/** Errors for any of the files ("files.0", "files.1", …). */
const fileErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key === 'files' || key.startsWith('files.'))
        .map(([, message]) => message),
);

function pick(event: Event): void {
    form.files = Array.from((event.target as HTMLInputElement).files ?? []);
}

function submit(): void {
    form.transform(({ learner_id, ...data }) => ({
        ...data,
        ...(props.learners ? { learner_id } : {}),
        date_to: data.date_to || data.date_from,
        remarks: data.remarks || null,
    })).post(props.url ?? ExcuseController.store.url(), {
        forceFormData: true,
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
                <DialogTitle>{{
                    learners
                        ? 'File an excuse for a learner'
                        : 'Submit an excuse'
                }}</DialogTitle>
                <DialogDescription>
                    <template v-if="learners"
                        >For a learner in one of your classes, e.g. one who
                        handed you a certificate. It counts as approved in your
                        classes; their other teachers decide for
                        theirs.</template
                    >
                    <template v-else
                        >For absences on one day or a range of up to
                        {{ props.maxDays }} days. Each of your teachers decides
                        for their class; once approved, your absences in that
                        class on those days are excused (lates stay).</template
                    >
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div v-if="learners" class="grid gap-2">
                    <Label for="excuse-learner">Learner</Label>
                    <NativeSelect id="excuse-learner" v-model="form.learner_id">
                        <option :value="null" disabled>Choose…</option>
                        <option
                            v-for="learner in learners"
                            :key="learner.id"
                            :value="learner.id"
                        >
                            {{ learner.name }} · {{ learner.student_number }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.learner_id" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="excuse-from">From</Label>
                        <Input
                            id="excuse-from"
                            v-model="form.date_from"
                            type="date"
                        />
                        <InputError :message="form.errors.date_from" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="excuse-to">To (optional)</Label>
                        <Input
                            id="excuse-to"
                            v-model="form.date_to"
                            type="date"
                            :min="form.date_from"
                        />
                        <InputError :message="form.errors.date_to" />
                    </div>
                </div>

                <p
                    v-if="daysLate"
                    class="rounded-md border border-warning/50 bg-warning-soft p-2 text-sm text-warning-soft-foreground"
                >
                    This is {{ daysLate }} day{{
                        daysLate === 1 ? '' : 's'
                    }}
                    past the {{ dueWithinDays }}-day deadline. It can still be
                    submitted; it will show as late.
                </p>

                <div class="grid gap-2">
                    <Label for="excuse-reason">Reason</Label>
                    <NativeSelect id="excuse-reason" v-model="form.reason">
                        <option
                            v-for="reason in reasons"
                            :key="reason"
                            :value="reason"
                        >
                            {{ reasonLabels[reason] }}
                        </option>
                    </NativeSelect>
                    <p
                        v-if="form.reason === 'medical'"
                        class="text-xs text-muted-foreground"
                    >
                        Attach the certificate signed by your attending
                        physician; it can't be approved without one.
                    </p>
                    <InputError :message="form.errors.reason" />
                </div>

                <div class="grid gap-2">
                    <Label for="excuse-remarks">Remarks</Label>
                    <textarea
                        id="excuse-remarks"
                        v-model="form.remarks"
                        rows="3"
                        class="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                        placeholder="What happened, and anything the dean should know"
                    />
                    <InputError :message="form.errors.remarks" />
                </div>

                <div class="grid gap-2">
                    <Label for="excuse-files">Files</Label>
                    <Input
                        id="excuse-files"
                        type="file"
                        multiple
                        accept="application/pdf,image/jpeg,image/png,image/webp"
                        @change="pick"
                    />
                    <p class="text-xs text-muted-foreground">
                        PDFs or photos, up to 10 files of 10 MB each.
                    </p>
                    <InputError
                        v-for="error in fileErrors"
                        :key="error"
                        :message="error"
                    />
                </div>

                <DialogFooter>
                    <Button
                        :disabled="
                            form.processing ||
                            !form.date_from ||
                            (!!learners && form.learner_id === null)
                        "
                        >{{ learners ? 'File' : 'Submit' }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
