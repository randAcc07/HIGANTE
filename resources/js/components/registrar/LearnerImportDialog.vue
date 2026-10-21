<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Download, FileSpreadsheet } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import LearnerImportController from '@/actions/App/Http/Controllers/Registrar/LearnerImportController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type CheckResult = {
    /** Set when the sheet is clean: what the import uses. */
    token: string | null;
    count: number;
    term: string | null;
    errors: { row: number | null; column: string | null; message: string }[];
    more: number;
};

type ImportDone = {
    count: number;
    term: string | null;
    unsectioned: number;
    links: string;
};

const props = defineProps<{
    /** Terms a new learner can be registered for right away. */
    registrationTerms: { id: number; label: string }[];
}>();

const open = ref(false);
const page = usePage();
const result = ref<CheckResult | null>(null);
const done = ref<ImportDone | null>(null);
const importing = ref(false);

const form = useForm({
    file: null as File | null,
    register_term_id: null as number | null,
});

watch(
    () => (page.flash as { learnerImport?: CheckResult }).learnerImport,
    (value) => {
        if (value) {
            result.value = value;
            done.value = null;
        }
    },
);
watch(
    () => (page.flash as { learnerImportDone?: ImportDone }).learnerImportDone,
    (value) => {
        if (value) {
            done.value = value;
            result.value = null;
            open.value = true;
        }
    },
);

function pick(event: Event): void {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
    result.value = null;
}

function check(): void {
    form.post(LearnerImportController.check.url(), {
        forceFormData: true,
        preserveScroll: true,
    });
}

function runImport(): void {
    if (!result.value?.token) {
        return;
    }

    importing.value = true;
    router.post(
        LearnerImportController.store.url(),
        { token: result.value.token, register_term_id: form.register_term_id },
        {
            preserveScroll: true,
            onFinish: () => (importing.value = false),
        },
    );
}

const termLabel = computed(
    () =>
        props.registrationTerms.find(
            (term) => term.id === form.register_term_id,
        )?.label ?? null,
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogTitle>Import learners from a sheet</DialogTitle>
            <DialogDescription>
                Fill in the template (one learner per row), then upload it.
                Every row is checked first and nothing is saved until the whole
                sheet is clean.
            </DialogDescription>

            <template v-if="done">
                <div
                    class="space-y-3 rounded-lg border border-success/50 bg-success-soft p-4 text-sm text-success-soft-foreground"
                >
                    <p class="font-medium">
                        Admitted {{ done.count }} learner{{
                            done.count === 1 ? '' : 's'
                        }}<template v-if="done.term">
                            and registered them for {{ done.term }}</template
                        >.
                    </p>
                    <p v-if="done.unsectioned">
                        {{ done.unsectioned }} had no section with room; the
                        dean places them.
                    </p>
                    <p>
                        Each was emailed a setup link. Download them once to
                        hand out where email isn't reliable; the file is deleted
                        after this download, and the links expire in 7 days.
                    </p>
                    <Button as-child size="sm">
                        <a :href="done.links"
                            ><Download class="h-4 w-4" /> Download setup
                            links</a
                        >
                    </Button>
                </div>
            </template>

            <template v-else>
                <Button variant="outline" size="sm" as-child class="w-fit">
                    <a :href="LearnerImportController.template.url()"
                        ><FileSpreadsheet class="h-4 w-4" /> Download the
                        template</a
                    >
                </Button>

                <form class="space-y-4" @submit.prevent="check">
                    <div class="grid gap-2">
                        <Label for="import-file">Filled-in sheet (.xlsx)</Label>
                        <Input
                            id="import-file"
                            type="file"
                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            @change="pick"
                        />
                        <InputError :message="form.errors.file" />
                    </div>
                    <div v-if="registrationTerms.length" class="grid gap-2">
                        <Label for="import-term">Register them for</Label>
                        <select
                            id="import-term"
                            v-model="form.register_term_id"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                            @change="result = null"
                        >
                            <option :value="null">Don't register yet</option>
                            <option
                                v-for="term in registrationTerms"
                                :key="term.id"
                                :value="term.id"
                            >
                                {{ term.label }}
                            </option>
                        </select>
                        <p class="text-xs text-muted-foreground">
                            Each goes to the emptiest section of their program
                            and year level with room; the Irregular column
                            decides automatic enrollment.
                        </p>
                    </div>
                    <Button
                        :disabled="!form.file || form.processing"
                        variant="outline"
                        >Check the sheet</Button
                    >
                </form>

                <div v-if="result && result.errors.length" class="space-y-2">
                    <p
                        class="text-sm font-medium text-destructive"
                        role="alert"
                    >
                        {{ result.errors.length + result.more }} problem{{
                            result.errors.length + result.more === 1 ? '' : 's'
                        }}
                        to fix in the sheet, then upload it again.
                    </p>
                    <div class="max-h-72 overflow-y-auto rounded-lg border">
                        <table class="w-full text-sm">
                            <thead
                                class="sticky top-0 bg-muted text-left text-muted-foreground"
                            >
                                <tr>
                                    <th class="px-3 py-2 font-medium">Row</th>
                                    <th class="px-3 py-2 font-medium">
                                        Column
                                    </th>
                                    <th class="px-3 py-2 font-medium">
                                        Problem
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(error, i) in result.errors"
                                    :key="i"
                                    class="border-t"
                                >
                                    <td class="px-3 py-1.5">
                                        {{ error.row ?? '—' }}
                                    </td>
                                    <td class="px-3 py-1.5">
                                        {{ error.column ?? '—' }}
                                    </td>
                                    <td class="px-3 py-1.5">
                                        {{ error.message }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="result.more" class="text-xs text-muted-foreground">
                        …and {{ result.more }} more.
                    </p>
                </div>

                <div
                    v-else-if="result && result.token"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-success/50 bg-success-soft p-3 text-sm text-success-soft-foreground"
                >
                    <span
                        >All {{ result.count }} learner{{
                            result.count === 1 ? '' : 's'
                        }}
                        check out<template v-if="termLabel">
                            and will be registered for {{ termLabel }}</template
                        >.</span
                    >
                    <Button :disabled="importing" @click="runImport"
                        >Import {{ result.count }}</Button
                    >
                </div>
            </template>
        </DialogContent>
    </Dialog>
</template>
