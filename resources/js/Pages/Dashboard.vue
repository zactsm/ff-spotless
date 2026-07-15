<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    mode: {
        type: String,
        default: 'welcome',
    },
    auth: {
        type: Object,
        default: () => ({ user: null, isAdmin: false }),
    },
    tasks: {
        type: Array,
        default: () => [],
    },
    currentDate: {
        type: String,
        default: '',
    },
    isReadOnly: {
        type: Boolean,
        default: true,
    },
    templates: {
        type: [Array, Object],
        default: () => [],
    },
    completedTasks: {
        type: [Array, Object],
        default: () => [],
    },
});

const page = usePage();

const sessions = {
    morning: {
        key: 'morning',
        label: 'Pagi',
        groupLabel: 'Pagi',
        pillClass: 'border-amber-400/30 bg-amber-400/10 text-amber-200',
    },
    afternoon: {
        key: 'afternoon',
        label: 'Tengah Hari',
        groupLabel: 'Tengah Hari',
        pillClass: 'border-sky-400/30 bg-sky-400/10 text-sky-200',
    },
    evening: {
        key: 'evening',
        label: 'Petang',
        groupLabel: 'Petang',
        pillClass: 'border-violet-400/30 bg-violet-400/10 text-violet-200',
    },
};

const sections = Object.values(sessions);
const unknownSession = {
    label: 'Tidak diketahui',
    groupLabel: 'Sesi tidak diketahui',
    pillClass: 'border-zinc-600 bg-zinc-800 text-zinc-300',
};

const localTasks = ref([]);
const selectedDate = ref('');
const adminDate = ref('');
const screen = ref(resolveInitialScreen());
const syncState = ref('connected');
const isNavigating = ref(false);
const isOpeningChecklist = ref(false);
const isExitingChecklist = ref(false);
const savingTaskIds = ref(new Set());
const notice = ref('');
const actionError = ref('');
const checklistNavigationError = ref('');
const showAdminMenu = ref(false);
const adminActiveTab = ref('history');
let notificationTimer;

const adminLogin = reactive({ password: '' });
const templateForm = reactive({ task_name: '', session: 'morning' });
const templateEditForm = reactive({ task_name: '', session: 'morning' });

const adminLoginError = ref('');
const isAuthorizing = ref(false);
const isCreatingTemplate = ref(false);
const editingTemplate = ref(null);
const templateEditorError = ref('');
const isUpdatingTemplate = ref(false);
const isDeletingTemplate = ref(false);
const isConfirmingTemplateDeletion = ref(false);

const isAdmin = computed(() => Boolean(props.auth?.isAdmin));
const templates = computed(() => collectionItems(props.templates));
const completedEntries = computed(() => collectionItems(props.completedTasks));
const completedPagination = computed(() => {
    if (Array.isArray(props.completedTasks)) {
        return null;
    }

    return props.completedTasks?.meta ?? null;
});

const displayDate = computed(() => formatChecklistDate(selectedDate.value));
const displayAdminDate = computed(() => formatChecklistDate(adminDate.value));
const isAdminDateToday = computed(() => {
    const today = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Kuala_Lumpur',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
    }).format(new Date());
    return adminDate.value === today;
});
const progressPercentage = computed(() => {
    if (!localTasks.value.length) {
        return 0;
    }

    const completedCount = localTasks.value.filter((task) => task.completed).length;

    return Math.round((completedCount / localTasks.value.length) * 100);
});

const adminCompletedCount = computed(() => {
    return completedEntries.value.filter((entry) => entry.isCompleted).length;
});

const adminProgressPercentage = computed(() => {
    if (!completedEntries.value.length) {
        return 0;
    }
    return Math.round((adminCompletedCount.value / completedEntries.value.length) * 100);
});
const completedTaskCount = computed(() => localTasks.value.filter((task) => task.completed).length);
const isChecklistLocked = computed(() => props.isReadOnly || isNavigating.value);
const isTemplateEditorProcessing = computed(() => isUpdatingTemplate.value || isDeletingTemplate.value);

watch(
    () => [props.tasks, props.currentDate],
    () => {
        localTasks.value = props.tasks.map(normalizeTask);
        selectedDate.value = props.currentDate;
        adminDate.value = props.currentDate;

        // Auto-collapse completed sessions by default
        collapsedChecklistSessions.value.clear();
        ['morning', 'afternoon', 'evening'].forEach(key => {
            const tasks = tasksFor(key);
            if (tasks.length && tasks.every(t => t.completed)) {
                collapsedChecklistSessions.value.add(key);
            }
        });
        collapsedChecklistSessions.value = new Set(collapsedChecklistSessions.value);
    },
    { immediate: true },
);

const isChecklistDateToday = computed(() => {
    const today = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Kuala_Lumpur',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
    }).format(new Date());
    return selectedDate.value === today;
});

const slideDirection = ref('slide-next');
const collapsedChecklistSessions = ref(new Set());
const collapsedHistorySessions = ref(new Set());

function toggleChecklistSession(key) {
    if (collapsedChecklistSessions.value.has(key)) {
        collapsedChecklistSessions.value.delete(key);
    } else {
        collapsedChecklistSessions.value.add(key);
    }
    collapsedChecklistSessions.value = new Set(collapsedChecklistSessions.value);
}

function toggleHistorySession(key) {
    if (collapsedHistorySessions.value.has(key)) {
        collapsedHistorySessions.value.delete(key);
    } else {
        collapsedHistorySessions.value.add(key);
    }
    collapsedHistorySessions.value = new Set(collapsedHistorySessions.value);
}

function isChecklistSessionCollapsible(sessionKey) {
    const tasks = tasksFor(sessionKey);
    if (!tasks.length) return false;
    const allCompleted = tasks.every((task) => task.completed);
    if (!allCompleted && collapsedChecklistSessions.value.has(sessionKey)) {
        collapsedChecklistSessions.value.delete(sessionKey);
        collapsedChecklistSessions.value = new Set(collapsedChecklistSessions.value);
    }
    return allCompleted;
}

