{{--
    La liste de la cloche (filament-notifications::components.database.modal.index),
    la même, sauf sa croix, posée ici dans l'en-tête à la place de celle de
    la fenêtre de Filament, à la même place : elle reste active pendant que
    la cloche regarde s'il y a du nouveau (voir
    App\Filament\CartesDesNotifications::intervalle()). Le bouton de
    Filament se désactivait pendant chaque regard (wire:loading.attr), et un
    clic tombé à ce moment se perdait ; la fenêtre de Filament ne laisse rien
    changer à sa croix. Sans notification, l'en-tête reprend l'icône, le
    titre et la phrase que la fenêtre de Filament y met.
--}}
@props([
    'notifications',
    'unreadNotificationsCount',
])

@php
    use Filament\Support\Enums\Alignment;

    $hasNotifications = $notifications->count();
    $isPaginated = $notifications instanceof \Illuminate\Contracts\Pagination\Paginator && $notifications->hasPages();
@endphp

<x-filament::modal
    :alignment="$hasNotifications ? null : Alignment::Center"
    :aria-labelledby="$hasNotifications ? null : 'database-notifications.heading'"
    :close-button="false"
    id="database-notifications"
    slide-over
    :sticky-header="$hasNotifications"
    width="md"
>
    <x-slot name="header">
        <div class="absolute end-6 top-6">
            <x-filament::icon-button
                color="gray"
                icon="heroicon-o-x-mark"
                icon-alias="modal.close-button"
                icon-size="lg"
                :label="__('filament::components/modal.actions.close.label')"
                tabindex="-1"
                x-on:click="$dispatch('close-modal', { id: 'database-notifications' })"
                :wire:loading.attr="false"
                class="fi-modal-close-btn"
            />
        </div>

        @if ($hasNotifications)
            <div>
                <x-filament-notifications::database.modal.heading
                    :unread-notifications-count="$unreadNotificationsCount"
                />

                <x-filament-notifications::database.modal.actions
                    :notifications="$notifications"
                    :unread-notifications-count="$unreadNotificationsCount"
                />
            </div>
        @else
            <div class="mb-5 flex items-center justify-center">
                <div class="fi-color-gray rounded-full bg-gray-100 p-3 dark:bg-gray-500/20">
                    <x-filament::icon
                        alias="notifications::database.modal.empty-state"
                        icon="heroicon-o-bell-slash"
                        class="fi-modal-icon h-6 w-6 text-gray-500 dark:text-gray-400"
                    />
                </div>
            </div>

            <div class="text-center">
                <x-filament::modal.heading id="database-notifications.heading">
                    {{ __('filament-notifications::database.modal.empty.heading') }}
                </x-filament::modal.heading>

                <x-filament::modal.description class="mt-2">
                    {{ __('filament-notifications::database.modal.empty.description') }}
                </x-filament::modal.description>
            </div>
        @endif
    </x-slot>

    @if ($hasNotifications)
        <div
            @class([
                '-mx-6 -mt-6 divide-y divide-gray-200 dark:divide-white/10',
                '-mb-6' => ! $isPaginated,
                'border-b border-gray-200 dark:border-white/10' => $isPaginated,
            ])
        >
            @foreach ($notifications as $notification)
                <div
                    @class([
                        'relative before:absolute before:start-0 before:h-full before:w-0.5 before:bg-primary-600 dark:before:bg-primary-500' => $notification->unread(),
                    ])
                >
                    {{ $this->getNotification($notification)->inline() }}
                </div>
            @endforeach
        </div>

        @if ($isPaginated)
            <x-slot name="footer">
                <x-filament::pagination :paginator="$notifications" />
            </x-slot>
        @endif
    @endif
</x-filament::modal>
