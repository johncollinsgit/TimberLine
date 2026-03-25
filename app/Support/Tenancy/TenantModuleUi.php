<?php

namespace App\Support\Tenancy;

class TenantModuleUi
{
    /**
     * @var array<string,array{label:string,tone:string,description:string}>
     */
    private const STATE_META = [
        'active' => [
            'label' => 'Active',
            'tone' => 'success',
            'description' => 'Included and configured for this tenant.',
        ],
        'setup_needed' => [
            'label' => 'Setup Needed',
            'tone' => 'attention',
            'description' => 'Included, but setup is not complete yet.',
        ],
        'locked' => [
            'label' => 'Locked',
            'tone' => 'critical',
            'description' => 'Not included in the current access level.',
        ],
        'coming_soon' => [
            'label' => 'Coming Soon',
            'tone' => 'info',
            'description' => 'Visible as planned surface, not live yet.',
        ],
    ];

    /**
     * @var array<string,string>
     */
    private const SETUP_LABELS = [
        'not_started' => 'Not Started',
        'in_progress' => 'In Progress',
        'configured' => 'Configured',
        'blocked' => 'Blocked',
    ];

    /**
     * @var array<string,string>
     */
    private const SETUP_DESCRIPTIONS = [
        'not_started' => 'Setup has not started yet.',
        'in_progress' => 'Setup is in progress.',
        'configured' => 'Setup is complete.',
        'blocked' => 'Setup is blocked and requires attention.',
    ];