function isHistorySessionCollapsible(sessionKey) {
    const entries = historyFor(sessionKey);
    if (!entries.length) return false;
    const allCompleted = entries.every((entry) => entry.isCompleted);
    if (!allCompleted && collapsedHistorySessions.value.has(sessionKey)) {
        collapsedHistorySessions.value.delete(sessionKey);
        collapsedHistorySessions.value = new Set(collapsedHistorySessions.value);
    }
    return allCompleted;
}

watch(selectedDate, () => {
    collapsedChecklistSessions.value.clear();
    ['morning', 'afternoon', 'evening'].forEach(key => {
        const tasks = tasksFor(key);
        if (tasks.length && tasks.every(t => t.completed)) {
            collapsedChecklistSessions.value.add(key);
        }
    });
    collapsedChecklistSessions.value = new Set(collapsedChecklistSessions.value);
});

watch(adminDate, () => {
    collapsedHistorySessions.value.clear();
    collapsedHistorySessions.value = new Set(collapsedHistorySessions.value);
});

watch(
    () => [props.mode, Boolean(props.auth?.isAdmin), page.url],
    ([mode, adminSession]) => {
        if (['welcome', 'checklist', 'admin'].includes(mode)) {
            screen.value = mode;
            return;
        }

        if (adminSession) {
            screen.value = 'admin';
            return;
        }

        if (!adminSession && screen.value !== 'admin-login') {
            screen.value = 'welcome';
        }
    },
    { immediate: true },
);

function resolveInitialScreen() {
    if (['welcome', 'checklist', 'admin'].includes(props.mode)) {
        return props.mode;
    }

    if (props.auth?.isAdmin) {
        return 'admin';
    }

    return 'welcome';
}

function collectionItems(value) {
    if (Array.isArray(value)) {
        return value;
    }

    return Array.isArray(value?.data) ? value.data : [];
}

function normalizeTask(task) {
    return {
        id: task.id,
        text: task.text,
        section: task.section,
        completed: Boolean(task.completed),
    };
}

function sessionMeta(value) {
    return sessions[value] ?? unknownSession;
}

function sessionPillClass(value) {
    return sessionMeta(value).pillClass;
}

function sessionTextClass(value) {
    if (value === 'morning') return 'text-amber-400';
    if (value === 'afternoon') return 'text-sky-400';
    if (value === 'evening') return 'text-violet-400';
    return 'text-zinc-400';
}

function templateSession(template) {
    return template.session ?? template.section ?? '';
}

function completedEntrySession(entry) {
    return entry.section ?? entry.session ?? '';
}

function firstError(errors, fallback) {
    const messages = Object.values(errors ?? {}).flat();

    return messages[0] ?? fallback;
}

function setNotice(message) {
    notice.value = message;
    actionError.value = '';
    window.clearTimeout(notificationTimer);
    notificationTimer = window.setTimeout(() => {
        notice.value = '';
    }, 4000);
}

function setActionError(errors, fallback) {
    actionError.value = firstError(errors, fallback);
    notice.value = '';
}

function tasksFor(section) {
    return localTasks.value.filter((task) => task.section === section);
}

function templatesFor(section) {
    return templates.value.filter((template) => templateSession(template) === section);
}

function historyFor(section) {
    return completedEntries.value.filter((entry) => completedEntrySession(entry) === section);
}

function formatChecklistDate(value) {
    if (!isDateInput(value)) {
        return 'Pilih tarikh';
    }

    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day, 12));

    return new Intl.DateTimeFormat('ms-MY', {
        weekday: 'long',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'Asia/Kuala_Lumpur',
    }).format(date);
}

function formatCompletedAt(value) {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('ms-MY', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Kuala_Lumpur',
    }).format(date);
}

function isDateInput(value) {
    return /^\d{4}-\d{2}-\d{2}$/.test(value ?? '');
}

function dateWithOffset(value, days) {
    if (!isDateInput(value)) {
        return '';
    }

    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(year, month - 1, day, 12);
    date.setDate(date.getDate() + days);

    return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
}

function showAdminLogin() {
    if (isAdmin.value) {
        openAdmin();
        return;
    }

    adminLoginError.value = '';
    screen.value = 'admin-login';
}

function openTodayChecklist() {
    if (isOpeningChecklist.value) {
        return;
    }

    checklistNavigationError.value = '';
    isOpeningChecklist.value = true;
    visitChecklist({ fromWelcome: true });
}

function openChecklistForDate(date) {
    if (!isDateInput(date)) {
        setActionError({}, 'Tarikh senarai semak tidak sah.');
        return;
    }

    visitChecklist({ date });
}

function visitChecklist({ date = null, fromWelcome = false } = {}) {
    const data = date === null ? {} : { date };
    const navigationFailure = 'Senarai semak tidak dapat dibuka. Sila cuba semula.';
    const showNavigationFailure = (errors = {}) => {
        if (fromWelcome) {
            checklistNavigationError.value = firstError(errors, navigationFailure);
            return;
        }

        setActionError(errors, navigationFailure);
    };

    router.get('/checklist', data, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => {
            isNavigating.value = true;
            syncState.value = 'saving';
        },
        onError: (errors) => {
            showNavigationFailure(errors);
        },
        onHttpException: () => {
            showNavigationFailure();
            return false;
        },
        onNetworkError: () => {
            showNavigationFailure();
            return false;
        },
        onFinish: () => {
            isNavigating.value = false;
            isOpeningChecklist.value = false;
            syncState.value = 'connected';
        },
    });
}

function adjustChecklistDate(offset) {
    slideDirection.value = offset > 0 ? 'slide-next' : 'slide-prev';
    const nextDate = dateWithOffset(selectedDate.value, offset);

    if (nextDate) {
        openChecklistForDate(nextDate);
    }
}

function resetToToday() {
    const today = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Kuala_Lumpur',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
    }).format(new Date());

    if (selectedDate.value > today) {
        slideDirection.value = 'slide-prev';
    } else if (selectedDate.value < today) {
        slideDirection.value = 'slide-next';
    }
    visitChecklist();
}

function exitChecklist() {
    if (isExitingChecklist.value || isNavigating.value || syncState.value === 'saving') {
        return;
    }

    isExitingChecklist.value = true;
    const exitFailure = 'Tidak dapat log keluar daripada senarai semak. Sila cuba semula.';
    const options = {
        preserveScroll: false,
        onError: (errors) => setActionError(errors, exitFailure),
        onHttpException: () => {
            setActionError({}, exitFailure);
            return false;
        },
        onNetworkError: () => {
            setActionError({}, exitFailure);
            return false;
        },
        onFinish: () => {
            isExitingChecklist.value = false;
        },
    };

    if (isAdmin.value) {
        router.post('/admin/logout', {}, options);
        return;
    }

    router.get('/', {}, options);
}

