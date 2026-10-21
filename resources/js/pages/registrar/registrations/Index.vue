<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import RegistrationController from '@/actions/App/Http/Controllers/Registrar/RegistrationController';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import NextTermButton from '@/components/NextTermButton.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/composables/useFilters';
import type {
    College,
    Paginated,
    Program,
    RegistrationCandidate,
    RegistrationRow,
    RegistrationTermOption,
    Semester,
    TermPhase,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Registration', href: RegistrationController.index() },
        ],
    },
});

const props = defineProps<{
    terms: RegistrationTermOption[];
    term: RegistrationTermOption | null;
    canRegister: boolean;
    counts: {
        registered: number;
        unsectioned: number;
        withdrawn: number;
    } | null;
    candidates: Paginated<RegistrationCandidate> | null;
    registrations: Paginated<RegistrationRow> | null;
    colleges: (Pick<College, 'id' | 'code' | 'name'> & {
        programs: Pick<Program, 'id' | 'college_id' | 'code' | 'name'>[];
    })[];
    filters: {
        term: number | null;
        view: 'candidates' | 'registered';
        source: 'list' | 'others';
        search: string | null;
        college: number | null;
        program: number | null;
        year: number | null;
        status: string | null;
        unsectioned: boolean | null;
    };
    /** The next term, while the page shows another: a shortcut to it. */
    nextTerm: { id: number; label: string } | null;
}>();

const filters = useFilters(RegistrationController.index.url(), {
    term: props.term?.id ?? null,
    view: props.filters.view,
    source: props.filters.source,
    search: props.filters.search ?? '',
    college: props.filters.college,
    program: props.filters.program,
    year: props.filters.year,
    status: props.filters.status,
    unsectioned: (props.filters.unsectioned ? 1 : null) as number | null,
});

const programs = computed(() =>
    props.colleges
        .filter(
            (college) =>
                filters.college === null || college.id === filters.college,
        )
        .flatMap((college) => college.programs),
);

const phaseLabels: Record<TermPhase, string> = {
    upcoming: 'Upcoming',
    in_session: 'In session',
    ended: 'Ended',
    closed: 'Closed',
};

const listLabels: Record<Semester, string> = {
    first: "Last school year's learners",
    second: "The 1st semester's learners",
    summer: "This school year's learners",
};

const listHints: Record<Semester, string> = {
    first: 'Last school year’s learners, one year level up. Tick each one as they complete enrollment, then register them.',
    second: 'Everyone registered in the 1st semester is ticked (withdrawn learners are not). Untick those who left, then register.',
    summer: 'This school year’s learners. Tick those enrolling for summer.',
};

/** The registrar's ticks and overrides, per learner on this page. */
const choices = reactive<
    Record<
        number,
        { ticked: boolean; year_level: number; is_irregular: boolean }
    >
>({});

watch(
    () => props.candidates,
    (candidates) => {
        for (const key of Object.keys(choices)) {
            delete choices[Number(key)];
        }

        for (const row of candidates?.data ?? []) {
            choices[row.learner.id] = {
                ticked: props.canRegister && row.ticked,
                year_level: row.year_level,
                is_irregular: row.is_irregular,
            };
        }
    },
    { immediate: true },
);

const ticked = computed(() =>
    Object.entries(choices)
        .filter(([, choice]) => choice.ticked)
        .map(([id, choice]) => ({
            learner_id: Number(id),
            year_level: Number(choice.year_level),
            is_irregular: choice.is_irregular,
        })),
);

const allTicked = computed<boolean | 'indeterminate'>(() => {
    const rows = props.candidates?.data ?? [];

    if (rows.length === 0 || ticked.value.length === 0) {
        return false;
    }

    return ticked.value.length === rows.length ? true : 'indeterminate';
});

function tickAll(value: boolean | 'indeterminate'): void {
    for (const choice of Object.values(choices)) {
        choice.ticked = value === true;
    }
}

const form = useForm<{
    entries: {
        learner_id: number;
        year_level: number;
        is_irregular: boolean;
    }[];
}>({ entries: [] });

const formErrors = computed(() => Object.values(form.errors));

function register(entries: typeof ticked.value): void {
    if (!props.term || entries.length === 0) {
        return;
    }

    form.entries = entries;
    form.post(RegistrationController.store.url(props.term.id), {
        preserveScroll: true,
    });
}

