<?php
declare(strict_types=1);

/*
 * Learning modules and their lesson ids. Keep in sync with the frontend's
 * src/data/modules.js — the API only accepts progress for ids listed here.
 */
const LEARNING_MODULES = [
    'savings' => ['why-save', 'set-goals', 'emergency-fund', 'pay-yourself-first', 'cut-costs', 'stay-on-track'],
    'budgeting' => ['what-is-a-budget', 'rule-50-30-20', 'track-spending', 'needs-vs-wants', 'realistic-goals'],
    'finlit' => ['foundations', 'spam', 'scam-mail', 'phishing', 'otp-fraud', 'card-fraud'],
    'debt' => ['good-vs-bad-debt', 'true-cost', 'loan-apps', 'credit-history', 'pay-it-off', 'before-you-borrow'],
    'investing' => ['why-invest', 'risk-return', 'options', 'diversify', 'ponzi', 'check-before'],
    'planning' => ['money-goals', 'net-worth', 'insurance', 'pension', 'family', 'review'],
];

function valid_module(string $module): bool
{
    return isset(LEARNING_MODULES[$module]);
}

function valid_lesson(string $module, string $lesson): bool
{
    return valid_module($module) && in_array($lesson, LEARNING_MODULES[$module], true);
}