function submitAdminLogin() {
    adminLoginError.value = '';
    isAuthorizing.value = true;

    router.post('/admin/login', { password: adminLogin.password }, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => {
            adminLoginError.value = firstError(errors, 'Kata laluan pentadbir tidak diterima.');
        },
        onFinish: () => {
            isAuthorizing.value = false;
            adminLogin.password = '';
        },
    });
}

function toggleTask(task) {
    if (isChecklistLocked.value || savingTaskIds.value.has(task.id) || !selectedDate.value) {
        return;
    }

    const previousValue = task.completed;
    const nextValue = !previousValue;
    task.completed = nextValue;
    setTaskSaving(task.id, true);
    syncState.value = 'saving';

    router.post('/tasks/toggle', {
        task_id: task.id,
        date: selectedDate.value,
        is_completed: nextValue,
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            setNotice(nextValue ? 'Tugasan ditandakan selesai.' : 'Tugasan ditandakan belum selesai.');
        },
        onError: (errors) => {
            task.completed = previousValue;
            setActionError(errors, 'Senarai semak tidak dapat dikemas kini. Perubahan anda tidak disimpan.');
        },
        onFinish: () => {
            setTaskSaving(task.id, false);
            syncState.value = 'connected';
        },
    });
}

function setTaskSaving(taskId, saving) {
    const updated = new Set(savingTaskIds.value);

    if (saving) {
        updated.add(taskId);
    } else {
        updated.delete(taskId);
    }

    savingTaskIds.value = updated;
}

function openAdmin(date = null) {
    const data = date ? { date } : {};

    router.get('/admin', data, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => {
            isNavigating.value = true;
        },
        onFinish: () => {
            isNavigating.value = false;
        },
    });
}

function adjustAdminDate(offset) {
    slideDirection.value = offset > 0 ? 'slide-next' : 'slide-prev';
    const nextDate = dateWithOffset(adminDate.value, offset);

    if (nextDate) {
        openAdmin(nextDate);
    }
}

function resetAdminDate() {
    const today = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Kuala_Lumpur',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
    }).format(new Date());

    if (adminDate.value > today) {
        slideDirection.value = 'slide-prev';
    } else if (adminDate.value < today) {
        slideDirection.value = 'slide-next';
    }
    openAdmin();
}

function logoutAdmin() {
    router.post('/admin/logout', {}, {
        preserveScroll: true,
        onFinish: () => {
            screen.value = 'welcome';
        },
    });
}

function createTemplate() {
    actionError.value = '';
    isCreatingTemplate.value = true;

    router.post('/admin/templates', { ...templateForm }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            templateForm.task_name = '';
            templateForm.session = 'morning';
            setNotice('Templat tugasan ditambah pada senarai hari ini dan akan datang.');
        },
        onError: (errors) => setActionError(errors, 'Templat tugasan tidak dapat ditambah.'),
        onFinish: () => {
            isCreatingTemplate.value = false;
        },
    });
}

function openTemplateEditor(template) {
    editingTemplate.value = template;
    Object.assign(templateEditForm, {
        task_name: template.taskName ?? template.task_name ?? template.text ?? '',
        session: templateSession(template) || 'morning',
    });
    templateEditorError.value = '';
    isConfirmingTemplateDeletion.value = false;
}

function closeTemplateEditor(force = false) {
    if (!force && isTemplateEditorProcessing.value) {
        return;
    }

    editingTemplate.value = null;
    templateEditorError.value = '';
    isConfirmingTemplateDeletion.value = false;
    Object.assign(templateEditForm, { task_name: '', session: 'morning' });
}

function updateTemplate() {
    if (!editingTemplate.value || isTemplateEditorProcessing.value) {
        return;
    }

    templateEditorError.value = '';
    isUpdatingTemplate.value = true;

    router.patch(`/admin/templates/${editingTemplate.value.id}`, { ...templateEditForm }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            setNotice('Templat tugasan berjaya dikemas kini.');
            closeTemplateEditor(true);
        },
        onError: (errors) => {
            templateEditorError.value = firstError(errors, 'Templat tugasan tidak dapat dikemas kini.');
        },
        onHttpException: () => {
            templateEditorError.value = 'Templat tugasan tidak dapat dikemas kini. Sila cuba semula.';
            return false;
        },
        onNetworkError: () => {
            templateEditorError.value = 'Sambungan rangkaian gagal. Sila cuba semula.';
            return false;
        },
        onFinish: () => {
            isUpdatingTemplate.value = false;
        },
    });
}

function showTemplateDeletionConfirmation() {
    if (!isTemplateEditorProcessing.value) {
        templateEditorError.value = '';
        isConfirmingTemplateDeletion.value = true;
    }
}

function cancelTemplateDeletion() {
    if (!isDeletingTemplate.value) {
        isConfirmingTemplateDeletion.value = false;
        templateEditorError.value = '';
    }
}

