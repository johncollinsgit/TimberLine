<?php

return [
    'recurring' => [
        'account_id' => [
            'label' => 'Payment account',
            'type' => 'account',
            'required' => true,
        ],
        'merchant' => [
            'label' => 'Exact merchant name',
            'type' => 'text',
            'required' => true,
        ],
        'amount_cents' => [
            'label' => 'Amount (income positive, bills negative)',
            'type' => 'money',
            'required' => true,
        ],
        'next_due_on' => [
            'label' => 'Next due date',
            'type' => 'date',
            'required' => true,
        ],
        'cadence' => [
            'label' => 'Repeats',
            'type' => 'select',
            'required' => true,
            'choices' => [
                0 => 'once',
                1 => 'weekly',
                2 => 'biweekly',
                3 => 'monthly',
                4 => 'quarterly',
                5 => 'yearly',
            ],
        ],
        'category' => [
            'label' => 'Category',
            'type' => 'category',
            'required' => true,
        ],
        'expense_type' => [
            'label' => 'Expense type',
            'type' => 'select',
            'required' => true,
            'choices' => [
                0 => 'fixed',
                1 => 'essential_variable',
                2 => 'discretionary',
            ],
        ],
        'confirmed' => [
            'label' => 'Schedule confirmed',
            'type' => 'boolean',
            'required' => true,
        ],
        'use_seasonal_estimates' => [
            'label' => 'Estimate variable bills from a year of seasonal history',
            'type' => 'boolean',
            'required' => false,
        ],
    ],
    'goal' => [
        'target_cents' => [
            'label' => 'Target amount',
            'type' => 'money',
            'required' => true,
        ],
        'saved_cents' => [
            'label' => 'Already saved',
            'type' => 'money',
            'required' => true,
        ],
        'monthly_cents' => [
            'label' => 'Monthly contribution',
            'type' => 'money',
            'required' => true,
        ],
        'target_on' => [
            'label' => 'Target date',
            'type' => 'date',
            'required' => true,
        ],
        'priority' => [
            'label' => 'Priority',
            'type' => 'integer',
            'required' => true,
        ],
        'goal_type' => [
            'label' => 'Goal type',
            'type' => 'select',
            'required' => true,
            'choices' => [
                0 => 'savings',
                1 => 'emergency',
                2 => 'debt_paydown',
            ],
        ],
        'debt_record_id' => [
            'label' => 'Debt to pay down (for debt goals)',
            'type' => 'debt_record',
            'required' => false,
        ],
    ],
    'debt' => [
        'account_id' => [
            'label' => 'Linked debt account',
            'type' => 'account',
            'required' => false,
        ],
        'kind' => [
            'label' => 'Debt type',
            'type' => 'select',
            'required' => true,
            'choices' => [
                0 => 'credit',
                1 => 'mortgage',
                2 => 'loan',
            ],
        ],
        'balance_cents' => [
            'label' => 'Balance',
            'type' => 'money',
            'required' => true,
        ],
        'apr_bps' => [
            'label' => 'APR (%)',
            'type' => 'percent',
            'required' => true,
        ],
        'payment_cents' => [
            'label' => 'Monthly principal + interest payment',
            'type' => 'money',
            'required' => true,
        ],
        'escrow_cents' => [
            'label' => 'Monthly escrow',
            'type' => 'money',
            'required' => true,
        ],
        'fees_cents' => [
            'label' => 'Monthly fees',
            'type' => 'money',
            'required' => true,
        ],
        'next_due_on' => [
            'label' => 'Next payment',
            'type' => 'date',
            'required' => true,
        ],
        'observed_on' => [
            'label' => 'Balance date',
            'type' => 'date',
            'required' => true,
        ],
        'confirmed' => [
            'label' => 'Terms reviewed',
            'type' => 'boolean',
            'required' => true,
        ],
        'recurring_record_id' => [
            'label' => 'Replace an existing recurring bill',
            'type' => 'recurring_record',
            'required' => false,
        ],
    ],
    'asset' => [
        'value_cents' => [
            'label' => 'Current value',
            'type' => 'money',
            'required' => true,
        ],
        'asset_type' => [
            'label' => 'Asset type',
            'type' => 'select',
            'required' => true,
            'choices' => [
                0 => 'property',
                1 => 'vehicle',
                2 => 'investment',
                3 => 'business_equity',
                4 => 'other',
            ],
        ],
        'observed_on' => [
            'label' => 'Valuation date',
            'type' => 'date',
            'required' => true,
        ],
        'linked_business_space_id' => ['label' => 'Business equity: linked business space ID', 'type' => 'integer', 'required' => false],
    ],
    'metal' => [
        'metal' => [
            'label' => 'Metal',
            'type' => 'select',
            'required' => true,
            'choices' => [
                0 => 'gold',
                1 => 'silver',
            ],
        ],
        'quantity' => [
            'label' => 'Quantity',
            'type' => 'decimal',
            'required' => true,
        ],
        'weight' => [
            'label' => 'Weight per item',
            'type' => 'decimal',
            'required' => true,
        ],
        'unit' => [
            'label' => 'Weight unit',
            'type' => 'select',
            'required' => true,
            'choices' => [
                0 => 'gram',
                1 => 'troy_ounce',
            ],
        ],
        'purity_bps' => [
            'label' => 'Purity (%)',
            'type' => 'percent',
            'required' => true,
        ],
        'cost_basis_cents' => [
            'label' => 'Total purchase cost including fees',
            'type' => 'money',
            'required' => true,
        ],
        'acquired_on' => [
            'label' => 'Purchase date',
            'type' => 'date',
            'required' => true,
        ],
        'resale_adjustment_cents' => [
            'label' => 'Optional resale premium/discount',
            'type' => 'money',
            'required' => false,
        ],
        'transaction_id' => [
            'label' => 'Purchase transaction ID',
            'type' => 'integer',
            'required' => false,
        ],
    ],
    'scenario' => [
        'spending_reduction_bps' => [
            'label' => 'Reduce variable spending (%)',
            'type' => 'percent',
            'required' => true,
        ],
        'additional_monthly_income_cents' => [
            'label' => 'Additional monthly take-home income',
            'type' => 'money',
            'required' => true,
        ],
        'extra_debt_payment_cents' => [
            'label' => 'Extra monthly payment per debt',
            'type' => 'money',
            'required' => true,
        ],
        'shock_on' => [
            'label' => 'Unexpected expense date',
            'type' => 'date',
            'required' => false,
        ],
        'shock_cents' => [
            'label' => 'Unexpected expense amount',
            'type' => 'money',
            'required' => false,
        ],
        'reduction_category' => [
            'label' => 'Limit reduction to category (optional)',
            'type' => 'category',
            'required' => false,
        ],
    ],
    'payroll' => [
        'employee' => [
            'label' => 'Employee',
            'type' => 'text',
            'required' => true,
        ],
        'period_start' => [
            'label' => 'Period start',
            'type' => 'date',
            'required' => true,
        ],
        'period_end' => [
            'label' => 'Period end',
            'type' => 'date',
            'required' => true,
        ],
        'wages_cents' => [
            'label' => 'Regular wages',
            'type' => 'money',
            'required' => true,
        ],
        'overtime_cents' => [
            'label' => 'Overtime',
            'type' => 'money',
            'required' => true,
        ],
        'employer_taxes_cents' => [
            'label' => 'Employer taxes',
            'type' => 'money',
            'required' => true,
        ],
        'benefits_cents' => [
            'label' => 'Employer benefits',
            'type' => 'money',
            'required' => true,
        ],
        'contractor_cents' => [
            'label' => 'Contractor costs',
            'type' => 'money',
            'required' => true,
        ],
        'source_id' => [
            'label' => 'Payroll source row ID',
            'type' => 'text',
            'required' => true,
        ],
        'reviewed' => [
            'label' => 'Source reviewed',
            'type' => 'boolean',
            'required' => true,
        ],
    ],
    'reliance' => [
        'household_monthly_cents' => [
            'label' => 'Monthly household lifestyle spending',
            'type' => 'money',
            'required' => true,
        ],
        'goals_monthly_cents' => [
            'label' => 'Monthly household goals',
            'type' => 'money',
            'required' => true,
        ],
        'fixed_costs_cents' => [
            'label' => 'Monthly fixed business costs excluding owner pay',
            'type' => 'money',
            'required' => true,
        ],
        'variable_cost_bps' => [
            'label' => 'Variable costs / revenue (%)',
            'type' => 'percent',
            'required' => true,
        ],
        'reserve_cents' => [
            'label' => 'Monthly business reserves',
            'type' => 'money',
            'required' => true,
        ],
        'debt_service_cents' => [
            'label' => 'Monthly business debt service',
            'type' => 'money',
            'required' => true,
        ],
        'owner_gross_cents' => [
            'label' => 'Owner compensation including employer costs',
            'type' => 'money',
            'required' => true,
        ],
        'owner_net_cents' => [
            'label' => 'Household take-home from owner compensation',
            'type' => 'money',
            'required' => true,
        ],
        'distribution_retention_bps' => [
            'label' => 'Share of additional distributions reaching household (%)',
            'type' => 'percent',
            'required' => true,
        ],
        'reviewed' => [
            'label' => 'All assumptions reviewed',
            'type' => 'boolean',
            'required' => true,
        ],
    ],
];