function registerOne(learnerId: number): void {
    const choice = choices[learnerId];
    register([
        {
            learner_id: learnerId,
            year_level: Number(choice.year_level),
            is_irregular: choice.is_irregular,
        },
    ]);
}
</script>

<template>
    <Head title="Registration" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Registration"
                :description="
                    term
                        ? `${term.label} · ${phaseLabels[term.phase]}`
                        : 'No terms yet'
                "
            />
            <div class="flex flex-wrap gap-2">
                <select
                    v-if="terms.length > 1"
                    v-model="filters.term"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    aria-label="Term"
                >
                    <option
                        v-for="option in terms"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.label }} ({{ phaseLabels[option.phase] }})
                    </option>
                </select>
                <NextTermButton
                    v-if="nextTerm"
                    :term="nextTerm"
                    @pick="(id) => (filters.term = id)"
                />
            </div>
        </div>

        <template v-if="term && counts">
            <p
                v-if="!canRegister"
                class="rounded-lg border p-3 text-sm text-muted-foreground"
            >
                This term has
                {{ term.phase === 'closed' ? 'closed' : 'ended' }}, so its
                registrations are read-only.
            </p>

            <div class="flex flex-wrap items-center gap-2 border-b">
                <button
                    v-if="canRegister"
                    type="button"
                    class="-mb-px border-b-2 px-3 py-2 text-sm font-medium"
                    :class="
                        filters.view === 'candidates'
                            ? 'border-primary'
                            : 'border-transparent text-muted-foreground'
                    "
                    @click="filters.view = 'candidates'"
                >
                    To register
                </button>
                <button
                    type="button"
                    class="-mb-px border-b-2 px-3 py-2 text-sm font-medium"
                    :class="
                        filters.view === 'registered'
                            ? 'border-primary'
                            : 'border-transparent text-muted-foreground'
                    "
                    @click="filters.view = 'registered'"
                >
                    Registered ({{ counts.registered }})
                </button>
                <span class="ml-auto text-sm text-muted-foreground">
                    <template v-if="counts.unsectioned"
                        >{{ counts.unsectioned }} waiting for the dean to place
                        ·
                    </template>
                    {{ counts.withdrawn }} withdrawn
                </span>
            </div>

            <div class="flex flex-wrap gap-3">
                <select
                    v-if="filters.view === 'candidates'"
                    v-model="filters.source"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    aria-label="List"
                >
                    <option value="list">
                        {{ listLabels[term.semester] }}
                    </option>
                    <option value="others">Others not registered</option>
                </select>
                <Input
                    v-model="filters.search"
                    class="max-w-xs"
                    placeholder="Search name, email or student number"
                    aria-label="Search learners"
                />
                <select
                    v-model="filters.college"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    aria-label="College"
                    @change="filters.program = null"
                >
                    <option :value="null">All colleges</option>
                    <option
                        v-for="college in colleges"
                        :key="college.id"
                        :value="college.id"
                    >
                        {{ college.code }}
                    </option>
                </select>
                <select
                    v-model="filters.program"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    aria-label="Program"
                >
                    <option :value="null">All programs</option>
                    <option
                        v-for="program in programs"
                        :key="program.id"
                        :value="program.id"
                    >
                        {{ program.code }}
                    </option>
                </select>
                <select
                    v-model="filters.year"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    aria-label="Year level"
                >
                    <option :value="null">All years</option>
                    <option v-for="year in 6" :key="year" :value="year">
                        Year {{ year }}
                    </option>
                </select>
                <template v-if="filters.view === 'registered'">
                    <select
                        v-model="filters.status"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        aria-label="Status"
                    >
                        <option :value="null">Any status</option>
                        <option value="registered">Registered</option>
                        <option value="withdrawn">Withdrawn</option>
                    </select>
                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox
                            :model-value="filters.unsectioned === 1"
                            @update:model-value="
                                filters.unsectioned = $event === true ? 1 : null
                            "
                        />
                        Unsectioned only
                    </label>
                </template>
            </div>

            <div
                v-if="formErrors.length"
                class="rounded-lg border border-destructive/50 p-3 text-sm text-destructive"
                role="alert"
            >
                <p v-for="error in formErrors" :key="error">{{ error }}</p>
            </div>

            <!-- To register -->
            <template v-if="candidates">
                <p
                    v-if="filters.source === 'list'"
                    class="text-sm text-muted-foreground"
                >
                    {{ listHints[term.semester] }} Each one goes to the emptiest
                    section of their program and year level with room<template
                        v-if="term.semester === 'second'"
                    >
                        (or the section named like their 1st-semester
                        one)</template
                    >; irregular learners get no subjects automatically.
                </p>
                <p v-else class="text-sm text-muted-foreground">
                    Active learners not on the list: returnees, or learners
                    admitted without registering. Check the year level before
                    registering.
                </p>

                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead
                            class="bg-muted/50 text-left text-muted-foreground"
                        >
                            <tr>
                                <th class="w-10 px-4 py-2">
                                    <Checkbox
                                        v-if="canRegister"
                                        :model-value="allTicked"
                                        aria-label="Tick everyone on this page"
                                        @update:model-value="tickAll"
                                    />
                                </th>
                                <th class="px-4 py-2 font-medium">Name</th>
                                <th class="px-4 py-2 font-medium">Program</th>
                                <th class="px-4 py-2 font-medium">Before</th>
                                <th class="px-4 py-2 font-medium">Year</th>
                                <th class="px-4 py-2 font-medium">Irregular</th>
                                <th class="px-4 py-2">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in candidates.data"
                                :key="row.learner.id"
                                class="border-t"
                            >
                                <td class="px-4 py-2">
                                    <Checkbox
                                        v-if="canRegister"
                                        v-model="choices[row.learner.id].ticked"
                                        :aria-label="`Tick ${row.learner.user.full_name}`"
                                    />
                                </td>
                                <td class="px-4 py-2">
                                    <div class="font-medium">
                                        {{ row.learner.user.last_name }},
                                        {{ row.learner.user.first_name }}
                                    </div>
                                    <div
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        {{ row.learner.student_number }}
                                    </div>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    {{ row.learner.program.code }}
                                    <span class="text-muted-foreground"
                                        >·
                                        {{
                                            row.learner.program.college.code
                                        }}</span
                                    >
                                </td>
                                <td class="px-4 py-2 text-muted-foreground">
                                    <template v-if="row.previous">
                                        {{ row.previous.term }} · Year
                                        {{ row.previous.year_level }}
                                        <template v-if="row.previous.section"
                                            >·
                                            {{ row.previous.section }}</template
                                        >
                                        <Badge
                                            v-if="
                                                row.previous.status ===
                                                'withdrawn'
                                            "
                                            variant="secondary"
                                            class="ml-1"
                                            >Withdrew</Badge
                                        >
                                    </template>
                                    <template v-else>—</template>
                                </td>
                                <td class="px-4 py-2">
                                    <Input
                                        v-model="
                                            choices[row.learner.id].year_level
                                        "
                                        type="number"
                                        min="1"
                                        max="6"
                                        class="h-8 w-16"
                                        :disabled="!canRegister"
                                        :aria-label="`Year level for ${row.learner.user.full_name}`"
                                    />
                                </td>
                                <td class="px-4 py-2">
                                    <Checkbox
                                        v-model="
                                            choices[row.learner.id].is_irregular
                                        "
                                        :disabled="!canRegister"
                                        :aria-label="`${row.learner.user.full_name} is irregular`"
                                    />
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <Button
                                        v-if="canRegister"
                                        variant="ghost"
                                        size="sm"
                                        :disabled="form.processing"
                                        @click="registerOne(row.learner.id)"
                                        >Register</Button
                                    >
                                </td>
                            </tr>
                            <tr v-if="candidates.data.length === 0">
                                <td
                                    colspan="7"
                                    class="px-4 py-8 text-center text-muted-foreground"
                                >
                                    Nobody left to register here.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="canRegister && candidates.data.length > 0"
                    class="sticky bottom-0 flex items-center justify-end gap-3 border-t bg-background py-3"
                >
                    <span class="text-sm text-muted-foreground"
                        >{{ ticked.length }} ticked on this page</span
                    >
                    <Button
                        :disabled="ticked.length === 0 || form.processing"
                        @click="register(ticked)"
                        >Register ticked</Button
                    >
                </div>

                <Pagination :paginator="candidates" />
            </template>

            <!-- Registered -->
            <template v-if="registrations">
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead
                            class="bg-muted/50 text-left text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-2 font-medium">Name</th>
                                <th class="px-4 py-2 font-medium">Program</th>
                                <th class="px-4 py-2 font-medium">Year</th>
                                <th class="px-4 py-2 font-medium">Section</th>
                                <th class="px-4 py-2 font-medium">
                                    Registered
                                </th>
                                <th class="px-4 py-2">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in registrations.data"
                                :key="row.id"
                                class="border-t"
                            >
                                <td class="px-4 py-2">
                                    <div class="font-medium">
                                        {{ row.learner.user.last_name }},
                                        {{ row.learner.user.first_name }}
                                        <Badge
                                            v-if="row.status === 'withdrawn'"
                                            variant="secondary"
                                            class="ml-2"
                                            >Withdrawn</Badge
                                        >
                                        <Badge
                                            v-if="row.is_irregular"
                                            variant="outline"
                                            class="ml-2"
                                            >Irregular</Badge
                                        >
                                    </div>
                                    <div
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        {{ row.learner.student_number }}
                                    </div>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    {{ row.learner.program.code }}
                                    <span class="text-muted-foreground"
                                        >·
                                        {{
                                            row.learner.program.college.code
                                        }}</span
                                    >
                                </td>
                                <td class="px-4 py-2">{{ row.year_level }}</td>
                                <td class="px-4 py-2">
                                    <template v-if="row.section">{{
                                        row.section.name
                                    }}</template>
                                    <Badge
                                        v-else-if="row.status === 'registered'"
                                        variant="secondary"
                                        >Waiting for the dean</Badge
                                    >
                                    <template v-else>—</template>
                                </td>
                                <td
                                    class="px-4 py-2 whitespace-nowrap text-muted-foreground"
                                >
                                    {{ row.registered_on }}
                                    <template v-if="row.registered_by"
                                        >· {{ row.registered_by }}</template
                                    >
                                </td>
                                <td class="px-4 py-2">
                                    <div
                                        v-if="canRegister"
                                        class="flex justify-end gap-1"
                                    >
                                        <template
                                            v-if="row.status === 'registered'"
                                        >
                                            <ConfirmActionDialog
                                                v-if="row.is_irregular"
                                                :title="`Make ${row.learner.user.full_name} regular?`"
                                                description="They are enrolled in every subject of their section they don't have yet."
                                                confirm-label="Make regular"
                                                method="put"
                                                :data="{ is_irregular: false }"
                                                :url="
                                                    RegistrationController.update.url(
                                                        row.id,
                                                    )
                                                "
                                            >
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    >Make regular</Button
                                                >
                                            </ConfirmActionDialog>
                                            <ConfirmActionDialog
                                                v-else
                                                :title="`Mark ${row.learner.user.full_name} irregular?`"
                                                description="Their current subjects stay; the dean adjusts them subject by subject, and subjects added to the section later aren't added for them."
                                                confirm-label="Mark irregular"
                                                method="put"
                                                :data="{ is_irregular: true }"
                                                :url="
                                                    RegistrationController.update.url(
                                                        row.id,
                                                    )
                                                "
                                            >
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    >Mark irregular</Button
                                                >
                                            </ConfirmActionDialog>
                                            <ConfirmActionDialog
                                                :title="`Withdraw ${row.learner.user.full_name}?`"
                                                description="Every class they have this term is dropped. The registration and its attendance stay on record. This can't be undone."
                                                confirm-label="Withdraw"
                                                destructive
                                                :url="
                                                    RegistrationController.withdraw.url(
                                                        row.id,
                                                    )
                                                "
                                            >
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    >Withdraw</Button
                                                >
                                            </ConfirmActionDialog>
                                        </template>
                                        <ConfirmActionDialog
                                            :title="`Undo ${row.learner.user.full_name}'s registration?`"
                                            description="For a registration made by mistake: it is removed with their subjects, as if it never happened. Not possible once attendance has been recorded; withdraw them instead."
                                            confirm-label="Undo registration"
                                            destructive
                                            method="delete"
                                            :url="
                                                RegistrationController.destroy.url(
                                                    row.id,
                                                )
                                            "
                                        >
                                            <Button variant="ghost" size="sm"
                                                >Undo</Button
                                            >
                                        </ConfirmActionDialog>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="registrations.data.length === 0">
                                <td
                                    colspan="6"
                                    class="px-4 py-8 text-center text-muted-foreground"
                                >
                                    No registrations match.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination :paginator="registrations" />
            </template>
        </template>
    </div>
</template>