function deleteTemplate() {
    if (!editingTemplate.value || isTemplateEditorProcessing.value) {
        return;
    }

    templateEditorError.value = '';
    isDeletingTemplate.value = true;

    router.delete(`/admin/templates/${editingTemplate.value.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            setNotice('Templat tugasan berjaya dipadam.');
            closeTemplateEditor(true);
        },
        onError: (errors) => {
            templateEditorError.value = firstError(errors, 'Templat tugasan tidak dapat dipadam.');
        },
        onHttpException: () => {
            templateEditorError.value = 'Templat tugasan tidak dapat dipadam. Sila cuba semula.';
            return false;
        },
        onNetworkError: () => {
            templateEditorError.value = 'Sambungan rangkaian gagal. Sila cuba semula.';
            return false;
        },
        onFinish: () => {
            isDeletingTemplate.value = false;
        },
    });
}

function completedTaskText(entry) {
    return entry.text ?? entry.task_name ?? 'Tugasan senarai semak';
}

function completedBy(entry) {
    const completedByUser = entry.completedBy ?? entry.completed_by ?? entry.completed_by_user;

    if (completedByUser && typeof completedByUser === 'object') {
        return completedByUser.name ?? completedByUser.username ?? 'Kakitangan tidak diketahui';
    }

    return completedByUser ?? 'Cleaner';
}
</script>

<template>
    <div class="min-h-screen bg-zinc-950 p-0 font-sans text-zinc-100 antialiased sm:p-4">
        <main class="relative mx-auto flex min-h-screen w-full max-w-md flex-col overflow-hidden bg-[#121212] sm:min-h-[840px] sm:max-h-[900px] sm:rounded-[36px] sm:border sm:border-zinc-700 sm:shadow-[0_24px_64px_rgba(0,0,0,0.8)]">

            <div v-if="actionError" class="absolute inset-x-4 top-5 z-[60]" aria-live="polite">
                <p
                    class="rounded-xl border border-[#ED4264]/30 bg-[#ED4264]/10 text-rose-200 px-3 py-2 text-center text-xs font-medium shadow-lg"
                >
                    {{ actionError }}
                </p>
            </div>

            <section v-if="screen === 'welcome'" class="flex flex-1 flex-col justify-between px-6 py-12 text-center">
                <div class="flex flex-1 flex-col items-center justify-center space-y-6">
                    <div class="w-20 rounded-2xl bg-gradient-to-tr from-[#ED4264] to-[#FFEDBC] p-0.5 shadow-lg shadow-[#ED4264]/20">
                        <div class="flex aspect-square items-center justify-center rounded-2xl bg-[#121212]">
                            <svg class="h-10 w-10 text-zinc-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-6 9 2 2 4-4" />
                            </svg>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <h1 class="bg-gradient-to-r from-[#ED4264] to-[#FFEDBC] bg-clip-text text-4xl font-extrabold tracking-tight text-transparent">FF SPOTLESS</h1>
                        <p class="mx-auto max-w-xs text-sm text-zinc-400">FF Studios Cleaner Checklist</p>
                    </div>
                </div>

                <div class="space-y-4">
                    <button
                        type="button"
                        :disabled="isOpeningChecklist"
                        class="flex h-14 w-full items-center justify-center gap-3 rounded-2xl bg-gradient-to-r from-[#ED4264] to-[#FFEDBC] font-bold text-zinc-950 shadow-lg shadow-[#ED4264]/10 transition active:scale-[0.98] disabled:cursor-wait disabled:opacity-60"
                        :aria-busy="isOpeningChecklist"
                        @click="openTodayChecklist()"
                    >
                        <span>{{ isOpeningChecklist ? 'Membuka senarai semak…' : 'Buka senarai semak' }}</span>
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m13 7 5 5m0 0-5 5m5-5H6" /></svg>
                    </button>

                    <p v-if="checklistNavigationError" class="rounded-lg border border-[#ED4264]/20 bg-[#ED4264]/5 px-3 py-2 text-center text-xs font-medium text-rose-200" aria-live="polite">
                        {{ checklistNavigationError }}
                    </p>

                    <button
                        type="button"
                        class="flex h-14 w-full items-center justify-center gap-3 rounded-2xl border border-zinc-700 bg-zinc-900 text-sm font-semibold text-zinc-300 transition hover:bg-zinc-800 active:scale-[0.98]"
                        @click="showAdminLogin"
                    >
                        {{ isAdmin ? 'Buka papan pemuka pentadbir' : 'Admin Access' }}
                    </button>
                </div>
            </section>

            <section v-else-if="screen === 'admin-login'" class="flex flex-1 flex-col justify-between px-6 py-12">
                <button type="button" class="inline-flex w-fit items-center gap-2 p-2 -ml-2 text-sm font-medium text-zinc-400 transition hover:text-zinc-200" @click="screen = 'welcome'; adminLoginError = ''">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m15 19-7-7 7-7" /></svg>
                    Kembali
                </button>

                <form class="flex flex-1 flex-col justify-center space-y-6" @submit.prevent="submitAdminLogin">
                    <div class="space-y-2 text-center">
                        <h2 class="text-2xl font-bold text-zinc-100">Admin Access</h2>
                        <p class="text-xs text-zinc-400">Sahkan kata laluan utama untuk mengurus tetapan premis.</p>
                    </div>

                    <div class="space-y-4">
                        <label class="block">
                            <span class="mb-2 block text-xs font-semibold uppercase tracking-wider text-zinc-500">Kata laluan akses</span>
                            <input v-model="adminLogin.password" name="password" type="password" autocomplete="current-password" required class="h-14 w-full rounded-xl border border-zinc-700 bg-zinc-900/60 px-4 text-center tracking-widest text-zinc-100 placeholder-zinc-700 outline-none transition focus:border-[#ED4264]" placeholder="••••••••">
                        </label>
                        <p v-if="adminLoginError" class="rounded-lg border border-[#ED4264]/20 bg-[#ED4264]/5 px-3 py-2 text-center text-xs font-medium text-rose-200">{{ adminLoginError }}</p>
                    </div>

                    <button type="submit" :disabled="isAuthorizing" class="h-14 w-full rounded-2xl bg-gradient-to-r from-[#ED4264] to-[#FFEDBC] font-bold text-zinc-950 shadow-lg transition active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50">
                        {{ isAuthorizing ? 'Mengesahkan…' : 'Sahkan & masuk' }}
                    </button>
                </form>
            </section>

            <section v-else-if="screen === 'checklist'" class="flex min-h-0 flex-1 flex-col">
                <header class="sticky top-0 z-20 flex items-center justify-between border-b border-zinc-800 bg-[#121212]/95 px-5 pb-4 pt-6 backdrop-blur-md">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold tracking-wide text-zinc-500">FF SPOTLESS</p>
                        <p class="truncate text-sm font-semibold text-zinc-200">Hello, Kak Jihan!</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold" :class="syncState === 'saving' ? 'border-amber-500/25 bg-amber-500/10 text-amber-300' : 'border-emerald-500/25 bg-emerald-500/10 text-emerald-400'">
                            <span class="h-1.5 w-1.5 rounded-full" :class="syncState === 'saving' ? 'animate-pulse bg-amber-300' : 'bg-emerald-400'"></span>
                            {{ syncState === 'saving' ? 'Menyimpan' : 'Status: OK' }}
                        </span>
                        <button
                            type="button"
                            :disabled="isExitingChecklist || isNavigating || syncState === 'saving'"
                            class="rounded-lg border border-red-400 bg-red-600 px-2.5 py-1.5 text-[11px] font-bold text-white shadow-sm shadow-red-950/40 transition hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="exitChecklist()"
                        >
                            {{ isExitingChecklist ? 'Keluar…' : 'Log keluar' }}
                        </button>
                    </div>
                </header>

                <section class="relative border-b border-zinc-800/60 bg-[#121212] px-5 pb-10 pt-2">
                    <div class="flex items-center justify-between gap-3">
                        <button type="button" :disabled="isNavigating" class="rounded-xl border border-zinc-700 bg-zinc-900 p-3 text-zinc-400 transition hover:text-zinc-200 disabled:opacity-50" aria-label="Hari sebelumnya" @click="adjustChecklistDate(-1)">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m15 19-7-7 7-7" /></svg>
                        </button>
                        <div class="min-w-0 text-center">
                            <div class="h-5 flex items-center justify-center">
                                <Transition :name="slideDirection" mode="out-in">
                                    <p :key="selectedDate" class="truncate text-sm font-bold text-zinc-200">{{ displayDate }}</p>
                                </Transition>
                            </div>
                        </div>
                        <button type="button" :disabled="isNavigating" class="rounded-xl border border-zinc-700 bg-zinc-900 p-3 text-zinc-400 transition hover:text-zinc-200 disabled:opacity-50" aria-label="Hari berikutnya" @click="adjustChecklistDate(1)">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" /></svg>
                        </button>
                    </div>
                    <button v-if="!isChecklistDateToday" type="button" class="absolute bottom-3 left-1/2 -translate-x-1/2 inline-flex items-center rounded-full bg-gradient-to-r from-[#ED4264] to-[#FFEDBC] px-4 py-1.5 text-[10px] font-extrabold uppercase tracking-wider text-zinc-950 shadow-md shadow-rose-950/20 transition hover:brightness-110 active:scale-95 animate-button-glow" @click="resetToToday">Ke hari ini</button>
                </section>

                <div class="h-1 bg-zinc-900"><div class="h-full bg-gradient-to-r from-[#ED4264] to-[#FFEDBC] transition-all duration-500" :style="{ width: `${progressPercentage}%` }"></div></div>

                <div class="flex items-center justify-between px-5 pt-4 text-xs text-zinc-400">
                    <span>{{ completedTaskCount }} daripada {{ localTasks.length }} selesai</span>
                    <span class="font-semibold text-zinc-300">{{ progressPercentage }}%</span>
                </div>

                <main class="min-h-0 flex-1 overflow-y-auto px-5 py-5">
                    <Transition :name="slideDirection" mode="out-in">
                        <div :key="selectedDate" class="space-y-7">
                            <div v-if="isChecklistLocked" class="flex gap-3 rounded-xl border border-zinc-700 bg-zinc-900/50 p-3.5 text-xs text-zinc-400">
                                <svg class="mt-0.5 h-5 w-5 shrink-0 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2Zm10-10V7a4 4 0 0 0-8 0v4h8Z" /></svg>
                                <div>
                                    <p class="font-bold text-zinc-300">Paparan dikunci</p>
                                    <p class="mt-0.5">Senarai semak lampau dan masa hadapan adalah baca sahaja.</p>
                                </div>
                            </div>

                            <div v-if="!localTasks.length" class="rounded-2xl border border-dashed border-zinc-700 bg-zinc-900/30 px-5 py-10 text-center">
                                <p class="text-sm font-semibold text-zinc-300">Tiada tugasan untuk tarikh ini</p>
                                <p class="mt-2 text-xs leading-relaxed text-zinc-500">Admin boleh menambah template tugasan melalui admin dashboard.</p>
                            </div>

                            <div v-else class="space-y-7">
                                <section v-for="section in sections" :key="section.key" v-show="tasksFor(section.key).length" class="space-y-3">
                                    <component
                                        :is="isChecklistSessionCollapsible(section.key) ? 'button' : 'h2'"
                                        :type="isChecklistSessionCollapsible(section.key) ? 'button' : undefined"
                                        class="w-full px-1 flex items-center justify-between text-base font-black uppercase tracking-wider text-left transition duration-200 outline-none"
                                        :class="[
                                            sessionTextClass(section.key),
                                            isChecklistSessionCollapsible(section.key) ? 'cursor-pointer hover:opacity-85' : ''
                                        ]"
                                        @click="isChecklistSessionCollapsible(section.key) ? toggleChecklistSession(section.key) : null"
                                    >
                                        <div class="flex items-center gap-2">
                                            <svg v-if="section.key === 'morning'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l.707.707M12 8a4 4 0 100 8 4 4 0 000-8z" /></svg>
                                            <svg v-else-if="section.key === 'afternoon'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" /></svg>
                                            <svg v-else-if="section.key === 'evening'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" /></svg>
                                            <span v-else>•</span>
                                            <span>{{ section.groupLabel }}</span>
                                        </div>
                                        <div v-if="isChecklistSessionCollapsible(section.key)" class="p-1 text-zinc-400 transition-colors duration-200">
                                            <svg class="h-4 w-4 transition-transform duration-200" :class="collapsedChecklistSessions.has(section.key) ? '-rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </div>
                                    </component>
                                    <div v-show="!collapsedChecklistSessions.has(section.key)" class="space-y-2">
                                        <button
                                            v-for="task in tasksFor(section.key)"
                                            :key="task.id"
                                            type="button"
                                            :disabled="isChecklistLocked || savingTaskIds.has(task.id)"
                                            :aria-pressed="task.completed"
                                            class="flex w-full items-center justify-between gap-4 rounded-2xl border p-4 text-left transition active:scale-[0.99] disabled:cursor-not-allowed"
                                            :class="isChecklistLocked ? 'border-zinc-800 bg-zinc-900/20 opacity-45' : task.completed ? 'border-[#ED4264]/20 bg-zinc-900/50' : 'border-zinc-700/70 bg-zinc-900 hover:bg-zinc-800/90'"
                                            @click="toggleTask(task)"
                                        >
                                            <span class="text-sm font-medium leading-relaxed transition" :class="task.completed ? 'text-zinc-500 line-through decoration-zinc-700' : 'text-zinc-200'">{{ task.text }}</span>
                                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-xl border-2 transition" :class="task.completed ? 'border-[#ED4264] bg-[#ED4264] text-white' : 'border-zinc-600 text-transparent'">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" /></svg>
                                            </span>
                                        </button>
                                    </div>
                                </section>
                            </div>
                        </div>
                    </Transition>
                </main>
            </section>

            <section v-else-if="screen === 'admin'" class="flex min-h-0 flex-1 flex-col">
                <header class="sticky top-0 z-20 flex items-center justify-between border-b border-zinc-800 bg-[#121212]/95 px-5 pb-4 pt-6 backdrop-blur-md">
                    <div>
                        <p class="text-xs font-semibold tracking-wide text-[#FFB0BE]">FF SPOTLESS</p>
                        <h1 class="text-base font-bold text-zinc-100">Admin Dashboard</h1>
                    </div>
                    <div class="relative">
                        <button
                            type="button"
                            class="rounded-lg border border-zinc-700 p-2 text-zinc-300 transition hover:bg-zinc-800"
                            :aria-expanded="showAdminMenu"
                            aria-label="Menu Pentadbir"
                            @click="showAdminMenu = !showAdminMenu"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>

                        <Transition
                            enter-active-class="transition duration-100 ease-out"
                            enter-from-class="transform scale-95 opacity-0"
                            enter-to-class="transform scale-100 opacity-100"
                            leave-active-class="transition duration-75 ease-in"
                            leave-from-class="transform scale-100 opacity-100"
                            leave-to-class="transform scale-95 opacity-0"
                        >
                            <div v-if="showAdminMenu" class="absolute right-0 top-11 z-30 w-48 origin-top-right rounded-2xl border border-zinc-600 bg-zinc-900 p-2 shadow-2xl">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2.5 rounded-xl px-4 py-3 text-left text-sm font-semibold transition"
                                    :class="adminActiveTab === 'history' ? 'bg-[#ED4264]/10 text-[#FFB0BE]' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white'"
                                    @click="adminActiveTab = 'history'; showAdminMenu = false"
                                >
                                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2z" />
                                    </svg>
                                    <span>Rekod Sejarah</span>
                                </button>
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2.5 rounded-xl px-4 py-3 text-left text-sm font-semibold transition"
                                    :class="adminActiveTab === 'templates' ? 'bg-[#ED4264]/10 text-[#FFB0BE]' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white'"
                                    @click="adminActiveTab = 'templates'; showAdminMenu = false"
                                >
                                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                                    </svg>
                                    <span>Urus Tugasan</span>
                                </button>
                                <div class="my-1 border-t border-zinc-800"></div>
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2.5 rounded-xl px-4 py-3 text-left text-sm font-bold text-rose-400 transition hover:bg-red-500/10 hover:text-rose-300"
                                    @click="logoutAdmin(); showAdminMenu = false"
                                >
                                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                    </svg>
                                    <span>Log keluar</span>
                                </button>
                            </div>
                        </Transition>
                    </div>
                </header>

                <main class="min-h-0 flex-1 overflow-y-auto px-5 py-5">
                    <div v-if="adminActiveTab === 'history'" class="mb-7 space-y-4">
                        <!-- Date Header Wrapper -->
                        <div class="relative rounded-2xl border border-zinc-700 bg-zinc-900/45 px-4 pb-10 pt-2.5">
                            <div class="flex items-center justify-between gap-3">
                                <button type="button" :disabled="isNavigating" class="rounded-lg border border-zinc-700 bg-[#121212] p-2.5 text-zinc-400 disabled:opacity-50" aria-label="Tarikh penyelesaian sebelumnya" @click="adjustAdminDate(-1)">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m15 19-7-7 7-7" /></svg>
                                </button>
                                <div class="min-w-0 text-center">
                                    <h2 class="truncate text-xs font-semibold tracking-wide text-zinc-500 uppercase">History</h2>
                                    <div class="h-5 flex items-center justify-center mt-0.5">
                                        <Transition :name="slideDirection" mode="out-in">
                                            <p :key="adminDate" class="truncate text-sm font-bold text-zinc-200 transition-colors duration-300" :class="isAdminDateToday ? 'text-[#ED4264] font-semibold' : 'text-zinc-200'">{{ displayAdminDate }}</p>
                                        </Transition>
                                    </div>
                                </div>
                                <button type="button" :disabled="isNavigating" class="rounded-lg border border-zinc-700 bg-[#121212] p-2.5 text-zinc-400 disabled:opacity-50" aria-label="Tarikh penyelesaian berikutnya" @click="adjustAdminDate(1)">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" /></svg>
                                </button>
                            </div>
                            <button v-if="!isAdminDateToday" type="button" class="absolute bottom-2.5 left-1/2 -translate-x-1/2 inline-flex items-center rounded-full bg-gradient-to-r from-[#ED4264] to-[#FFEDBC] px-4 py-1.5 text-[10px] font-extrabold uppercase tracking-wider text-zinc-950 shadow-md shadow-rose-950/20 transition hover:brightness-110 active:scale-95 animate-button-glow" @click="resetAdminDate">Ke hari ini</button>
                        </div>

                        <!-- History Content Card -->
                        <div class="rounded-2xl border border-zinc-700 bg-zinc-900/45 p-4">
                            <Transition :name="slideDirection" mode="out-in">
                                <div :key="adminDate" class="space-y-4">
                                    <div v-if="completedEntries.length">
                                        <div class="border-b border-zinc-800 pb-4">
                                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-800">
                                                <div class="h-full bg-gradient-to-r from-[#ED4264] to-[#FFEDBC] transition-all duration-500" :style="{ width: `${adminProgressPercentage}%` }"></div>
                                            </div>
                                            <div class="mt-2 flex items-center justify-between text-xs text-zinc-400">
                                                <span>{{ adminCompletedCount }} daripada {{ completedEntries.length }} selesai</span>
                                                <span class="font-semibold text-zinc-300">{{ adminProgressPercentage }}%</span>
                                            </div>
                                        </div>

                                        <div class="mt-4 space-y-6">
                                            <div v-for="section in sections" :key="section.key" v-show="historyFor(section.key).length" class="space-y-2">
                                                <component
                                                    :is="isHistorySessionCollapsible(section.key) ? 'button' : 'h3'"
                                                    :type="isHistorySessionCollapsible(section.key) ? 'button' : undefined"
                                                    class="w-full px-1 flex items-center justify-between text-base font-black uppercase tracking-wider text-left transition duration-200 outline-none"
                                                    :class="[
                                                        sessionTextClass(section.key),
                                                        isHistorySessionCollapsible(section.key) ? 'cursor-pointer hover:opacity-85' : ''
                                                    ]"
                                                    @click="isHistorySessionCollapsible(section.key) ? toggleHistorySession(section.key) : null"
                                                >
                                                    <div class="flex items-center gap-2">
                                                        <svg v-if="section.key === 'morning'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l.707.707M12 8a4 4 0 100 8 4 4 0 000-8z" /></svg>
                                                        <svg v-else-if="section.key === 'afternoon'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" /></svg>
                                                        <svg v-else-if="section.key === 'evening'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" /></svg>
                                                        <span v-else>•</span>
                                                        <span>{{ section.groupLabel }}</span>
                                                    </div>
                                                    <div v-if="isHistorySessionCollapsible(section.key)" class="p-1 text-zinc-400 transition-colors duration-200">
                                                        <svg class="h-4 w-4 transition-transform duration-200" :class="collapsedHistorySessions.has(section.key) ? '-rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                                        </svg>
                                                    </div>
                                                </component>
                                                <div v-show="!collapsedHistorySessions.has(section.key)" class="space-y-2">
                                                    <article v-for="entry in historyFor(section.key)" :key="entry.id" class="rounded-xl border border-zinc-700/80 bg-[#121212] px-3 py-3">
                                                        <div class="flex items-start justify-between gap-3">
                                                            <p class="min-w-0 text-sm font-semibold leading-snug text-[#ededec]" :class="entry.isCompleted ? '' : 'opacity-70'">{{ completedTaskText(entry) }}</p>
                                                            <span class="shrink-0 rounded-full border px-2.5 py-0.5 text-[9px] font-bold uppercase tracking-wide" :class="entry.isCompleted ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400' : 'border-zinc-600 bg-zinc-800 text-zinc-400'">
                                                                {{ entry.isCompleted ? 'Selesai' : 'Belum Selesai' }}
                                                            </span>
                                                        </div>
                                                        <div v-if="entry.isCompleted" class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-zinc-500">
                                                            <span>{{ formatCompletedAt(entry.completedAt ?? entry.completed_at) }}</span>
                                                            <span>{{ completedBy(entry) }}</span>
                                                        </div>
                                                    </article>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-else>
                                        <p class="rounded-xl border border-dashed border-zinc-700 px-3 py-5 text-center text-xs text-zinc-500">Tiada rekod tugasan untuk tarikh ini.</p>
                                    </div>
                                </div>
                            </Transition>
                        </div>
                        <p v-if="completedPagination?.total !== undefined && completedEntries.length" class="mt-3 text-center text-[11px] text-zinc-600">{{ completedPagination.total }} tugasan selesai direkodkan.</p>
                    </div>

                    <section v-else-if="adminActiveTab === 'templates'" class="mb-7 rounded-2xl border border-zinc-700 bg-zinc-900/45 p-4">
                        <div class="mb-4">
                            <h2 class="text-sm font-bold text-zinc-200">Tugasan</h2>
                            <p class="mt-1 text-xs leading-relaxed text-zinc-500">Template baharu ditambah pada senarai hari ini dan akan datang; rekod lampau kekal tidak berubah.</p>
                        </div>

                        <form class="space-y-3" @submit.prevent="createTemplate">
                            <label class="block">
                                <span class="mb-1.5 block text-xs font-semibold text-zinc-500">Nama tugasan</span>
                                <input v-model.trim="templateForm.task_name" maxlength="255" required class="h-11 w-full rounded-xl border border-zinc-700 bg-[#121212] px-3 text-sm text-zinc-100 outline-none transition focus:border-[#ED4264]" placeholder="cth. Nyahkuman pemegang pintu masuk">
                            </label>
                            <label class="block">
                                <span class="mb-1.5 block text-xs font-semibold text-zinc-500">Sesi</span>
                                <select v-model="templateForm.session" class="h-11 w-full rounded-xl border border-zinc-700 bg-[#121212] px-3 text-sm text-zinc-100 outline-none transition focus:border-[#ED4264]">
                                    <option v-for="section in sections" :key="section.key" :value="section.key">{{ section.label }}</option>
                                </select>
                            </label>
                            <button type="submit" :disabled="isCreatingTemplate" class="h-11 w-full rounded-xl bg-gradient-to-r from-[#ED4264] to-[#FFEDBC] text-sm font-bold text-zinc-950 transition active:scale-[0.98] disabled:opacity-50">{{ isCreatingTemplate ? 'Menambah…' : 'Tambah tugasan' }}</button>
                        </form>

                        <div v-if="templates.length" class="mt-5 space-y-6 border-t border-zinc-700 pt-4">
                            <div v-for="section in sections" :key="section.key" v-show="templatesFor(section.key).length" class="space-y-2">
                                <h3 class="px-1 flex items-center gap-2 text-base font-black uppercase tracking-wider" :class="sessionTextClass(section.key)">
                                    <svg v-if="section.key === 'morning'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l.707.707M12 8a4 4 0 100 8 4 4 0 000-8z" /></svg>
                                    <svg v-else-if="section.key === 'afternoon'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" /></svg>
                                    <svg v-else-if="section.key === 'evening'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" /></svg>
                                    <span v-else>•</span>
                                    <span>{{ section.groupLabel }}</span>
                                </h3>
                                <div class="space-y-2">
                                    <div v-for="template in templatesFor(section.key)" :key="template.id" class="flex items-start justify-between gap-3 rounded-xl bg-[#121212] px-3 py-2.5">
                                        <span class="min-w-0 flex-1 text-sm leading-snug text-zinc-300">{{ template.taskName ?? template.task_name ?? template.text }}</span>
                                        <button type="button" class="rounded-lg border border-zinc-600 p-1.5 text-zinc-300 transition hover:bg-zinc-700 hover:text-white" :aria-label="`Sunting ${template.taskName ?? template.task_name ?? template.text}`" @click="openTemplateEditor(template)">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p v-else class="mt-5 rounded-xl border border-dashed border-zinc-700 px-3 py-4 text-center text-xs text-zinc-500">Tiada templat lagi.</p>
                    </section>

                </main>

                <div
                    v-if="editingTemplate"
                    class="absolute inset-0 z-50 flex items-end bg-black/75 p-4 sm:items-center"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="template-editor-title"
                >
                    <form class="w-full rounded-2xl border border-zinc-600 bg-[#171717] p-5 shadow-2xl" @submit.prevent="updateTemplate">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 id="template-editor-title" class="text-base font-bold text-zinc-100">Edit tugasan</h2>
                                <p class="mt-1 text-xs leading-relaxed text-zinc-500">Rekod lampau dan tugasan yang telah selesai tidak akan diubah.</p>
                            </div>
                            <button
                                type="button"
                                :disabled="isTemplateEditorProcessing"
                                class="text-sm font-semibold text-zinc-500 hover:text-zinc-200 disabled:opacity-50"
                                @click="closeTemplateEditor()"
                            >
                                Tutup
                            </button>
                        </div>

                        <template v-if="!isConfirmingTemplateDeletion">
                            <div class="mt-4 space-y-3">
                                <label class="block">
                                    <span class="mb-1.5 block text-xs font-semibold text-zinc-500">Nama tugasan</span>
                                    <input
                                        v-model.trim="templateEditForm.task_name"
                                        maxlength="255"
                                        required
                                        autofocus
                                        class="h-11 w-full rounded-xl border border-zinc-700 bg-[#121212] px-3 text-sm text-zinc-100 outline-none transition focus:border-[#ED4264]"
                                    >
                                </label>
                                <label class="block">
                                    <span class="mb-1.5 block text-xs font-semibold text-zinc-500">Sesi</span>
                                    <select v-model="templateEditForm.session" class="h-11 w-full rounded-xl border border-zinc-700 bg-[#121212] px-3 text-sm text-zinc-100 outline-none transition focus:border-[#ED4264]">
                                        <option v-for="section in sections" :key="section.key" :value="section.key">{{ section.label }}</option>
                                    </select>
                                </label>
                            </div>

                            <p v-if="templateEditorError" class="mt-3 rounded-lg border border-[#ED4264]/20 bg-[#ED4264]/5 px-3 py-2 text-center text-xs font-medium text-rose-200" aria-live="polite">
                                {{ templateEditorError }}
                            </p>

                            <div class="mt-5 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <button
                                    type="button"
                                    :disabled="isTemplateEditorProcessing"
                                    class="h-11 rounded-xl border border-[#ED4264]/30 text-sm font-bold text-[#FFB0BE] transition hover:bg-[#ED4264]/10 disabled:opacity-50"
                                    @click="showTemplateDeletionConfirmation"
                                >
                                    Padam templat
                                </button>
                                <button
                                    type="submit"
                                    :disabled="isTemplateEditorProcessing"
                                    class="h-11 rounded-xl bg-gradient-to-r from-[#ED4264] to-[#FFEDBC] text-sm font-bold text-zinc-950 transition active:scale-[0.98] disabled:opacity-50"
                                >
                                    {{ isUpdatingTemplate ? 'Menyimpan…' : 'Simpan perubahan' }}
                                </button>
                            </div>
                        </template>

                        <template v-else>
                            <div class="mt-5 rounded-xl border border-[#ED4264]/25 bg-[#ED4264]/5 p-4">
                                <h3 class="text-sm font-bold text-rose-100">Padam templat ini?</h3>
                                <p class="mt-2 text-xs leading-relaxed text-rose-100/70">
                                    Templat akan dinyahaktifkan dan tugasan belum selesai untuk hari ini serta masa hadapan akan dibuang. Rekod lampau dan tugasan selesai akan dikekalkan.
                                </p>
                            </div>

                            <p v-if="templateEditorError" class="mt-3 rounded-lg border border-[#ED4264]/20 bg-[#ED4264]/5 px-3 py-2 text-center text-xs font-medium text-rose-200" aria-live="polite">
                                {{ templateEditorError }}
                            </p>

                            <div class="mt-5 grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    :disabled="isDeletingTemplate"
                                    class="h-11 rounded-xl border border-zinc-600 text-sm font-bold text-zinc-300 transition hover:bg-zinc-800 disabled:opacity-50"
                                    @click="cancelTemplateDeletion"
                                >
                                    Batal
                                </button>
                                <button
                                    type="button"
                                    :disabled="isDeletingTemplate"
                                    class="h-11 rounded-xl bg-[#ED4264] text-sm font-bold text-white transition active:scale-[0.98] disabled:opacity-50"
                                    @click="deleteTemplate"
                                >
                                    {{ isDeletingTemplate ? 'Memadam…' : 'Ya, padam' }}
                                </button>
                            </div>
                        </template>
                    </form>
                </div>

            </section>
        </main>
    </div>
</template>

<style scoped>
@keyframes button-glow {
    0%, 100% {
        box-shadow: 0 0 4px rgba(237, 66, 100, 0.25), 0 2px 4px rgba(0, 0, 0, 0.3);
    }
    50% {
        box-shadow: 0 0 16px rgba(237, 66, 100, 0.75), 0 2px 8px rgba(237, 66, 100, 0.4);
    }
}
.animate-button-glow {
    animation: button-glow 2.2s infinite ease-in-out;
}

.slide-next-enter-active,
.slide-next-leave-active,
.slide-prev-enter-active,
.slide-prev-leave-active {
    transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
}

.slide-next-enter-from {
    transform: translateX(16px);
    opacity: 0;
}
.slide-next-leave-to {
    transform: translateX(-16px);
    opacity: 0;
}

.slide-prev-enter-from {
    transform: translateX(-16px);
    opacity: 0;
}
.slide-prev-leave-to {
    transform: translateX(16px);
    opacity: 0;
}
</style>