    /**
     * @param  array<string,mixed>|null  $moduleState
     * @return array{
     *   module_key:string,
     *   label:string,
     *   ui_state:string,
     *   state_label:string,
     *   tone:string,
     *   has_access:bool,
     *   access_sources:array<int,string>,
     *   setup_status:string,
     *   setup_status_label:string,
     *   setup_description:string,
     *   coming_soon:bool,
     *   upgrade_prompt_eligible:bool,
     *   show_upgrade_prompt:bool,
     *   description:string
     * }
     */
    public static function present(?array $moduleState, ?string $fallbackLabel = null): array
    {
        $state = is_array($moduleState) ? $moduleState : [];
        $moduleKey = strtolower(trim((string) ($state['module_key'] ?? '')));
        $resolvedLabel = trim((string) ($state['label'] ?? $fallbackLabel ?? self::titleFromKey($moduleKey)));
        if ($resolvedLabel === '') {
            $resolvedLabel = 'Module';
        }

        $uiState = strtolower(trim((string) ($state['ui_state'] ?? 'locked')));
        if (! array_key_exists($uiState, self::STATE_META)) {
            $uiState = 'locked';
        }

        $setupStatus = strtolower(trim((string) ($state['setup_status'] ?? 'not_started')));
        if (! array_key_exists($setupStatus, self::SETUP_LABELS)) {
            $setupStatus = 'not_started';
        }

        $stateMeta = self::STATE_META[$uiState];
        $showUpgradePrompt = $uiState === 'locked' && (bool) ($state['upgrade_prompt_eligible'] ?? false);

        $description = match ($uiState) {
            'setup_needed' => self::SETUP_DESCRIPTIONS[$setupStatus] ?? self::STATE_META['setup_needed']['description'],
            default => $stateMeta['description'],
        };

        return [
            'module_key' => $moduleKey,
            'label' => $resolvedLabel,
            'ui_state' => $uiState,
            'state_label' => $stateMeta['label'],
            'tone' => $stateMeta['tone'],
            'has_access' => (bool) ($state['has_access'] ?? false),
            'access_sources' => array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                (array) ($state['access_sources'] ?? [])
            ))),
            'setup_status' => $setupStatus,
            'setup_status_label' => self::SETUP_LABELS[$setupStatus],
            'setup_description' => self::SETUP_DESCRIPTIONS[$setupStatus],
            'coming_soon' => (bool) ($state['coming_soon'] ?? false),
            'upgrade_prompt_eligible' => (bool) ($state['upgrade_prompt_eligible'] ?? false),
            'show_upgrade_prompt' => $showUpgradePrompt,
            'description' => $description,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $moduleStates
     * @param  array<int,string>  $preferredOrder
     * @return array{
     *   modules:array<int,array<string,mixed>>,
     *   setup:array<int,array<string,mixed>>,
     *   locked:array<int,array<string,mixed>>,
     *   coming_soon:array<int,array<string,mixed>>,
     *   active:array<int,array<string,mixed>>,
     *   counts:array{
     *     total:int,
     *     setup:int,
     *     locked:int,
     *     coming_soon:int,
     *     active:int
     *   },
     *   next_actions:array<int,string>
     * }
     */
    public static function checklist(array $moduleStates, array $preferredOrder = []): array
    {
        $ordered = self::orderedModuleStates($moduleStates, $preferredOrder);
        $presented = array_map(
            static fn (array $state): array => self::present($state),
            $ordered
        );

        $setup = array_values(array_filter($presented, static fn (array $state): bool => ($state['ui_state'] ?? '') === 'setup_needed'));
        $locked = array_values(array_filter($presented, static fn (array $state): bool => ($state['ui_state'] ?? '') === 'locked'));
        $comingSoon = array_values(array_filter($presented, static fn (array $state): bool => ($state['ui_state'] ?? '') === 'coming_soon'));
        $active = array_values(array_filter($presented, static fn (array $state): bool => ($state['ui_state'] ?? '') === 'active'));

        $nextActions = [];
        if ($setup !== []) {
            $nextActions[] = 'Finish setup for modules marked as Setup Needed.';
        }
        if (array_values(array_filter($locked, static fn (array $state): bool => (bool) ($state['upgrade_prompt_eligible'] ?? false))) !== []) {
            $nextActions[] = 'Review locked modules and trigger upgrade prompts where eligible.';
        }
        if ($comingSoon !== []) {
            $nextActions[] = 'Track coming-soon modules separately from live setup tasks.';
        }

        return [
            'modules' => $presented,
            'setup' => $setup,
            'locked' => $locked,
            'coming_soon' => $comingSoon,
            'active' => $active,
            'counts' => [
                'total' => count($presented),
                'setup' => count($setup),
                'locked' => count($locked),
                'coming_soon' => count($comingSoon),
                'active' => count($active),
            ],
            'next_actions' => $nextActions,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $moduleStates
     * @param  array<int,string>  $preferredOrder
     * @return array<int,array<string,mixed>>
     */
    private static function orderedModuleStates(array $moduleStates, array $preferredOrder): array
    {
        $normalized = [];
        foreach ($moduleStates as $key => $state) {
            if (! is_array($state)) {
                continue;
            }

            $moduleKey = strtolower(trim((string) ($state['module_key'] ?? $key)));
            if ($moduleKey === '') {
                continue;
            }

            $normalized[$moduleKey] = [
                ...$state,
                'module_key' => $moduleKey,
            ];
        }

        if ($normalized === []) {
            return [];
        }

        $ordered = [];
        foreach ($preferredOrder as $moduleKey) {
            $resolvedKey = strtolower(trim((string) $moduleKey));
            if ($resolvedKey === '' || ! array_key_exists($resolvedKey, $normalized)) {
                continue;
            }

            $ordered[$resolvedKey] = $normalized[$resolvedKey];
        }

        $remaining = array_diff_key($normalized, $ordered);
        if ($remaining !== []) {
            uasort($remaining, static function (array $left, array $right): int {
                return strcmp(
                    strtolower(trim((string) ($left['label'] ?? $left['module_key'] ?? ''))),
                    strtolower(trim((string) ($right['label'] ?? $right['module_key'] ?? '')))
                );
            });
        }

        return array_values($ordered + $remaining);
    }

    private static function titleFromKey(string $moduleKey): string
    {
        if ($moduleKey === '') {
            return 'Module';
        }

        $title = str_replace('_', ' ', $moduleKey);

        return ucwords($title);
    }
}

