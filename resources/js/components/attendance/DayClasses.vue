<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AttendanceController from '@/actions/App/Http/Controllers/AttendanceController';
import { Badge } from '@/components/ui/badge';
import { formatTime } from '@/lib/time';
import type { DayClass } from '@/types';

/** One day's classes, each opening its class list for that date. */
defineProps<{
    classes: DayClass[];
    date: string;
    /** Show who teaches each class (the dean's view). */
    showTeacher?: boolean;
    empty: string;
}>();
</script>

<template>
    <div class="overflow-x-auto rounded-lg border">
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left text-muted-foreground">
                <tr>
                    <th class="px-4 py-2 font-medium">Time</th>
                    <th class="px-4 py-2 font-medium">Class</th>
                    <th class="px-4 py-2 font-medium">Room</th>
                    <th v-if="showTeacher" class="px-4 py-2 font-medium">
                        Teacher
                    </th>
                    <th class="px-4 py-2 font-medium">Attendance</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="item in classes"
                    :key="item.schedule_id"
                    class="border-t"
                >
                    <td class="px-4 py-2 whitespace-nowrap">
                        {{ formatTime(item.start) }}–{{ formatTime(item.end) }}
                    </td>
                    <td class="px-4 py-2">
                        <Link
                            v-if="!item.refusal"
                            :href="
                                AttendanceController.show({
                                    schedule: item.schedule_id,
                                    date,
                                })
                            "
                            class="font-medium text-highlight hover:underline"
                            >{{ item.subject }}</Link
                        >
                        <span v-else class="font-medium">{{
                            item.subject
                        }}</span>
                        <span class="text-muted-foreground">
                            · {{ item.section
                            }}<template v-if="item.batch">
                                ({{ item.batch }})</template
                            ></span
                        >
                        <div class="text-xs text-muted-foreground">
                            {{ item.title }}
                        </div>
                        <Badge
                            v-if="item.covering"
                            variant="secondary"
                            class="mt-1 bg-info-soft text-info-soft-foreground"
                            >Covering for {{ item.teacher }}</Badge
                        >
                        <div
                            v-else-if="item.cover"
                            class="text-xs text-muted-foreground"
                        >
                            Covered by {{ item.cover }}
                        </div>
                    </td>
                    <td class="px-4 py-2">{{ item.room ?? 'Online' }}</td>
                    <td v-if="showTeacher" class="px-4 py-2">
                        {{ item.teacher }}
                    </td>
                    <td class="px-4 py-2">
                        <span
                            v-if="item.refusal"
                            class="text-muted-foreground"
                            >{{ item.refusal }}</span
                        >
                        <Badge v-else-if="item.listed === 0" variant="secondary"
                            >Nobody enrolled</Badge
                        >
                        <template v-else-if="item.late || item.absent">
                            <Badge
                                v-if="item.late"
                                variant="secondary"
                                class="mr-1"
                                >{{ item.late }} late</Badge
                            >
                            <Badge v-if="item.absent" variant="secondary"
                                >{{ item.absent }} absent</Badge
                            >
                            <span class="ml-1 text-xs text-muted-foreground"
                                >of {{ item.listed }}</span
                            >
                        </template>
                        <span v-else class="text-muted-foreground"
                            >All {{ item.listed }} present</span
                        >
                    </td>
                </tr>
                <tr v-if="classes.length === 0">
                    <td
                        :colspan="showTeacher ? 5 : 4"
                        class="px-4 py-8 text-center text-muted-foreground"
                    >
                        {{ empty }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
